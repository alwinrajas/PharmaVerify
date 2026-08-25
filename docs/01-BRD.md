# 01 — Business Requirements Document (BRD)

**Application:** PharmaVerify — Pharmacy Stock Verification Web Application
**Version:** 0.1.0
**Date:** 2026-08-25

---

## 1. Project Overview

PharmaVerify is a browser-based application used by a pharmacy business to **verify physical stock against system stock** after a physical stock count has been performed on Android HHT (Hand Held Terminal) devices.

The application is **not** a general inventory management system. It does not handle purchase, sales, suppliers, procurement, purchase orders, warehouses, goods receipt or stock transfers.

## 2. Objectives

1. Hold the authoritative **system stock** per shop, loaded from an Excel stock file.
2. Receive **completed physical stock counts** from HHT devices in a single final submission.
3. Compare system stock against physical stock and expose the **variance**.
4. Allow authorised users to **verify** the count and **adjust** the system stock immediately.
5. Capture **stock take** entries for physical stock that is not present in the stock file.
6. Produce **reports** and a **final output file** that can be shared to OneDrive on demand.

## 3. Application Users

| User | Description |
| --- | --- |
| Administrator | Full access. Manages shops, items, devices, users, roles and settings. |
| Supervisor / Authorised User | Operational access. Imports stock, verifies audits, adjusts stock, runs reports. |
| Shop User / Shop Keeper | Restricted to assigned shops. Views stock and audits, records stock takes, runs shop reports. |

## 4. Business Workflow

```
Master Data (Shops, Items, Devices)
        ↓
Item Stock Import (Excel)  →  System Stock
        ↓
HHT Physical Stock Scan (offline, on device)
        ↓
Final HHT Share / Submit  →  Web HHT Submission
        ↓
Stock Audit  →  Stock Verification
        ↓
Variance (Physical − System)
        ↓
Stock Adjustment  /  Stock Take
        ↓
Reports  →  Final Output  →  Share to OneDrive
```

## 5. Modules

Master (Shops, Items, Devices, Item Stock Import), Item Stock, HHT Submission, Stock Audit,
Stock Verification, Variance, Stock Adjustment, Stock Take, Reports, Final Output / OneDrive,
User Management, Settings, Dashboard.

## 6. Business Rules

| # | Rule |
| --- | --- |
| BR-01 | Importing a stock file for a shop **replaces** that shop's existing stock. It is never appended. The replacement is atomic — a failed import leaves the previous stock intact. |
| BR-02 | The same product may exist in several shops. Stock identity is **Shop + Product + Batch**, never barcode alone. |
| BR-03 | The HHT submission identity is **Shop ID + Device ID + Audit Number**. Audit Number alone is not unique — several devices in a shop can share an audit number, and each device advances its own audit sequence. |
| BR-04 | The HHT holds all scans in local device storage. There is **no continuous synchronisation** — only one final submission after the count is complete. |
| BR-05 | Re-sending the same submission (network retry) must **not** create duplicate audit data. |
| BR-06 | `Variance = Physical Quantity − System Quantity`. |
| BR-07 | Stock adjustment is posted **immediately** on save. There is **no approval workflow** and no supervisor approval step. |
| BR-08 | Adjustment history is retained for every posted adjustment. |
| BR-09 | Stock Take records physical stock that is absent from the stock file. It **must not** automatically create an Item Master record. |
| BR-10 | Completed audits may be edited, but only by users holding the relevant permission, and every edit is written to the audit trail (user, date/time, record, field, old value, new value, action). |
| BR-11 | The final output is uploaded to OneDrive **only** when the user explicitly clicks **Share to OneDrive**. Nothing uploads automatically. |
| BR-12 | Authorisation is enforced on the backend. Frontend visibility rules are a convenience only. |

## 7. Data Requirements

Shops, Items, Devices, Item Stock (with batch, expiry, shelf location, price, UOM), Stock Imports and
their row-level errors, HHT Submissions, Audits, Audit Lines, Stock Adjustments, Stock Takes,
Final Outputs, Users / Roles / Permissions, and an activity log.

## 8. HHT Flow

```
HHT Login → Load required data → Scan products → Enter / verify physical quantity
          → Complete stock scan → Final Share / Submit → REST API → Audit + Audit Lines
```

See `08-HHT-INTEGRATION.md`.

## 9. Stock Verification

The verification screen presents system quantity beside physical quantity per audit line, with the
computed variance, batch, expiry and status. Authorised users may correct permitted fields; every
change is logged.

## 10. Variance

Variance is derived from the audit line (`physical_qty − system_qty`) and is filterable by shop, audit,
product, barcode, batch, date and by positive / negative / zero variance.

## 11. Adjustment

An authorised user posts the adjustment against a variance line. The shop's system stock is updated in
the same transaction and an adjustment history record is written. No approval step exists.

## 12. Stock Take

Captures shop, barcode, product, description, physical quantity, batch, expiry, shelf location, date and
user for stock found on the shelf but absent from the stock file. Kept separate from Item Master.

## 13. Reports

Variance, Audit No. Based, Individual Shop Based, Overall Stock, User Log, Stock Adjustment, Detailed,
Variance Summary and Stock Occurrence. Each supports search, filters, sorting, Excel and PDF export.
See `10-REPORT-SPECIFICATION.md`.

## 14. OneDrive

The final output file is generated in the application and uploaded to OneDrive through the Microsoft
Graph API on explicit user action, with progress, success, failure and retry states.
See `09-ONEDRIVE-INTEGRATION.md`.

## 15. Non-Functional Requirements

| Area | Requirement |
| --- | --- |
| Security | Token authentication, permission-based authorisation enforced server-side, hashed passwords, validated input, secure file upload, audit logging. |
| Usability | Premium enterprise UI, consistent layout, clear empty / loading / error states, understandable error messages. |
| Reliability | Database transactions for stock import replacement, HHT submission and stock adjustment. |
| Maintainability | Controller → Request → Service → Model on the backend; feature-organised, component-reused frontend. |
| Portability | Target database is Microsoft SQL Server; the data layer avoids engine-specific SQL. See `15-ASSUMPTIONS-DEPENDENCIES.md`. |
