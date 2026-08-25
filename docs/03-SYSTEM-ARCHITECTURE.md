# 03 — System Architecture

## 1. Overview

```
Browser
   │
   ▼
React 19 + TypeScript + Vite + Material UI
   │  REST over HTTPS, Bearer token
   ▼
Laravel 12 (API only)
   │  Eloquent
   ▼
Microsoft SQL Server   (development runs on MySQL — see docs/15)
```

The application is an API-first Laravel backend with a separate React single-page
frontend. Laravel serves JSON only; it renders no application HTML apart from the
PDF report template.

## 2. Component Architecture

### Backend

```
routes/api.php
      │
      ▼
Controller ──► FormRequest (validation)
      │
      ▼
   Service  ──► Model / Eloquent ──► Database
      │
      ▼
 API Resource (response shape)
```

| Layer | Responsibility |
| --- | --- |
| Controller | HTTP concerns, permission check, delegate to a service, return a resource |
| FormRequest | Input validation and the messages the user reads |
| Service | Business rules and transactions (`app/Services`) |
| Model | Persistence, relationships, query scopes |
| API Resource | The JSON shape of the response |
| Policy / permission | Authorisation, always enforced server side |

Key services:

| Service | Responsibility |
| --- | --- |
| `StockImportService` | Reads the Excel file, validates every row, replaces the shop's stock atomically |
| `HhtSubmissionService` | Receives a completed count, enforces submission identity and idempotency |
| `VerificationService` | Corrects counted lines, keeps the audit header in step, writes the audit trail |
| `StockAdjustmentService` | Posts an adjustment immediately and records its history |
| `FinalOutputService` | Builds the closing workbook and hands it to the OneDrive uploader |
| `Reports\ReportRegistry` / `ReportService` / `ReportExportService` | One engine describing, running and exporting all nine reports |
| `OneDrive\OneDriveUploader` | Interface with a Graph implementation and a demonstration implementation |

### Frontend

```
src/
  theme/        one design system: colour, type, spacing, component defaults
  components/   AppLayout, DataTable, dialogs, filters, states, badges
  features/     one folder per business area, each owning its screens and calls
  hooks/        useTableQuery (list state), useOptions (shop/device selectors)
  services/     axios client, error translation, file download
  routes/       route table with the permission each screen requires
```

Every list screen is the same three parts: `useTableQuery` for state, a
`FilterBar` for the filters, and the shared `DataTable`. That is why the screens
behave identically and why a new list screen is small to add.

## 3. Data Flow — the business path

```
Shops / Items / Devices
        │
        ▼
Excel file ──► StockImportService ──► item_stocks   (replaces, atomic)
        │
        ▼
HHT device (offline counting, local storage only)
        │  one final POST when the operator presses Share / Submit
        ▼
HhtSubmissionService ──► hht_submissions + audits + audit_lines
        │
        ▼
VerificationService ──► audit_lines.verification_status  (+ activity log)
        │
        ▼
Variance = physical_qty − system_qty        (derived on the line)
        │
        ├──► StockAdjustmentService ──► item_stocks.system_qty  (immediate)
        │                          └──► stock_adjustments (history)
        │
        └──► StockTake ──► stock_takes  (item master untouched)
        │
        ▼
Reports  ──► screen / Excel / PDF
        │
        ▼
FinalOutputService ──► final_outputs (+ file in storage)
        │  only on an explicit user click
        ▼
OneDriveUploader ──► Microsoft Graph ──► OneDrive
```

## 4. Authentication Flow

```
POST /api/auth/login  (email, password)
        │
        ├─ invalid credentials  ──► 422, no token
        ├─ inactive account     ──► 422, no token
        │
        ▼
Laravel Sanctum issues a personal access token
        │
        ▼
Frontend stores the token and sends it as `Authorization: Bearer …`
        │
        ▼
Every subsequent request is authenticated, then authorised by permission
```

A 401 from any endpoint clears the stored token in the browser and returns the
user to the sign-in screen, so an expired session never shows a broken page.

The same token mechanism serves the HHT devices: a device authenticates once and
posts its completed audit with the token.

## 5. HHT Integration

```
Android HHT
   │ login, load required data
   │ scan products, enter physical quantity
   │ everything held in the device's local database
   ▼
Share / Submit  (one call, at the end)
   │
   ▼
POST /api/hht/submissions
   │
   ├─ authenticate
   ├─ resolve shop      (unknown → 422)
   ├─ resolve device    (not registered against the shop → 422)
   ├─ validate items    (empty list or negative quantity → 422)
   ├─ idempotency check on Shop + Device + Audit Number + submission_uid
   │      already seen → return the existing audit, create nothing
   ▼
transaction: audits header + audit_lines + hht_submissions record
   │
   ▼
Audit is visible on the web for verification
```

There is **no continuous synchronisation**. Nothing is sent scan by scan.

See `08-HHT-INTEGRATION.md` for the payload contract.

## 6. OneDrive Integration

```
Verified audit
   │
   ▼
User clicks "Generate Final Output"  ──► workbook written to storage,
   │                                     onedrive_status = not_uploaded
   ▼
User clicks "Share to OneDrive"      ──► the only point of upload
   │
   ▼
OneDriveUploader (bound by config)
   ├─ graph : token via client credentials, then PUT / upload session
   └─ demo  : writes to local storage and simulates the result
   │
   ▼
success → onedrive_status = uploaded, item id and URL stored
failure → onedrive_status = failed, reason stored, Retry offered
```

Nothing uploads automatically, on a schedule, or as a side effect of generating
the file.

## 7. Error Handling

`App\Exceptions\ApiExceptionRenderer` converts every exception raised on an API
route into the standard envelope:

```json
{ "success": false, "message": "…", "errors": { "field": ["…"] } }
```

| Exception | Status | What the user sees |
| --- | --- | --- |
| `ValidationException` | 422 | “The information supplied is not valid…” plus field messages |
| `AuthenticationException` | 401 | “Your session has expired. Please sign in again.” |
| `AuthorizationException` / `abort(403)` | 403 | “You do not have permission to perform this action.” |
| `ModelNotFoundException` | 404 | “The requested record could not be found.” |
| `BusinessRuleException` | 409 / 422 | The business message itself, written for the user |
| `QueryException` | 500 | A generic database message; the detail goes to the log |
| Anything else | 500 | A generic message; the detail goes to the log |

Stack traces, SQL text and exception class names never reach the response in
production. `APP_DEBUG=true` adds a `debug` block for developers only.

## 8. Security Boundaries

| Boundary | Control |
| --- | --- |
| Authentication | Sanctum tokens; passwords hashed with bcrypt |
| Authorisation | Permission checks in every controller action; the frontend only hides UI |
| Shop scoping | `ScopesToUserShops` restricts queries to a user's assigned shops |
| Input | FormRequest validation on every write; type and length limits |
| SQL injection | Eloquent and the query builder only — no string-concatenated SQL |
| File upload | Extension and MIME check, 20 MB limit, stored outside the public path |
| XSS | React escapes by default; the API returns data, not markup |
| CSRF | Not applicable to token-authenticated API routes; CORS is restricted to the configured frontend origin |
| Audit logging | `spatie/laravel-activitylog` records the significant actions with old and new values |
| Secrets | Database and Graph credentials live only in `.env`; the settings API never returns them |

## 9. Transactions

Three operations must be all-or-nothing and are wrapped in `DB::transaction`:

| Operation | Why |
| --- | --- |
| Stock import replacement | A partially replaced stock file would leave the shop with neither the old nor the new stock |
| HHT submission | An audit header without its lines is meaningless |
| Stock adjustment | The stock update and its history record must agree |

## 10. Deployment Topology

```
                    ┌──────────────────────┐
   Browsers ────────►  Web server (HTTPS)   │
                    │  serves frontend/dist │
                    └───────────┬───────────┘
                                │ /api/*
                    ┌───────────▼───────────┐
   HHT devices ─────►  PHP-FPM / Laravel     │
                    └───────────┬───────────┘
                                │
                    ┌───────────▼───────────┐
                    │  Microsoft SQL Server  │
                    └────────────────────────┘
                                │
                    ┌───────────▼───────────┐
                    │  Microsoft Graph       │  (outbound only, on demand)
                    └────────────────────────┘
```

See `12-DEPLOYMENT-GUIDE.md`.
