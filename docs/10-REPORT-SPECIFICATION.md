# 10 — Report Specification

All nine reports run through one engine. Each is described once in
`backend/app/Services/Reports/ReportRegistry.php` — its query, its columns and
the filters it accepts — and that single description drives the on-screen table,
the Excel export and the PDF export. A column added to a report appears in all
three without further work.

**Access:** `reports.view` to run, `reports.export` to download.
**Scoping:** every report is restricted to the shops the user may see.
**Export cap:** 20,000 rows; the file states when it was truncated.

Common filters: `search`, `shop_id`, `device_id`, `audit_number`, `audit_id`,
`variance`, `date_from`, `date_to`, `expiry_from`, `expiry_to` — each report
declares which of these it understands, and the screen renders only those.

Sorting: any column, either direction. Pagination: 10 / 25 / 50 / 100.

---

## 1. Variance Report — `variance`

**Purpose:** Every counted line where the physical quantity differs from the
system quantity.

**Filters:** shop, audit, audit number, device, variance direction, date range, search

| Column | Type |
| --- | --- |
| Shop | text |
| Audit No. | number |
| Device | text |
| Product Code | text |
| Barcode | text |
| Product Description | text |
| Batch | text |
| Expiry | date |
| UOM | text |
| System Qty | decimal |
| Physical Qty | decimal |
| Variance | decimal |
| Adjustment | text |

**Default sort:** Variance ascending — the largest shortages first.
**Summary:** lines, net variance, short lines, excess lines.
**Excel / PDF:** yes.

---

## 2. Audit No. Based Report — `audit-number`

**Purpose:** Verification detail for an audit number. Shop and device are always
shown because the same audit number exists on several devices.

**Filters:** shop, device, audit number, audit, variance direction, search

| Column | Type |
| --- | --- |
| Shop | text |
| Device | text |
| Audit No. | number |
| Product Code | text |
| Product Description | text |
| Batch | text |
| System Qty | decimal |
| Physical Qty | decimal |
| Variance | decimal |
| Verification | text |
| Adjustment | text |

**Default sort:** Product code ascending.
**Summary:** lines, lines with variance, net variance.
**Excel / PDF:** yes.

---

## 3. Individual Shop Based Report — `shop-stock`

**Purpose:** Current system stock for one selected shop.

**Filters:** shop, verification status, expiry range, search

| Column | Type |
| --- | --- |
| Shop | text |
| Product Code | text |
| Barcode | text |
| Product Description | text |
| Batch | text |
| Expiry | date |
| UOM | text |
| System Qty | decimal |
| Price | money |
| Stock Value | money (quantity × price) |
| Shelf | text |

**Default sort:** Product code ascending.
**Summary:** records, total quantity.
**Excel / PDF:** yes.

---

## 4. Overall Stock Report — `overall-stock`

**Purpose:** Consolidated system stock across every shop.

**Filters:** shop, expiry range, search

| Column | Type |
| --- | --- |
| Shop | text |
| Shop Name | text |
| Product Code | text |
| Product Description | text |
| Batch | text |
| Expiry | date |
| System Qty | decimal |
| Price | money |
| Stock Value | money |

**Default sort:** Shop ascending.
**Summary:** records, total quantity.
**Excel / PDF:** yes.

---

## 5. User Log Report — `user-log`

**Purpose:** User activity and the significant actions the system recorded.

**Filters:** user, module, date range, search

| Column | Type |
| --- | --- |
| Date / Time | datetime |
| User | text |
| Module | text |
| Action | text |
| Record | text |
| Detail | text (old and new values where a field changed) |

**Default sort:** Date descending.
**Excel / PDF:** yes.

---

## 6. Stock Adjustment Report — `adjustment`

**Purpose:** Adjustments already posted, with the quantity before and after.

**Filters:** shop, audit, adjusted by, date range, search

| Column | Type |
| --- | --- |
| Adjusted On | datetime |
| Shop | text |
| Audit No. | number |
| Device | text |
| Product Code | text |
| Product Description | text |
| Batch | text |
| Previous Qty | decimal |
| Physical Qty | decimal |
| Variance | decimal |
| New Qty | decimal |
| Adjusted By | text |
| Reason | text |

**Default sort:** Adjusted on, descending.
**Summary:** adjustments, net variance.
**Excel / PDF:** yes.

---

## 7. Detailed Report — `detailed`

**Purpose:** Every counted line with its full stock, audit and verification
context — the evidence report.

**Filters:** shop, device, audit, audit number, variance direction, date range, search

| Column | Type |
| --- | --- |
| Shop · Device · Audit No. · Audit Date | text / number / date |
| Product Code · Barcode · Product Description | text |
| Batch · Expiry · Shelf · UOM | text / date |
| Price | money |
| System Qty · Physical Qty · Variance | decimal |
| Verification · Adjustment | text |

**Default sort:** Line order.
**Excel / PDF:** yes (landscape).

---

## 8. Variance Summary Report — `variance-summary`

**Purpose:** Variance totalled per audit, so a count can be judged at a glance.

**Filters:** shop, device, audit number, date range

| Column | Type |
| --- | --- |
| Shop | text |
| Device | text |
| Audit No. | number |
| Audit Date | date |
| Items Counted | number |
| Excess Lines | number |
| Short Lines | number |
| Matched Lines | number |
| Net Variance | decimal |
| Status | text |

**Default sort:** Audit date descending.
**Excel / PDF:** yes.

---

## 9. Stock Occurrence Report — `stock-occurrence`

**Purpose:** How often a product has been counted, and where, across shops and
audits.

**Filters:** shop, date range, search

| Column | Type |
| --- | --- |
| Product Code | text |
| Product Description | text |
| Shops | number (distinct shops) |
| Audits | number (distinct audits) |
| Occurrences | number (counted lines) |
| System Qty | decimal (total) |
| Physical Qty | decimal (total) |
| Net Variance | decimal |

**Default sort:** Occurrences descending.
**Excel / PDF:** yes.

---

## Export formats

### Excel (`.xlsx`, PhpSpreadsheet)

Title block with the report name, company, generated timestamp, generated by and
the active filters. A styled header row, auto-filter, frozen header, numeric
columns right-aligned, and the summary figures beneath the data.

### PDF (dompdf)

A masthead with the company, report title, description, generation details,
record count and active filters. Landscape above eight columns. Variance is
coloured — short in red, excess in green. Page numbers in the footer. A notice
appears when the export was capped.

---

## Adding a report

1. Add a private method to `ReportRegistry` returning a `ReportDefinition`
   (key, title, description, permission, columns, filters, query, optional
   summary, default sort).
2. Register it in `ReportRegistry::all()`.

The screen, the filters, the Excel export and the PDF export follow from that
definition. Nothing in the frontend needs to change.
