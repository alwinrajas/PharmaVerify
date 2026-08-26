# 05 — API Documentation

Base URL: `/api`
Authentication: `Authorization: Bearer <token>` on every route except login.
Content type: `application/json` (except the stock import, which is `multipart/form-data`).

## Response envelope

Success:

```json
{
  "success": true,
  "message": "Optional message written for the user",
  "data": { },
  "meta": { "current_page": 1, "per_page": 25, "total": 120, "last_page": 5 }
}
```

Failure:

```json
{
  "success": false,
  "message": "A sentence a business user can act on",
  "errors": { "field": ["The specific problem with this field"] }
}
```

Stack traces, SQL text and exception names are never returned.

## Common list parameters

Every list endpoint accepts:

| Parameter | Default | Notes |
| --- | --- | --- |
| `page` | 1 | |
| `per_page` | 25 | Maximum 200 |
| `search` | — | Matches the endpoint's searchable columns |
| `sort_by` | per endpoint | Ignored if not a sortable column |
| `sort_dir` | `asc` / `desc` | |

Status codes: `200` OK · `201` Created · `401` Unauthenticated · `403` No
permission · `404` Not found · `409` Business conflict · `422` Validation or
business rule · `429` Too many requests.

## Rate limiting

Every API route is throttled. Limits are counted per caller — a signed-in user,
or an origin for anyone not yet signed in — never globally, so one busy or
hostile caller cannot exhaust everybody else's allowance.

| Scope | Limit | Counted by |
| --- | --- | --- |
| `POST /auth/login` | 5 per minute | email **and** origin together |
| `POST /auth/login` | 20 per minute | origin alone, to stop one source spraying many accounts |
| All other API routes, signed in | 300 per minute | user |
| All other API routes, not signed in | 60 per minute | origin |
| `POST /stock-imports` | 6 per 10 minutes | user | 

The general allowance is deliberately roomy: a screen in the web application
fires several requests at once, and an HHT device must be free to retry a
dropped submission — a retry is how idempotency is exercised, so throttling one
would strand a finished count on the device.

Exceeding a limit returns `429` in the standard envelope:

```json
{
  "success": false,
  "message": "Too many requests. Please wait a moment and try again.",
  "retry_after_seconds": 47
}
```

The usual `Retry-After` and `X-RateLimit-*` headers are set so a client can back
off. The reply never names the limit that was reached, and on a sign-in attempt
it never reveals whether the account exists.

---

## Authentication

### POST `/auth/login`

**Purpose:** Sign in and receive an API token.
**Authentication:** none.

Request:

```json
{ "email": "admin@pharmaverify.com", "password": "Pharma@2026", "device_name": "pharmaverify-web" }
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "token": "1|xxxxxxxx",
    "user": { "id": 1, "name": "…", "roles": ["Administrator"], "permissions": ["shops.view", "…"], "shops": [] }
  }
}
```

**Errors:** `422` invalid credentials or a deactivated account.

### GET `/auth/me`

Returns the signed-in user with roles, permissions and assigned shops.

### POST `/auth/logout`

Revokes the current token. `200`.

---

## Dashboard

### GET `/dashboard/summary`

Cards, variance breakdown and the recent submissions, audits and adjustments the
user is allowed to see.

---

## Master data

### Shops — `/shops`

| Method | Path | Purpose | Permission |
| --- | --- | --- | --- |
| GET | `/shops` | List. Filters: `status` | `shops.view` |
| GET | `/shops/options` | Lightweight list for selectors | authenticated |
| POST | `/shops` | Create | `shops.create` |
| GET | `/shops/{shop}` | View | `shops.view` |
| PUT | `/shops/{shop}` | Update, including activate/deactivate via `status` | `shops.edit` |
| DELETE | `/shops/{shop}` | Remove | `shops.delete` |

Body: `shop_code` (unique), `shop_name`, `address`, `city`, `contact_person`,
`contact_number`, `status` (`active`/`inactive`).

A Shop User sees only their assigned shops.

### Items — `/items`

| Method | Path | Purpose | Permission |
| --- | --- | --- | --- |
| GET | `/items` | List. Filters: `status`, `uom`, `manufacturer` | `items.view` |
| POST | `/items` | Create | `items.create` |
| GET | `/items/{item}` | View | `items.view` |
| PUT | `/items/{item}` | Update | `items.edit` |
| DELETE | `/items/{item}` | Remove | `items.delete` |

Body: `product_code` (unique), `barcode`, `description`, `generic_name`,
`manufacturer`, `uom`, `price`, `status`.

### Devices — `/devices`

| Method | Path | Purpose | Permission |
| --- | --- | --- | --- |
| GET | `/devices` | List. Filters: `shop_id`, `status` | `devices.view` |
| GET | `/devices/options` | Selector list; `shop_id` narrows it | authenticated |
| POST | `/devices` | Register | `devices.create` |
| PUT | `/devices/{device}` | Update | `devices.edit` |
| DELETE | `/devices/{device}` | Remove | `devices.delete` |

Body: `shop_id`, `device_code` (unique within the shop), `description`,
`serial_number`, `status`.

---

## Stock

### POST `/stock-imports`

**Purpose:** Import stock and **replace** what is currently held.
**Permission:** `stock.import`
**Content type:** `multipart/form-data`
**Rate limit:** 6 per 10 minutes per user.

Two file shapes are accepted. Which one arrived is decided from the workbook's
sheet names, not from a parameter.

| Field | Rules |
| --- | --- |
| `file` | required, `.xls` or `.xlsx`, ≤ 100 MB |
| `shop_id` | **optional**, must exist. Required for a flat file; for a Stock Report it acts as a filter, limiting the import to that one shop |

#### The business Stock Report (three sheets)

A Dynamics AX export whose sheets are named `stock`, `all batches` and
`Item Master`. It carries every branch it was run for, so one upload replaces
the stock of each shop it names; shops it does not mention are untouched. Shops
are matched by warehouse code (`INVENTLOCATIONID`) through the shop's AX
location.

Response `201` — note `data` is an **array**, one entry per shop:

```json
{
  "success": true,
  "message": "Stock Report imported successfully. 8,910 row(s) across 2 shop(s) imported and 27 previous record(s) replaced.",
  "data": [
    { "id": 1, "shop_code": "PHM001", "total_records": 4953, "success_records": 4950, "failed_records": 3, "replaced_records": 15, "status": "completed_with_errors" },
    { "id": 2, "shop_code": "PHM002", "total_records": 3960, "success_records": 3960, "failed_records": 0, "replaced_records": 12, "status": "completed" }
  ],
  "meta": {
    "format": "stock_report",
    "summary": {
      "shops": 2,
      "total_rows": 8913,
      "imported": 8910,
      "failed": 3,
      "replaced": 27,
      "items_synced": 4729,
      "barcodes_matched": 6394
    }
  }
}
```

`meta.format` is `stock_report`, which is how a client tells the two shapes
apart. See `17-STOCK-REPORT-IMPORT.md` for the sheet and column contract.

> The Stock Report module is **on hold pending client confirmation** of four
> business decisions (quantity column, warehouse mapping, item-master sync and
> price source). The endpoint behaves as described today, but those points may
> still change — see `17-STOCK-REPORT-IMPORT.md` §6.

#### The flat single-sheet file

One sheet for one shop. `shop_id` is **required**; omitting it returns `422`
with *"Choose the shop this file belongs to…"*.

Required columns (headings matched flexibly): **Product Code**, **Product
Description**, **System Stock**.
Optional: Shop, Barcode, UOM, Price, Batch, Expiry Date, Shelf Location.

Response `201` — `data` is a **single object**, and there is no `meta.summary`:

```json
{
  "success": true,
  "message": "Stock import completed successfully. 240 record(s) imported and 236 previous record(s) replaced.",
  "data": { "id": 12, "total_records": 240, "success_records": 240, "failed_records": 0, "replaced_records": 236, "status": "completed" }
}
```

#### Errors

| Status | Cause |
| --- | --- |
| 422 | Wrong extension, over 100 MB, a required sheet or column missing, the workbook unreadable, every row invalid, or a flat file with no `shop_id` — **nothing is changed** |
| 403 | Caller lacks `stock.import` |
| 429 | More than 6 imports in 10 minutes |

Row-level problems do not fail the import: valid rows are written, invalid rows
are listed with a reason and `status` becomes `completed_with_errors`.

The whole exchange runs in one transaction. A failure leaves the previous stock
exactly as it was.

A large report takes a minute or more to process, and the endpoint lifts its own
execution limit for the duration. The web server's own timeout still applies —
see `12-DEPLOYMENT-GUIDE.md`.
### GET `/stock-imports` · `/stock-imports/{id}` · `/stock-imports/{id}/errors` · `/stock-imports/template`

History, detail, the row-level errors, and the expected column list.

### GET `/item-stocks`

**Purpose:** The system stock per shop.
**Permission:** `stock.view`

Filters: `shop_id`, `product_code`, `barcode`, `batch`, `verification_status`,
`shelf_location`, `expiry_from`, `expiry_to`, `expiring_soon=1`, `search`.

`meta.summary` carries `record_count`, `total_quantity` and `expiring_soon`.

---

## HHT

### POST `/hht/submissions`

**Purpose:** Receive a completed physical count from a handheld device.
**Authentication:** Bearer token (device or user).
**Idempotent:** yes — see below.

Request:

```json
{
  "submission_uid": "SUB-PHM001-HHT-02-A3",
  "shop_code": "PHM001",
  "device_code": "HHT-02",
  "audit_number": 3,
  "audit_date": "2026-08-25",
  "hht_user": "Karthik Subramani",
  "app_version": "1.4.2",
  "items": [
    {
      "barcode": "8901234500011",
      "product_code": "MED-1001",
      "physical_quantity": 95,
      "batch": "B01001",
      "expiry": "2027-06-30",
      "uom": "STRIP",
      "shelf_location": "A-01"
    }
  ]
}
```

`shop_id` / `device_id` may be sent instead of `shop_code` / `device_code`.

Response `201`:

```json
{
  "success": true,
  "message": "Submission accepted. Audit 3 for PHM001 / HHT-02 created with 42 item(s).",
  "data": { "submission_id": 88, "audit_id": 41, "audit_number": 3, "status": "accepted", "item_count": 42 }
}
```

Response `200` when the same submission arrives again:

```json
{
  "success": true,
  "message": "This submission has already been received. The existing audit has been returned and no duplicate was created.",
  "data": { "submission_id": 88, "audit_id": 41, "audit_number": 3, "status": "duplicate_ignored", "item_count": 42 }
}
```

**Validation**

| Field | Rule |
| --- | --- |
| `shop_id` / `shop_code` | One is required; must resolve to an active shop |
| `device_id` / `device_code` | One is required; must be an active device **of that shop** |
| `audit_number` | Required integer ≥ 1 |
| `audit_date` | Required date |
| `items` | Required, at least one entry |
| `items.*.physical_quantity` | Required, numeric, ≥ 0 |
| `items.*.product_code` | Required unless `barcode` is present |

**Errors**

| Status | Cause |
| --- | --- |
| 422 | Unknown or inactive shop, device not registered against the shop, empty items, negative quantity |
| 409 | A different count already exists for this shop, device and audit number |

### GET `/hht/submissions` · `/hht/submissions/{id}`

List and detail. Filters: `shop_id`, `device_id`, `audit_number`, `status`,
`date_from`, `date_to`, `search`.

---

## Stock Audit

| Method | Path | Purpose | Permission |
| --- | --- | --- | --- |
| GET | `/audits` | List. Filters: `shop_id`, `device_id`, `audit_number`, `status`, `date_from`, `date_to` | `audits.view` |
| GET | `/audits/{audit}` | Header detail | `audits.view` |
| GET | `/audits/{audit}/lines` | Counted lines, paginated. Filters: `variance`, `verification_status`, `adjustment_status`, `batch`, `search` | `audits.view` |
| POST | `/audits/{audit}/verify` | Mark every outstanding line verified | `verification.edit` |

`/audits/{audit}/lines` returns `meta.summary` with `total_lines`,
`positive_variance`, `negative_variance`, `zero_variance`,
`pending_verification`, `adjusted`, `unknown_items` and `net_variance`.

---

## Verification

### GET `/verification`

The worklist across every audit the user can see. Filters: `shop_id`,
`device_id`, `audit_id`, `audit_number`, `verification_status`,
`adjustment_status`, `variance`, `batch`, `search`.

### PATCH `/verification/lines/{auditLine}`

**Purpose:** Correct a counted line on a completed audit.
**Permission:** `verification.edit`

```json
{
  "physical_qty": 98,
  "batch": "B01001",
  "expiry_date": "2027-06-30",
  "shelf_location": "A-01",
  "remarks": "Recounted with the shelf supervisor",
  "mark_verified": true
}
```

Only these fields may be changed. The variance is **recomputed by the server**
and never accepted from the request. Every change is written to the activity log
with its old and new value.

**Errors:** `403` without the permission; `422` on a negative quantity or a
closed audit.

---

## Variance

### GET `/variance`

Filters: `variance` (`negative` / `positive` / `zero` / `non_zero` / `all`,
default `non_zero`), `shop_id`, `device_id`, `audit_id`, `audit_number`,
`product_code`, `barcode`, `batch`, `adjustment_status`, `date_from`, `date_to`,
`search`.

`meta.summary`: `total_lines`, `positive_count`, `positive_quantity`,
`negative_count`, `negative_quantity`, `zero_count`, `net_variance`,
`pending_adjustment`.

### GET `/variance/summary`

The summary block alone.

---

## Stock Adjustment

### POST `/adjustments`

**Purpose:** Post an adjustment. **It takes effect immediately — there is no
approval workflow.**
**Permission:** `adjustments.create`

Single line:

```json
{ "audit_line_id": 512, "reason": "Physical count confirmed by supervisor" }
```

Several lines:

```json
{ "audit_line_ids": [512, 513, 514], "reason": "Month-end recount" }
```

Response `201` (single):

```json
{
  "success": true,
  "message": "Adjustment posted. System stock for MED-1005 is now 120.",
  "data": { "old_system_qty": 125, "physical_qty": 120, "variance_qty": -5, "new_system_qty": 120 }
}
```

For a batch, `data.adjustments` holds what was posted and `data.skipped` lists
anything refused with its reason, so one refusal does not discard the rest.

**Errors**

| Status | Cause |
| --- | --- |
| 422 | The line is already adjusted, or the product is not in the stock file (record a Stock Take instead) |
| 403 | Caller lacks `adjustments.create` |

### GET `/adjustments` · `/adjustments/{id}`

Adjustment history. Filters: `shop_id`, `audit_id`, `product_code`, `batch`,
`adjusted_by`, `date_from`, `date_to`, `search`.

---

## Stock Take

| Method | Path | Purpose | Permission |
| --- | --- | --- | --- |
| GET | `/stock-takes` | List. Filters: `shop_id`, `audit_id`, `status`, `barcode`, `date_from`, `date_to` | `stocktake.view` |
| GET | `/stock-takes/candidates` | Counted products missing from the stock file and not yet written up | `stocktake.view` |
| POST | `/stock-takes` | Record one | `stocktake.create` |
| PUT | `/stock-takes/{stockTake}` | Update | `stocktake.create` |
| DELETE | `/stock-takes/{stockTake}` | Remove | `stocktake.create` |

Body: `shop_id`, `description` and `physical_qty` are required; `audit_id`,
`audit_line_id`, `barcode`, `product_code`, `uom`, `batch`, `expiry_date`,
`shelf_location`, `remarks` are optional.

**Recording a stock take never creates an Item Master record.**

---

## Reports

### GET `/reports`

The reports this user may run, each with its columns, filters and default sort.

### GET `/reports/{report}`

Report keys: `variance`, `audit-number`, `shop-stock`, `overall-stock`,
`user-log`, `adjustment`, `detailed`, `variance-summary`, `stock-occurrence`.

| Parameter | Notes |
| --- | --- |
| `format` | `json` (default), `xlsx`, `pdf` |
| filters | Whatever the report declares — see `/reports` |

`format=xlsx` and `format=pdf` return a download and require `reports.export`.
An export is capped at 20,000 rows; the file says so when it was truncated.

**Errors:** `404` unknown report key; `403` without `reports.view` (or
`reports.export` for a download).

---

## Final Output & OneDrive

| Method | Path | Purpose | Permission |
| --- | --- | --- | --- |
| GET | `/final-outputs` | List. Filters: `shop_id`, `audit_id`, `onedrive_status`, `date_from`, `date_to` | `finaloutput.view` |
| POST | `/final-outputs` | Generate for `audit_id`. Nothing is uploaded | `finaloutput.generate` |
| GET | `/final-outputs/{id}` | Detail | `finaloutput.view` |
| GET | `/final-outputs/{id}/download` | Download the file | `finaloutput.view` |
| POST | `/final-outputs/{id}/share-onedrive` | **Upload to OneDrive** | `onedrive.share` |

`meta.onedrive_driver` on the list tells the frontend whether the real Graph
driver or the demonstration driver is active.

**Errors on share**

| Status | Cause |
| --- | --- |
| 422 | Already uploaded, or the file is no longer in storage |
| 502 | The upload failed; `last_error` holds the reason and Retry is offered |

---

## Administration

| Method | Path | Purpose | Permission |
| --- | --- | --- | --- |
| GET | `/users` | List. Filters: `role`, `status` | `users.manage` |
| POST | `/users` | Create with role and shop assignment | `users.manage` |
| GET | `/users/{user}` | View | `users.manage` |
| PUT | `/users/{user}` | Update | `users.manage` |
| POST | `/users/{user}/reset-password` | Set a new password and revoke tokens | `users.manage` |
| POST | `/users/{user}/toggle-status` | Activate / deactivate | `users.manage` |
| GET | `/users/roles` | Roles with their permissions | `users.manage` |
| GET | `/settings` | Settings and integration status | authenticated |
| PUT | `/settings` | Save settings | `settings.manage` |
| GET | `/activity-log` | Audit trail. Filters: `log_name`, `user_id`, `subject_type`, `subject_id`, dates | `activity.view` |

The last active administrator cannot be demoted or deactivated (`422`).
