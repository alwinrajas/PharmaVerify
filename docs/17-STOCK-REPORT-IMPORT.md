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
| `LOWERQTY` | **System quantity** — see the pending decision in §6 | yes |
| `HIGHERQTY` | **Whole quantity**, stored as supplied | no |
| `TOTALCOST` | ERP cost total, stored exactly as supplied | yes |
| `COSTPERINVUNIT` | Read, not stored as the line price | no |

There is no column literally named `QTY`. The report offers `LOWERQTY` and
`HIGHERQTY`, which is why the quantity mapping is the one decision still open —
see §6.

### `all batches` — the barcodes

`ITEMID`, `INVENTBATCHID`, `EXPDATE`, `ITEMBARCODE`. Joined on item **and**
batch. `ITEMBARCODE` is a 7-digit internal code and is **not** the GTIN.

### `Item Master` — the products

| Column | Used as | Required |
| --- | --- | --- |
| `ITEMID` | Product code | yes |
| `ITEMNAME` | Description | yes |
| `SALESPRICE` | **Retail selling price** — the only source of price | yes |
| `FACTOR` | Loose units per whole pack | yes |
| `GLOBALTRADEITEMNUMBER` | **GTIN — the scan identifier** | yes |
| `INVUNIT` | Unit of measure | no |

The remaining columns are ignored.

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

### The item master is *not* rewritten

A Stock Report **creates products it introduces** — a stock row needs a product
to point at — but leaves every product already on file exactly as it was.

> Confirmed with the business on 2026-08-28: Item Import and Stock Import are
> two separate operations. Item Import (`POST /api/items/import`) maintains the
> product list; Stock Import loads quantities. This supersedes the 2026-08-26
> position that a stock import may refresh product details. Stock Take is
> unaffected and still never creates a product — see `01-BRD.md` BR-09.

### Checked before it replaces

`POST /api/stock-imports/preview` reads and validates the file and reports what
it would do — rows, locations, matched and unmatched shops, and for each shop
what it holds now against what would take its place — **without writing
anything**. The screen requires this check before the replacement can be
started, and names the affected shops in the confirmation.

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
| **PENDING — `LOWERQTY` is the system quantity** | Not yet confirmed. The business answer is in an audio recording that has not been transcribed, so the mapping is unchanged from before. When it is settled, the single line to change is `system_qty` in `StockReportImportService::readStockSheet()`, plus `system_qty` on `item_stocks`. |
| `SALESPRICE` is the price | Confirmed 2026-08-28. It is the retail selling price, and the only source of one — the earlier fallback to `COSTPRICE` and `COSTPERINVUNIT` is gone. |
| `GLOBALTRADEITEMNUMBER` is the scan identifier | Confirmed 2026-08-28. It is stored on `items.gtin` and `item_stocks.gtin`. The 7-digit `ITEMBARCODE` is a **different identifier system**, keeps its own `barcode` column, and never displaces the GTIN. |
| Whole quantity may be fractional | Confirmed 2026-08-28. `HIGHERQTY = LOWERQTY / FACTOR`; 387 of 8,913 rows in the reference file are fractional (0.04, 0.24, 0.33, 1.38 …). It is never rounded to a whole number. Where the column is absent it is derived from `FACTOR`. |
| `TOTALCOST` is stored, never derived | Confirmed 2026-08-28. The ERP total does not reconcile with quantity × unit cost on 140 rows, so the supplied value is authoritative. |
| A shared GTIN is reported, not resolved | A GTIN two products answer to cannot identify one stock line. The import reports it and carries on rather than inventing a rule for picking a winner. |
| Keys are trimmed before joining | Raw keys carry stray whitespace: joining without trimming yields **no** barcodes at all, silently. |
| `EXPDATE` is read as an Excel serial | Reading without styling means the cell arrives as a plain number. Values outside roughly 1970–2150 are treated as bad data. |
| Rejections are recorded against the first shop's import | A rejected row often has no usable shop, so it cannot be filed under one. |

---

## 7. The older flat file still works

A single-sheet file with `Product Code`, `Product Description` and
`System Stock` is still accepted for one shop, and a shop must be chosen for it.
The importer decides which path to take by looking at the sheet names, so both
file shapes work without a setting.

---

## 8. GTIN coverage in the reference file

The business has confirmed the GTIN is the identifier the handheld scans. In the
supplied report it is largely absent:

| Measure | Count | Share |
| --- | --- | --- |
| Products in `Item Master` | 40,360 | — |
| …carrying a `GLOBALTRADEITEMNUMBER` | 1,235 | **3.1%** |
| Stock rows | 8,913 | — |
| …whose product has a GTIN | 1,700 | **19.1%** |
| …whose product has **no** GTIN | 7,213 | **80.9%** |
| GTINs shared by more than one product | 0 | — |

Four stock lines in five therefore cannot be found by GTIN today. This is a data
question for the business, not something the application can decide, so:

- the GTIN is the **primary** lookup wherever a code is resolved;
- the 7-digit `ITEMBARCODE` is consulted **only when the GTIN finds nothing**,
  which is what keeps the other 80.9% scannable in the meantime;
- coverage is reported on every import and in the pre-import check, so the gap
  stays visible rather than turning into unexplained "not found" scans.

If the business intends the GTIN to be the sole identifier, the item master
needs `GLOBALTRADEITEMNUMBER` populated before that can be switched on.

---

## 9. Item Import

`POST /api/items/import` — the item master, on its own.

Accepts the full Stock Report (only its `Item Master` sheet is read) or a single
sheet with the same headings. Products are **created and updated**; none are
ever deleted. `update_existing=0` limits it to products not already on file.

It never touches stock quantities. That separation is the point: a stock
snapshot arriving on a Tuesday must not quietly rewrite the descriptions and
prices of the whole catalogue.
