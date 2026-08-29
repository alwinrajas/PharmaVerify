# 19 — HHT Device Integration

The Android handheld at `phram-stock-scan-app` and PharmaVerify are two
separate applications that describe the same shelf. This document records where
they agree, where they do not, and what has been built here to receive the
device's work.

---

## 1. There is no live connection

The device has **no HTTP client** — no Retrofit, no OkHttp, no base URL. It is a
fully offline Room application whose only outbound path is an Excel export
written by `io/exporter/VerificationExporter.kt`.

PharmaVerify's `POST /api/hht/submissions` endpoint is real, tested and working,
but this device has never called it and cannot. The integration that exists in
the field is a **file hand-off**, so the interchange contract is the exported
workbook rather than the JSON payload the endpoint was designed around.

Both inbound paths are kept. A future device build may use the endpoint; until
then the workbook is the route, and `audits.source` records which one produced
any given audit.

---

## 2. Variance is computed differently on each side

> **This is the most important paragraph in this document.**

| System | Formula | Shortage is |
| --- | --- | --- |
| **PharmaVerify** | `System − (Physical + Loose)` | **positive** |
| Device | `Physical + LZ − System` | negative |

The two are exact negations of one another. A shelf five units short reads
`+5` in PharmaVerify and `−5` on the handheld.

**PharmaVerify's convention is the confirmed one**, agreed with the business on
2026-08-28 and matching the client's own `Variance_Report`, which reports
`System 5, Physical 4, Looze 0.5 → Variance 0.5`. PharmaVerify is **not** to be
changed to match the device.

### What this means in practice

- Staff using both tools see opposite signs for the same count. Until the device
  is updated, that has to be trained around.
- The device's exported `VARIANCE` column carries the device's sign. An importer
  must **recompute** from the three quantities and compare against `−VARIANCE`
  from the file; it must never store the file's figure directly.
- Fixing this properly means changing `Models.kt` and the four filter branches
  in `VerificationDao.kt` on the device side. That is a separate codebase and a
  separate decision, and it is outstanding.

---

## 3. LZ is Loose Quantity

The device labels the field **LZ Qty** and its source names the property
`expiredStock`. The client's own variance report names the same column
**"Looze Quantity"**, with a derived column headed
`ConvertedQty(Physical Qty + Lz Qty)`.

The business confirmed on 2026-08-28 that it means **Loose Quantity** — stock
counted outside a full pack. PharmaVerify stores it as `loose_qty` and describes
it that way everywhere. **It is not expired stock**, carries no expiry
semantics, and has no write-off path.

The arithmetic is the same under either reading, so only the naming and the
reporting depended on the answer.

---

## 4. Reference numbering

The device numbers each cycle `AUD-ddMMyyyy-NNNN` for an audit and
`STK-ddMMyyyy-NNNN` for a stock take, with the sequence monotonic **per shop per
workflow** and never reset. Withdrawing a cycle is a soft delete, so a consumed
number is never reissued.

PharmaVerify now records both formats:

| | PharmaVerify | Device |
| --- | --- | --- |
| Audit identity | shop + **device** + audit number | shop + reference |
| Audit reference | `audits.audit_ref` | `AUD-ddMMyyyy-NNNN` |
| Stock take | `stock_take_sessions.take_ref` | `STK-ddMMyyyy-NNNN` |

`audit_number` remains the identity here — it is in the unique index, the HHT
JSON contract and the `audit-number` report. `audit_ref` sits beside it for
display and for matching an imported file.

### Why the audit reference is indexed but not unique

PharmaVerify partitions audit numbering by **device**; the handheld partitions by
**shop only**. Four devices counting one shop on one day therefore all hold
audit number 1, and all derive `AUD-<that day>-0001`. That is valid data, not a
clash — five such groups already exist in the development database — so a unique
constraint would reject records the application itself created.

Uniqueness for device-sourced references is enforced in application code at
import time, where the shop and the reference arrive together and a clash can be
reported in words. `Audit::refTakenAtShop()` is that check.

`stock_take_sessions` **does** carry `unique(shop_id, take_ref)`: the table
starts empty and stock-take numbering has no device dimension.

### Audits that predate the format

`php artisan audits:backfill-references` derives
`AUD-<audit_date ddMMyyyy>-<audit_number padded to 4>` for any audit without a
reference. It is idempotent and supports `--dry-run`.

The backfill is display enrichment only. `Audit::reference()` derives the same
string on the fly, so an audit with a null `audit_ref` still renders and still
exports. That fallback is **permanent**, not a stopgap: an audit created through
the JSON endpoint has no device reference and never will.

---

## 5. Stock take cycles

A stock take was previously a loose row per counted line with nothing tying one
sweep together. `stock_take_sessions` gives the sweep a row of its own, with the
reference, the shop, the date, a tally and a soft delete.

`stock_takes.stock_take_session_id` is **nullable and stays nullable**. Every
take recorded before cycles existed was raised by hand with no sweep around it,
and a null session is the truth about those rows rather than a gap to fill.
Grouping them into invented sessions would consume references the handheld may
go on to issue.

Numbering a web-raised cycle uses `StockTakeSession::nextNumberFor()`, which
takes the highest number the shop has ever issued — **including withdrawn
cycles** — and adds one.

---

## 6. The export layouts

Confirmed from `VerificationExporter.kt`, where the source states that column
order is part of the interchange contract. Both are single-sheet.

**Audit export**

`INVENTLOCATIONID, AUDITNUMBER, ITEMBARCODE, ITEMID, ITEMNAME, INVENTBATCHID,`
`EXPDATE, SYSTEMQTY, PHYSICALQTY, LZQTY, VARIANCE, VERIFIEDBY, VERIFIEDDATE, STATUS`

**Stock Take export**

`INVENTLOCATIONID, STOCKTAKENUMBER, ITEMBARCODE, ITEMID, ITEMNAME, INVENTBATCHID,`
`EXPDATE, PHYSICALQTY, LZQTY, COUNTEDBY, COUNTEDDATE, STATUS`

`SYSTEMQTY` and `VARIANCE` are absent from the take rather than blank. The
exporter's own comment explains why: a take is a blind physical count, and "an
empty column invites the receiving side to fill it in from somewhere else." Any
importer must honour that and never derive a system quantity for a take.

---

## 7. Device identity is not in the export

The audit export carries the shop, the reference, the product, the batch, the
quantities and the operator's name — but **no device identifier**. PharmaVerify's
audit identity needs one.

The agreed approach is that the **operator selects the device when uploading**.
That preserves shop + device + audit number as the identity rather than weakening
it. The upload screen belongs to a later phase; the columns that receive it
(`audit_ref`, `source`) are in place.

---

## 8. The importer

`POST /api/hht/imports/preview` reads and judges a workbook without writing
anything; `POST /api/hht/imports` commits it in one transaction. The kind is
decided from the file's own headings, never from what the caller says, so an
audit cannot be filed as a take by mistake.

### What is believed, and what is not

| From the file | Treatment |
| --- | --- |
| `PHYSICALQTY`, `LZQTY` | Stored as given. Loose is never folded into physical. |
| `INVENTBATCHID`, `EXPDATE` | Used to narrow the stock match, then stored. |
| `SYSTEMQTY` | Kept as `audit_lines.source_system_qty`. **PharmaVerify's own `item_stocks.system_qty` is authoritative** for the line — the handheld works from its own copy of the ERP data, taken at its own moment. Disagreements are counted and reported. |
| `VARIANCE` | **Not stored.** Recomputed from the quantities. The file's figure is compared against the negation of ours, and a mismatch is reported. |
| `INVENTLOCATIONID` | Matched to a shop. Never creates one; unmatched codes are reported and their rows dropped, never reassigned. |

### Identifying a product

GTIN first, then the ERP item code, then the 7-digit internal barcode. The
barcode is a controlled fallback reached only when the first two find nothing,
so it can never displace the GTIN. The preview reports how many rows resolved
on each, so coverage is visible rather than assumed.

A row matching nothing is kept as an unknown item, never dropped — an
unrecognised product is what a stock take exists to surface.

### Identity and conflicts

An audit is identified by **shop + device + audit number**, and the reference's
numeric tail becomes that number. Two checks run before anything is written:

- the same reference already recorded at that shop, and
- the same shop, device and number already recorded — most likely from the JSON
  endpoint.

Either is refused with a sentence naming what it clashed with, rather than
surfacing a database constraint error.

### Idempotency

A fingerprint is taken over the shop, the reference and the canonicalised rows —
sorted, so re-exporting the same count in a different row order is recognised as
the same file. Operator name and timestamps are excluded: they do not make it a
different count.

- **Same file again** ⇒ `duplicate_ignored`. Nothing written, the existing audit
  returned.
- **Same reference, different contents** ⇒ refused. Replacing a recorded count
  is a separate decision.

Audits use the `hht_submissions` ledger the JSON endpoint already had. Stock
takes have no such ledger, so the fingerprint lives on
`stock_take_sessions.payload_hash`.

### Transaction safety

Validate, stage, then one transaction. A failure at any point leaves no audit,
no lines and no ledger row — proven by a test that forces a database failure
partway through the line insert.

---

## 9. Stock take imports

A take is a **blind** count. No system quantity is looked up and no variance is
computed, because the export deliberately omits both. The cycle is created
complete, with its reference, its date and the operator's name from the file.
