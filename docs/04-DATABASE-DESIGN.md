# 04 — Database Design

Target engine: **Microsoft SQL Server**. The data layer uses Eloquent migrations
and the query builder only, with no engine-specific SQL, so one schema serves
every engine.

**Verified on SQL Server 2022 on 2026-08-26**: migrations, the full demonstration
seed and all 52 core feature tests run against a real instance, and produce the
same data as MySQL. The suite also runs on MySQL and SQLite.

**Re-run on 2026-08-26 with the Stock Report module applied: all 60 pass on
SQL Server, MySQL and SQLite.**

One compatibility defect was found and fixed on the way: `shops.ax_location_id`
carried a plain unique index on a nullable column. SQL Server and PostgreSQL
treat NULLs as equal in a unique index and permit only one, so a second shop
without a warehouse code was rejected; MySQL and SQLite permit many. The column
now takes a **filtered unique index** (`WHERE ax_location_id IS NOT NULL`) on
the engines that need one, and a plain unique index on those that do not. This
is the same NULL-uniqueness hazard recorded as A-05 in
`15-ASSUMPTIONS-DEPENDENCIES.md` for `item_stocks.batch`.

A generated SQL Server script is kept at `database/sql/schema-sqlserver.sql`
and can be regenerated with `php artisan pharmaverify:sqlsrv-schema`.

---

## 1. Relationships

```
shops ─┬─► devices ────────────┐
       │                       │
       ├─► item_stocks ◄───────┼──── stock_imports ──► stock_import_errors
       │        ▲              │
       │        │              ▼
       ├─► audits ◄────────── hht_submissions
       │      │
       │      ▼
       │   audit_lines ──┬──► stock_adjustments
       │        ▲        └──► stock_takes
       │        │
       └─► final_outputs

users ─┬─► shop_user ──► shops        (which shops a user may see)
       ├─► roles / permissions        (spatie)
       └─► activity_log               (who did what)
```

**Submission identity.** An HHT submission is identified by
`Shop ID + Device ID + Audit Number`. The audit number alone is *not* unique:
several devices in one shop routinely carry the same number, and each device
advances its own sequence. This is enforced by
`audits.audit_identity_unique (shop_id, device_id, audit_number)`.

**Consolidations.** Two entities named in the requirement do not have their own
table, because doing so would add joins without adding information:

| Requirement entity | Where it lives | Why |
| --- | --- | --- |
| Verification record | `audit_lines.verification_status`, `verified_by`, `verified_at` | A verification record would carry no field the line does not already hold. |
| Variance | `audit_lines.variance_qty` | Variance is `physical_qty − system_qty`. It is stored on the line so reports do not recompute it per row, and it is always recomputed by the server rather than accepted from a request. |

---

## 2. Tables

### `users`

Application users.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| id | bigint identity | no | PK |
| name | nvarchar(150) | no | |
| email | nvarchar(150) | no | **unique** |
| email_verified_at | datetime | yes | |
| password | nvarchar(191) | no | bcrypt hash |
| employee_code | nvarchar(50) | yes | |
| phone | nvarchar(30) | yes | |
| status | nvarchar(20) | no | `active` / `inactive`, default `active`, **indexed** |
| last_login_at | datetime | yes | Set on each successful sign-in |
| remember_token | nvarchar(100) | yes | |
| created_at / updated_at | datetime | yes | |

### `shops`

Pharmacy branches.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| id | bigint identity | no | PK |
| shop_code | nvarchar(50) | no | **unique** — the business key |
| shop_name | nvarchar(200) | no | **indexed** |
| address | nvarchar(500) | yes | |
| city | nvarchar(100) | yes | |
| contact_person | nvarchar(150) | yes | |
| contact_number | nvarchar(30) | yes | |
| status | nvarchar(20) | no | default `active`, **indexed** |
| created_by / updated_by | bigint | yes | FK → `users.id`, null on delete |
| created_at / updated_at | datetime | yes | |

### `shop_user`

Which shops a user may see. A user with no rows here is unrestricted only if
their role carries `shops.view_all`.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| id | bigint identity | no | PK |
| shop_id | bigint | no | FK → `shops.id`, cascade delete |
| user_id | bigint | no | FK → `users.id`, cascade delete |

**Unique:** `(shop_id, user_id)`.

### `items`

The pharmacy item master.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| id | bigint identity | no | PK |
| product_code | nvarchar(60) | no | **unique** |
| barcode | nvarchar(60) | yes | **indexed** — scanned by the HHT |
| description | nvarchar(300) | no | |
| generic_name | nvarchar(200) | yes | |
| manufacturer | nvarchar(200) | yes | |
| uom | nvarchar(20) | no | default `EA` |
| price | decimal(18,4) | no | default 0 |
| status | nvarchar(20) | no | default `active`, **indexed** |
| created_by / updated_by | bigint | yes | FK → `users.id` |
| created_at / updated_at | datetime | yes | |

### `devices`

HHT terminals. A device belongs to exactly one shop.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| id | bigint identity | no | PK |
| shop_id | bigint | no | FK → `shops.id`, cascade delete |
| device_code | nvarchar(50) | no | e.g. `HHT-01` |
| description | nvarchar(200) | yes | |
| serial_number | nvarchar(100) | yes | |
| status | nvarchar(20) | no | default `active`, **indexed** |
| last_submission_at | datetime | yes | Updated on each accepted submission |

**Unique:** `device_shop_code_unique (shop_id, device_code)` — the same code may
be reused in a different shop.

### `stock_imports`

One row per import attempt.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| id | bigint identity | no | PK |
| shop_id | bigint | no | FK → `shops.id`, **indexed** |
| file_name | nvarchar(255) | no | As uploaded |
| stored_path | nvarchar(500) | yes | Where the file was kept |
| total_records | int | no | Data rows in the file |
| success_records | int | no | Rows written |
| failed_records | int | no | Rows rejected |
| replaced_records | int | no | Rows removed from the shop's previous stock |
| status | nvarchar(20) | no | `completed`, `completed_with_errors`, `failed`, **indexed** |
| failure_reason | nvarchar(500) | yes | |
| imported_by | bigint | yes | FK → `users.id` |
| imported_at | datetime | yes | |

### `stock_import_errors`

Row-level rejections, so the user is told exactly what to fix.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| id | bigint identity | no | PK |
| stock_import_id | bigint | no | FK → `stock_imports.id`, cascade delete, **indexed** |
| row_number | int | no | Row in the workbook |
| column_name | nvarchar(100) | yes | |
| column_value | nvarchar(300) | yes | |
| error_message | nvarchar(500) | no | Written for a business user |

### `item_stocks`

The system stock a shop currently holds. **Replaced** on each import.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| id | bigint identity | no | PK |
| shop_id | bigint | no | FK → `shops.id`, cascade delete |
| item_id | bigint | yes | FK → `items.id`, null on delete |
| stock_import_id | bigint | yes | FK → `stock_imports.id` — which import produced this row |
| product_code | nvarchar(60) | no | |
| barcode | nvarchar(60) | yes | |
| description | nvarchar(300) | no | |
| system_qty | decimal(18,3) | no | default 0 |
| uom | nvarchar(20) | no | default `EA` |
| price | decimal(18,4) | no | default 0 |
| batch | nvarchar(60) | no | default `''` — empty rather than null so the unique key behaves the same on every engine |
| expiry_date | date | yes | |
| shelf_location | nvarchar(100) | yes | |
| verification_status | nvarchar(20) | no | `not_verified` / `verified` / `adjusted`, **indexed** |

**Unique:** `item_stock_identity_unique (shop_id, product_code, batch)`
**Index:** `item_stock_shop_barcode_idx (shop_id, barcode)`

The same product in three shops is three separate rows. Barcode alone never
identifies stock.

### `audits`

A completed HHT count.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| id | bigint identity | no | PK |
| shop_id | bigint | no | FK → `shops.id`, cascade delete |
| device_id | bigint | no | FK → `devices.id`, cascade delete |
| audit_number | int | no | **indexed** — not unique on its own |
| audit_date | date | no | **indexed** |
| hht_user | nvarchar(150) | yes | Who performed the count on the device |
| submitted_at | datetime | yes | When the submission arrived |
| item_count | int | no | Lines in the count |
| variance_count | int | no | Lines whose variance is not zero |
| status | nvarchar(25) | no | `submitted`, `in_verification`, `verified`, `adjusted`, `closed`, **indexed** |
| verified_by | bigint | yes | FK → `users.id` |
| verified_at | datetime | yes | |

**Unique:** `audit_identity_unique (shop_id, device_id, audit_number)`

### `hht_submissions`

The receipt for each submission, kept separately from the audit so retries can
be recognised.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| id | bigint identity | no | PK |
| submission_uid | nvarchar(80) | no | Identifier generated by the device |
| shop_id | bigint | no | FK → `shops.id` |
| device_id | bigint | no | FK → `devices.id` |
| audit_number | int | no | |
| audit_date | date | no | |
| hht_user | nvarchar(150) | yes | |
| app_version | nvarchar(30) | yes | |
| item_count | int | no | |
| payload_hash | nvarchar(64) | no | SHA-256 of the submission body — catches a retry that lost its uid |
| status | nvarchar(25) | no | `accepted`, `duplicate_ignored`, `rejected`, **indexed** |
| message | nvarchar(500) | yes | |
| audit_id | bigint | yes | FK → `audits.id` — the audit this created |
| received_at | datetime | yes | **indexed** |

**Unique:** `hht_submission_identity_unique (shop_id, device_id, audit_number, submission_uid)`

### `audit_lines`

One counted product. Carries the verification state and the variance.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| id | bigint identity | no | PK |
| audit_id | bigint | no | FK → `audits.id`, cascade delete, **indexed** |
| shop_id | bigint | no | FK → `shops.id` — denormalised so shop scoping needs no join |
| item_stock_id | bigint | yes | FK → `item_stocks.id`; null when the product is not in the stock file |
| product_code | nvarchar(60) | yes | **indexed** |
| barcode | nvarchar(60) | yes | |
| description | nvarchar(300) | yes | |
| system_qty | decimal(18,3) | no | Snapshot taken at submission |
| physical_qty | decimal(18,3) | no | What was counted |
| variance_qty | decimal(18,3) | no | `physical_qty − system_qty` |
| uom | nvarchar(20) | no | |
| price | decimal(18,4) | no | |
| batch | nvarchar(60) | no | default `''` |
| expiry_date | date | yes | |
| shelf_location | nvarchar(100) | yes | |
| is_unknown_item | bit | no | The product is not in the shop's stock file |
| verification_status | nvarchar(20) | no | `pending` / `verified`, **indexed** |
| adjustment_status | nvarchar(20) | no | `not_adjusted` / `adjusted`, **indexed** |
| verified_by | bigint | yes | FK → `users.id` |
| verified_at / adjusted_at | datetime | yes | |
| remarks | nvarchar(500) | yes | |

**Index:** `audit_line_shop_barcode_idx (shop_id, barcode)`

`system_qty` is a snapshot rather than a live join, so an audit still shows what
the system held at the moment of the count even after later imports.

### `stock_adjustments`

History of adjustments that have already been applied.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| id | bigint identity | no | PK |
| audit_line_id | bigint | yes | FK → `audit_lines.id` |
| audit_id | bigint | yes | FK → `audits.id`, **indexed** |
| shop_id | bigint | no | FK → `shops.id`, **indexed** |
| item_stock_id | bigint | yes | FK → `item_stocks.id` |
| product_code / barcode / description / batch | nvarchar | yes | Copied so history survives later stock replacement |
| old_system_qty | decimal(18,3) | no | Before the adjustment |
| physical_qty | decimal(18,3) | no | What was counted |
| variance_qty | decimal(18,3) | no | |
| new_system_qty | decimal(18,3) | no | After the adjustment |
| reason | nvarchar(500) | yes | |
| adjusted_by | bigint | yes | FK → `users.id` |
| adjusted_at | datetime | yes | **indexed** |

This table is a record, never a queue — there is no approval workflow.

### `stock_takes`

Physical stock found on the shelf that the stock file does not carry.
Recording one never creates an `items` row.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| id | bigint identity | no | PK |
| shop_id | bigint | no | FK → `shops.id`, **indexed** |
| audit_id / audit_line_id | bigint | yes | The count it came from, when applicable |
| barcode | nvarchar(60) | yes | **indexed** |
| product_code | nvarchar(60) | yes | |
| description | nvarchar(300) | no | |
| physical_qty | decimal(18,3) | no | |
| uom | nvarchar(20) | no | |
| batch | nvarchar(60) | no | default `''` |
| expiry_date | date | yes | |
| shelf_location | nvarchar(100) | yes | |
| status | nvarchar(20) | no | default `recorded` |
| remarks | nvarchar(500) | yes | |
| taken_by | bigint | yes | FK → `users.id` |
| taken_at | datetime | yes | **indexed** |

### `final_outputs`

The closing file for an audit and the record of its upload.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| id | bigint identity | no | PK |
| shop_id | bigint | no | FK → `shops.id` |
| audit_id | bigint | no | FK → `audits.id`, cascade delete, **indexed** |
| file_name | nvarchar(255) | no | `SHOP_DEVICE_Audit-N_timestamp.xlsx` |
| file_path | nvarchar(500) | yes | Location in application storage |
| record_count | int | no | |
| verification_status | nvarchar(25) | no | `verified` / `partially_verified` |
| adjustment_status | nvarchar(25) | no | `completed` / `pending` |
| onedrive_status | nvarchar(25) | no | `not_uploaded`, `uploading`, `uploaded`, `failed`, **indexed** |
| onedrive_item_id | nvarchar(200) | yes | |
| onedrive_url | nvarchar(1000) | yes | |
| upload_attempts | int | no | Increments on each Share click |
| last_error | nvarchar(500) | yes | The reason shown when Retry is offered |
| uploaded_at | datetime | yes | |
| generated_by | bigint | yes | FK → `users.id` |
| generated_at | datetime | yes | |

### `app_settings`

Application settings surfaced on the Settings screen. Credentials are never
stored here.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| id | bigint identity | no | PK |
| group_name | nvarchar(50) | no | **indexed** |
| key_name | nvarchar(100) | no | **unique** |
| value | nvarchar(1000) | yes | |
| value_type | nvarchar(20) | no | `string` / `integer` / `boolean` |
| label / description | nvarchar | yes | Shown on the screen |
| is_editable | bit | no | |

### Package tables

| Table | Package | Purpose |
| --- | --- | --- |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | spatie/laravel-permission | RBAC |
| `activity_log` | spatie/laravel-activitylog | Audit trail: user, time, subject, description, old and new values |
| `personal_access_tokens` | laravel/sanctum | API tokens |
| `cache`, `jobs`, `sessions`, `password_reset_tokens` | Laravel | Framework support |

---

## 3. Cascade Behaviour

**SQL Server permits only one cascade or set-null path between any two tables.**
It rejects a schema with more than one — `Introducing FOREIGN KEY constraint …
may cause cycles or multiple cascade paths` — where MySQL and SQLite accept it
silently. The delete rules below are therefore arranged so that every table is
reachable from any ancestor by exactly one path.

### Cascading deletes

| Parent | Child | Path |
| --- | --- | --- |
| shops | shop_user, devices, stock_imports, item_stocks, audits, stock_takes, stock_adjustments | direct, one each |
| devices | hht_submissions | the submission belongs to the device that produced it |
| audits | audit_lines, final_outputs | the audit owns its lines and its closing file |
| stock_imports | stock_import_errors | the error belongs to its import |
| users | shop_user | removing a user removes their shop assignments |

Deleting a shop therefore still removes everything beneath it:
`shops → devices → hht_submissions` and `shops → audits → audit_lines /
final_outputs`, with `item_stocks`, `stock_takes`, `stock_adjustments`,
`stock_imports → stock_import_errors` and `shop_user` going directly.

### Restricted references (NO ACTION)

| Column | References | Effect |
| --- | --- | --- |
| created_by, updated_by, verified_by, imported_by, adjusted_by, taken_by, generated_by | users | A user who has history cannot be deleted. The application never deletes users — it deactivates them — so this is the intended outcome and it strengthens the guarantee that history survives the person leaving. |
| audits.device_id | devices | A device with audits cannot be deleted. Its submissions still cascade from the device, and its audits cascade from the shop. |
| item_stocks.item_id | items | A product still held as stock cannot be removed from the item master. |

### Soft references (indexed column, no constraint)

Some columns carry an id for lookup and scoping but deliberately hold no foreign
key, because the row they point at is expected to disappear underneath them or
because the constraint would introduce a second delete path:

| Column | Why |
| --- | --- |
| audit_lines.shop_id, final_outputs.shop_id, hht_submissions.shop_id | Denormalised so shop scoping needs no join. The row is already removed through its audit or device. |
| audit_lines.item_stock_id, stock_adjustments.item_stock_id | Stock is **replaced wholesale on every import**, so these ids go stale by design. The audit keeps its own `system_qty` snapshot, and `StockAdjustmentService` already refuses politely when the stock row is gone. |
| item_stocks.stock_import_id | Records which import produced the row; imports are retained while the stock they created is replaced. |
| stock_adjustments.audit_id / audit_line_id, stock_takes.audit_id / audit_line_id | The history outlives the audit it came from. |

These columns keep their indexes, and the application always writes them from
the parent it just resolved, so the values remain correct in normal operation.
The trade is deliberate: a little referential integrity moves from the database
into the application in exchange for a schema that is identical on SQL Server,
MySQL and SQLite.

## 4. Indexing Rationale

| Index | Serves |
| --- | --- |
| `item_stock_identity_unique` | The stock replacement upsert and duplicate detection during import |
| `item_stock_shop_barcode_idx` | Matching a scanned barcode to stock during submission |
| `audit_identity_unique` | Rejecting a second count under the same shop, device and audit number |
| `hht_submission_identity_unique` | Recognising a retried submission |
| `audit_lines.audit_id` | Loading an audit's lines |
| `audit_lines.verification_status` / `adjustment_status` | The verification and variance worklists |
| `stock_adjustments.adjusted_at` | The adjustment report's date range |
| `final_outputs.onedrive_status` | Finding failed uploads to retry |
