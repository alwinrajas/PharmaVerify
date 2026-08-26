# 17 — Stock Report Import

The **Stock Report** (`Stock report.xlsx`) is the official business file for
loading system stock. It is produced by the source ERP (Microsoft Dynamics AX)
and covers every branch it was run for.

---

## 1. The file

Three sheets, all required:

| Sheet | Purpose | Scale in the reference file |
| --- | --- | --- |
| `stock` | Quantities per shop, item and batch | 8,913 rows × 9 columns |
| `all batches` | Item + batch → barcode | 103,200 rows × 4 columns |
| `Item Master` | Product description, unit, prices | 40,360 rows × 15 columns |

About 152,500 rows in 6.6 MB. Production files are expected to grow.

### `stock` — the quantities

| Column | Used as | Required |
| --- | --- | --- |
| `INVENTLOCATIONID` | Warehouse code identifying the shop | yes |
| `ITEMID` | Product code | yes |
| `INVENTBATCHID` | Batch | yes |
| `EXPDATE` | Expiry, as an Excel day serial | no |
| `LOWERQTY` | **System quantity** | yes |
| `HIGHERQTY` | Read but not stored — see §6 | no |
| `COSTPERINVUNIT` | Fallback price when the item master has none | no |

### `all batches` — the barcodes

`ITEMID`, `INVENTBATCHID`, `EXPDATE`, `ITEMBARCODE`. Joined on item **and**
batch.

### `Item Master` — the products

`ITEMID`, `ITEMNAME`, `INVUNIT`, `SALESPRICE` are used; `GLOBALTRADEITEMNUMBER`
is a barcode fallback. The remaining columns are ignored.

---

## 2. Shops are matched by warehouse code

The report identifies a branch by `INVENTLOCATIONID` (for example `P001`), which
is not the shop code used here. Each shop therefore carries an **AX location**
(`shops.ax_location_id`).

```
INVENTLOCATIONID  P001  ─→  shop PHM001
INVENTLOCATIONID  T033  ─→  shop PHM002
```

**Set this on every shop before importing.** A row whose warehouse code matches
no shop is rejected with a message naming the code, and the rest of the file
still imports.

One upload updates **every shop the report names**. The shop selector on the
import screen is optional and acts as a filter — choose a shop to import only
that branch.

---

## 3. What happens during an import

```
1  Validate the workbook          sheet names, then required columns per sheet
2  Read `stock` in chunks         validate each row; note which items and
                                  batches are actually referenced
3  Stream `all batches`           keep only the referenced barcodes
4  Stream `Item Master`           keep only the referenced products
5  One transaction:
     • sync the item master       upsert, never delete
     • per shop in the file:      delete its stock, insert the new rows
     • record the outcome         one stock_imports row per shop
```

Nothing touches the database until the whole file has been read and judged.

### Replace, never append

Importing **replaces** the stock of each shop the report covers. Shops the
report does not mention keep everything they had. Importing the same file twice
leaves the same number of rows — a repeat replaces rather than duplicates.

### The item master is synced

A Stock Report **creates products it introduces and refreshes the details of
products already known** — description, unit, price and barcode. Nothing is ever
removed.

> This is a deliberate change of rule, confirmed with the business on
> 2026-08-26. It replaces the earlier position that a stock import must not
> alter the item master. Stock Take is unaffected and still never creates a
> product — see `01-BRD.md` BR-09.

---

## 4. Validation

**Workbook** — the three sheets must be present, and each must carry its
required columns. A file failing either check is refused before anything is
read, and no stock changes.

**Rows** — each is checked independently, and a bad row never stops the others:

| Rejected when | Message |
| --- | --- |
| Warehouse code missing | The warehouse code is missing, so the row cannot be assigned to a shop. |
| Warehouse code unknown | No shop is linked to warehouse code `X`. Set the AX location on the shop, then import again. |
| Item code missing | The item code is required. |
| Quantity not a number | The quantity must be a number. |
| Quantity negative | The quantity cannot be negative. |
| Expiry unreadable | The expiry date could not be read. |
| Repeat of shop + item + batch | Duplicate of row N — the same shop, item and batch appears twice in the report. |

Every rejection records the row number, column, offending value and reason, and
is readable from the import history.

---

## 5. Performance

Measured against the reference file on the development machine:

| | Time | Peak memory |
| --- | --- | --- |
| Reading and validating all three sheets | ~41 s | ~180 MB |
| Full import including the database work | ~71 s | ~270 MB |
| Same over HTTP end to end | ~107 s | — |

Three things keep it within a default 512 MB limit:

1. **Only the sheet in hand is loaded** (`setLoadSheetsOnly`).
2. **Only the columns used are parsed** (a column read filter) — `Item Master`
   has fifteen columns and the import needs four.
3. **Styling is skipped** (`setReadDataOnly`), which is most of the weight.

The two large sheets are walked **25,000 rows at a time**, so peak memory
follows the chunk size rather than the size of the file. Only the keys the stock
sheet actually referenced are retained, so the 143,000 lookup rows never all
exist in memory at once.

Database work is bulk throughout: one `upsert` per 1,000 products, one `insert`
per 1,000 stock rows, and a single lookup of item ids for the whole file. There
is no per-row query.

### Limits

`upload_max_filesize` and `post_max_size` must be at least 100 MB, and the web
server's read timeout at least 600 seconds — see `12-DEPLOYMENT-GUIDE.md`. The
endpoint lifts its own execution limit for the duration.

If reports grow far beyond the current size, the next step is to move the import
to a queued job and poll for its progress. The reading and writing are already
chunked, so that change is contained to how the work is started.

---

## 6. Decisions recorded

| Decision | Reason |
| --- | --- |
| `LOWERQTY` is the system quantity | Confirmed with the business. `HIGHERQTY` differs on about a quarter of rows and is not stored. |
| `SALESPRICE` from `Item Master` is the price | Matches how price is used elsewhere in the application. `COSTPERINVUNIT` from the stock row is a fallback. |
| Barcode comes from `all batches`, then `GLOBALTRADEITEMNUMBER` | The batch barcode is the more specific of the two. |
| Keys are trimmed before joining | Raw keys carry stray whitespace: joining without trimming yields **no** barcodes at all, silently. |
| `EXPDATE` is read as an Excel serial | Reading without styling means the cell arrives as a plain number. Values outside roughly 1970–2150 are treated as bad data. |
| Rejections are recorded against the first shop's import | A rejected row often has no usable shop, so it cannot be filed under one. |

---

## 7. The older flat file still works

A single-sheet file with `Product Code`, `Product Description` and
`System Stock` is still accepted for one shop, and a shop must be chosen for it.
The importer decides which path to take by looking at the sheet names, so both
file shapes work without a setting.
