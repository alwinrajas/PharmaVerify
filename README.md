# PharmaVerify

**Pharmacy Stock Verification Web Application**

A browser-based application for verifying pharmacy stock after a physical count
has been performed on Android HHT devices. It holds each shop's system stock,
receives completed counts from the devices, exposes the variance, and lets an
authorised user correct the stock — with a full record of every change.

This is **not** a general inventory system. It has no purchase, sales, supplier,
procurement, purchase order, warehouse, goods receipt or transfer modules.

---

## The business flow

```
Master Data (Shops · Items · Devices)
        ↓
Item Stock Import (Excel)  →  System Stock          replaces, never appends
        ↓
HHT Physical Count (offline, on the device)
        ↓
Final Share / Submit       →  HHT Submission        one call, at the end
        ↓
Stock Audit  →  Stock Verification
        ↓
Variance  =  Physical Quantity − System Quantity
        ↓
Stock Adjustment  /  Stock Take                     adjustment posts immediately
        ↓
Reports  (Excel · PDF)
        ↓
Final Output  →  Share to OneDrive                  only on an explicit click
```

---

## Technology

| Layer | Technology |
| --- | --- |
| Frontend | React 19 · TypeScript · Vite · Material UI 7 · TanStack Query |
| Backend | Laravel 12 (API only) · PHP 8.2 |
| Database | Microsoft SQL Server (target) — see [Database](#database) |
| Authentication | Laravel Sanctum, token based |
| Authorisation | spatie/laravel-permission — permission based RBAC |
| Excel | PhpOffice/PhpSpreadsheet — import and export |
| PDF | barryvdh/laravel-dompdf |
| Audit trail | spatie/laravel-activitylog |
| OneDrive | Microsoft Graph API |
| HHT | Android / Kotlin (device application out of scope; the endpoint it posts to is built) |

---

## Project structure

```
pharmacy-stock-verification/
├── backend/                 Laravel API
│   ├── app/
│   │   ├── Http/            Controllers · Requests · Resources
│   │   ├── Models/          Eloquent models and query scopes
│   │   ├── Services/        Business rules and transactions
│   │   ├── Support/         Permissions, roles, response envelope
│   │   └── Exceptions/      API error translation
│   ├── database/            migrations · seeders
│   ├── routes/api.php       Every endpoint
│   └── tests/Feature/       52 feature tests
│
├── frontend/                React SPA
│   └── src/
│       ├── theme/           The design system
│       ├── components/      DataTable, dialogs, filters, states
│       ├── layouts/         AppLayout, Sidebar, navigation
│       ├── features/        One folder per business area
│       ├── hooks/           useTableQuery, useOptions
│       ├── services/        API client, error translation, downloads
│       └── routes/          Route table with permission guards
│
├── docs/                    01-BRD … 16-CHANGELOG
├── database/
│   ├── sql/                 Generated SQL Server schema
│   └── sample-data/         Sample stock import files (valid and with errors)
├── PROJECT-STATUS.md
└── README.md
```

---

## Prerequisites

| Requirement | Version |
| --- | --- |
| PHP | 8.2 or later, with `mbstring`, `openssl`, `pdo`, `fileinfo`, `zip`, `gd` |
| Composer | 2.x |
| Node.js | 20 or later |
| Database | Microsoft SQL Server 2017+ (target) or MySQL 5.7+ / MariaDB 10.4+ (development) |

For SQL Server you also need the Microsoft ODBC Driver 18 and the PHP `sqlsrv` /
`pdo_sqlsrv` extensions — see [docs/12-DEPLOYMENT-GUIDE.md](docs/12-DEPLOYMENT-GUIDE.md) §1.

---

## Installation

### 1. Database

Create an empty database:

```sql
-- SQL Server
CREATE DATABASE PharmaVerify;

-- MySQL / MariaDB
CREATE DATABASE pharmaverify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```dotenv
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173

DB_CONNECTION=mysql          # or sqlsrv
DB_HOST=127.0.0.1
DB_PORT=3306                 # 1433 for SQL Server
DB_DATABASE=pharmaverify
DB_USERNAME=YOUR_DATABASE_USER
DB_PASSWORD=YOUR_DATABASE_PASSWORD

ONEDRIVE_DRIVER=demo         # graph once credentials are available
```

`backend/.env.sqlsrv.example` holds the SQL Server block ready to copy over.

Then:

```bash
php artisan migrate --seed
```

`--seed` loads roles, settings, and realistic demonstration data — three shops,
22 pharmacy products, nine devices, 14 audits with a spread of variance, and four
users. **In production, seed only roles and settings** — see
[docs/12](docs/12-DEPLOYMENT-GUIDE.md) §3.

### 3. Frontend

```bash
cd frontend
npm install
```

No configuration is needed for development: Vite proxies `/api` to
`http://127.0.0.1:8000`.

---

## Running

Two terminals:

```bash
# Terminal 1 — API on http://127.0.0.1:8000
cd backend
php artisan serve

# Terminal 2 — application on http://localhost:5173
cd frontend
npm run dev
```

Open **http://localhost:5173**.

---

## Demonstration credentials

Password for all three: `Pharma@2026`

| Role | Email | Sees |
| --- | --- | --- |
| Administrator | `admin@pharmaverify.com` | Everything |
| Supervisor | `supervisor@pharmaverify.com` | Everything operational; not users or settings |
| Shop User | `annanagar@pharmaverify.com` | One assigned shop only |

The sign-in screen lists these and fills them in on a click.

---

## Build

```bash
# Frontend production bundle → frontend/dist
cd frontend
npm run build

# Backend production caches
cd backend
composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache && php artisan event:cache
```

---

## Testing

```bash
cd backend
php artisan test
```

52 feature tests, 248 assertions, covering the rules most likely to regress:
stock import replacement and its atomicity, HHT submission idempotency, variance
calculation, immediate adjustment posting, stock take not touching the item
master, RBAC and shop scoping, and the shape of error responses.

Tests run on SQLite, which also confirms the schema and queries carry no
engine-specific SQL. Because SQLite does not enforce string lengths, a guard
test reads each status column length straight from its migration and checks that
every value the code writes fits.

Frontend:

```bash
cd frontend
npx tsc -b          # type check
npm run build       # production build
npm run lint
```

---

## Database

**Target:** Microsoft SQL Server. **Development ran on MySQL**, because the build
machine had neither a SQL Server instance nor the `pdo_sqlsrv` extension.

The data layer uses Eloquent migrations and the query builder only — no raw SQL
and no engine-specific types — so the same schema applies to both. A generated
SQL Server script lives at `database/sql/schema-sqlserver.sql`:

```bash
cd backend
php artisan pharmaverify:sqlsrv-schema
```

Switching to SQL Server means copying `backend/.env.sqlsrv.example` over the
`DB_*` block and running `php artisan migrate:fresh --seed`. This dependency is
recorded in [docs/15](docs/15-ASSUMPTIONS-DEPENDENCIES.md) D-01.

---

## Sample data

`database/sample-data/` holds two stock files for demonstrations and manual
testing — one clean, one carrying the validation problems real files carry
(non-numeric quantity, missing product code, negative quantity, missing
description, a duplicate row, and a row belonging to another shop).

```bash
cd backend
php artisan pharmaverify:sample-stock --shop=PHM001
```

---

## Business rules that must not drift

| # | Rule |
| --- | --- |
| 1 | Importing a stock file **replaces** the shop's stock, atomically. A failed import leaves the previous stock untouched. |
| 2 | Stock identity is **shop + product + batch**. Barcode alone is never enough. |
| 3 | Submission identity is **shop + device + audit number**. The audit number alone repeats across devices. |
| 4 | The HHT sends **one final submission**. There is no continuous synchronisation. |
| 5 | A repeated submission is **recognised and ignored**, never duplicated. |
| 6 | `Variance = Physical Quantity − System Quantity`, always computed by the server. |
| 7 | An adjustment **posts immediately**. There is no approval workflow. |
| 8 | A Stock Take **never** creates an Item Master record. |
| 9 | OneDrive upload happens **only** when the user clicks Share to OneDrive. |
| 10 | Authorisation is enforced on the **backend**. The interface only hides things. |

Each is covered by a feature test.

---

## Documentation

| Document | Contents |
| --- | --- |
| [01-BRD](docs/01-BRD.md) | Objectives, users, workflow, business rules |
| [02-REQUIREMENTS](docs/02-REQUIREMENTS.md) | Numbered requirements with acceptance criteria |
| [03-SYSTEM-ARCHITECTURE](docs/03-SYSTEM-ARCHITECTURE.md) | Components, data flow, security boundaries |
| [04-DATABASE-DESIGN](docs/04-DATABASE-DESIGN.md) | Every table, column, key and index |
| [05-API-DOCUMENTATION](docs/05-API-DOCUMENTATION.md) | Every endpoint with request, response and errors |
| [06-SCREEN-FLOW](docs/06-SCREEN-FLOW.md) | Navigation and every screen's purpose and behaviour |
| [07-RBAC-MATRIX](docs/07-RBAC-MATRIX.md) | Roles, permissions and where each is enforced |
| [08-HHT-INTEGRATION](docs/08-HHT-INTEGRATION.md) | The device contract and duplicate handling |
| [09-ONEDRIVE-INTEGRATION](docs/09-ONEDRIVE-INTEGRATION.md) | Upload flow, drivers, Azure setup, failures |
| [10-REPORT-SPECIFICATION](docs/10-REPORT-SPECIFICATION.md) | All nine reports, their columns and filters |
| [11-TEST-CASES](docs/11-TEST-CASES.md) | Automated coverage and the manual walkthrough |
| [12-DEPLOYMENT-GUIDE](docs/12-DEPLOYMENT-GUIDE.md) | Server setup, deployment, production checklist |
| [13-USER-GUIDE](docs/13-USER-GUIDE.md) | For business users, written without jargon |
| [14-DEMO-GUIDE](docs/14-DEMO-GUIDE.md) | A 12–15 minute client demonstration |
| [15-ASSUMPTIONS-DEPENDENCIES](docs/15-ASSUMPTIONS-DEPENDENCIES.md) | What we assumed, what is outstanding |
| [16-CHANGELOG](docs/16-CHANGELOG.md) | Version history |
| [PROJECT-STATUS](PROJECT-STATUS.md) | Module-by-module status |

---

## Outstanding dependencies

| # | Item | Effect |
| --- | --- | --- |
| D-01 | A reachable SQL Server instance and the PHP driver | The application has not been executed against SQL Server |
| D-02 | Azure app registration for OneDrive | The Graph driver is written but untested against a live tenant; the demonstration driver is active |
| D-03 | The real HHT payload specification | Our contract is documented; a mapping would live in one service method |
| D-04 | Confirmation of report columns | Chosen by us; changed by editing one definition |

Detail in [docs/15](docs/15-ASSUMPTIONS-DEPENDENCIES.md).
