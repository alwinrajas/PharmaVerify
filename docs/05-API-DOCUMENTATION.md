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
business rule.

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

**Purpose:** Import a shop's stock file and **replace** the stock it currently
holds.
**Permission:** `stock.import`
**Content type:** `multipart/form-data`

| Field | Rules |
| --- | --- |
| `shop_id` | required, must exist |
| `file` | required, `.xls` or `.xlsx`, ≤ 20 MB |

Required columns (headings matched flexibly): **Product Code**, **Product
Description**, **System Stock**.
Optional columns: Shop, Barcode, UOM, Price, Batch, Expiry Date, Shelf Location.

Response `201`:

```json
{
  "success": true,
  "message": "Stock import completed successfully. 240 record(s) imported and 236 previous record(s) replaced.",
  "data": { "id": 12, "total_records": 240, "success_records": 240, "failed_records": 0, "replaced_records": 236, "status": "completed" }
}
```

**Errors**

| Status | Cause |
| --- | --- |
| 422 | Wrong extension, a required column missing, the workbook unreadable, or every row invalid — **nothing is changed** |
| 403 | Caller lacks `stock.import` |

Row-level problems do not fail the import: valid rows are written, invalid rows
are listed with a reason and `status` becomes `completed_with_errors`.

The delete-and-insert runs in one transaction. A failure leaves the previous
stock exactly as it was.

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
