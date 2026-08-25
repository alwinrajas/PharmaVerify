# 06 — Screen Flow

## 1. Navigation

```
Login
  ↓
Dashboard
  │
  ├── Master
  │     ├── Shops
  │     ├── Items
  │     ├── HHT Devices
  │     └── Item Stock Import
  │
  ├── Stock Verification
  │     ├── Item Stock
  │     ├── HHT Submissions ──► HHT Simulator
  │     ├── Stock Audit ──────► Audit Detail
  │     ├── Verification
  │     ├── Variance
  │     ├── Stock Adjustment
  │     └── Stock Take
  │
  ├── Output
  │     ├── Reports ──────────► Report Viewer (9 reports)
  │     └── Final Output ─────► Share to OneDrive
  │
  └── Administration
        ├── Users
        ├── Activity Log
        └── Settings
```

Sidebar entries are hidden when the user lacks the permission, and the route is
guarded as well, so typing the URL does not get past it. The API checks again
regardless.

## 2. The business path through the screens

```
Shops → Items → HHT Devices        (set up once)
        ↓
Item Stock Import                  (per shop, per stock file)
        ↓
Item Stock                         (confirm what the system now holds)
        ↓
HHT Submissions                    (a completed count arrives)
        ↓
Stock Audit → Audit Detail         (see the count against the system)
        ↓
Verification                       (correct and confirm the count)
        ↓
Variance                           (what disagrees, and by how much)
        ↓
Stock Adjustment  /  Stock Take    (fix the stock, or record what isn't in it)
        ↓
Reports                            (evidence, exported to Excel or PDF)
        ↓
Final Output → Share to OneDrive   (close it out)
```

---

## 3. Screens

### Login

| | |
| --- | --- |
| Purpose | Sign in |
| Entry | Application root when not signed in |
| Roles | Everyone |
| Fields | Email, password |
| Actions | Sign in; fill a demonstration account |
| Validation | Both fields required; email must be an address |
| Success | Redirect to the dashboard |
| Error | Inline alert: wrong credentials, or a deactivated account |

### Dashboard

| | |
| --- | --- |
| Purpose | Where the verification currently stands |
| Roles | All, scoped to the shops the user may see |
| Content | Eight metric cards, variance breakdown, recent submissions / audits / adjustments, quick actions |
| Actions | Every card and list row navigates to the screen behind it |
| Empty state | Each panel says what would appear there and why it is empty |

### Shops · Items · HHT Devices

| | |
| --- | --- |
| Purpose | Master data |
| Roles | View by permission; create and edit by permission |
| Fields | Shops: code, name, address, city, contact, status. Items: product code, barcode, description, generic name, manufacturer, UOM, price, status. Devices: shop, device code, description, serial, status |
| Actions | Add, edit, activate / deactivate, search, filter, sort, paginate |
| Validation | Shop code and product code unique; device code unique **within its shop** |
| Success | Snackbar confirmation and the list refreshes |
| Error | Inline in the dialog, e.g. “This shop code is already in use.” |

The Devices screen carries a standing note that a submission is identified by
Shop + Device + Audit Number.

### Item Stock Import

| | |
| --- | --- |
| Purpose | Load a shop's system stock from Excel |
| Roles | `stock.import` to import; `stock.view` to see history |
| Fields | Shop, file (.xls/.xlsx) |
| Flow | Select file → confirm replacement → upload with progress → summary |
| Confirmation | An explicit dialog: this removes the shop's current stock |
| Success | Summary panel: total, imported, failed, previous records replaced |
| Error | A readable message; on a fatal error nothing is changed |
| Errors detail | Failed count is clickable and lists row, column, value and reason |

### Item Stock

| | |
| --- | --- |
| Purpose | The system stock each shop holds |
| Roles | `stock.view`, scoped to assigned shops |
| Columns | Shop, product, batch, expiry, shelf, UOM, system quantity, price, status |
| Filters | Search, shop, status, expiry range, “expiring soon” |
| Summary | Record count, total quantity, items expiring within 90 days |

### HHT Submissions

| | |
| --- | --- |
| Purpose | Completed counts received from the devices |
| Roles | `hht.view` |
| Columns | Received, shop, device, audit number, audit date, items, submission id, status |
| Filters | Search, shop, device, status, date range |
| Actions | Open the audit a submission created; open the Simulator |
| Note | A duplicate submission is shown as *Duplicate Ignored* rather than hidden |

### HHT Simulator

| | |
| --- | --- |
| Purpose | Send a completed count without a physical device |
| Roles | `hht.view` |
| Fields | Shop, device, audit number, audit date, counted by, submission id |
| Actions | Load 8 products, load the full shelf, apply a variance spread, add an item not in the stock file, adjust any physical quantity, **Share / Submit** |
| Behaviour | Nothing is sent until Share / Submit, exactly like the device |
| Success | Banner naming the audit created, with a link to open it |
| Duplicate | Re-sending the same submission id reports *Duplicate submission ignored* |

### Stock Audit

| | |
| --- | --- |
| Purpose | The audits available to verify |
| Roles | `audits.view` |
| Columns | Audit (number, shop, device), shop name, audit date, submitted, items, variance count, status |
| Filters | Search, shop, device, status, date range |
| Actions | Click a row to open the detail |

### Audit Detail

| | |
| --- | --- |
| Purpose | The count itself, line by line |
| Roles | `audits.view`; editing needs `verification.edit`; adjusting needs `adjustments.create` |
| Header | Audit number, shop, device, counted by, audit date, submitted, status |
| Summary | Lines counted, short, excess, matched, pending verification, net variance |
| Columns | Product, batch, expiry, shelf, system, physical, variance, verification, adjustment |
| Filters | Search, variance direction, verification status, adjustment status |
| Actions | Verify or correct a line; post an adjustment; record a stock take for an unknown item; verify all lines; generate the final output |
| Warning | A banner appears when the count includes products not in the stock file |

### Verification

| | |
| --- | --- |
| Purpose | The verification worklist across audits |
| Roles | `audits.view`; editing needs `verification.edit` |
| Default | Filtered to lines still pending |
| Columns | Audit, product, system, physical, variance, verification, adjustment |
| Actions | Verify or correct a line; post an adjustment; open the audit |
| Dialog | System, physical and the resulting variance are shown as the quantity is typed |
| Audit trail | Every change records user, time, field, old value and new value |

### Variance

| | |
| --- | --- |
| Purpose | Where the count and the system disagree |
| Roles | `variance.view` |
| Summary | Lines in scope, short lines and units, excess lines and units, matched lines, net variance, awaiting adjustment |
| Filters | Search, variance direction, shop, device, audit number, date range |
| Actions | Adjust one line, adjust a selection, export the variance report to Excel or PDF |

### Stock Adjustment

| | |
| --- | --- |
| Purpose | The history of adjustments already applied |
| Roles | `adjustments.view`; posting needs `adjustments.create` |
| Columns | Adjusted (when, by whom), audit, product, quantity change (before → after), variance, reason |
| Filters | Search, shop, date range |
| Actions | Go to Variance to post an adjustment; export to Excel |
| Note | A standing banner states that adjustments take effect on save, with no approval step |

**Adjustment dialog:** states plainly that the post is immediate, lists each
line with its before and after quantity, and takes an optional reason.

### Stock Take

| | |
| --- | --- |
| Purpose | Physical stock the shop's stock file does not contain |
| Roles | `stocktake.view`; recording needs `stocktake.create` |
| Highlight | A panel lists counted products missing from the stock file, each with a Record button |
| Fields | Shop, barcode, product code, description, physical quantity, UOM, batch, expiry, shelf, remarks |
| Validation | Shop, description and quantity required; quantity cannot be negative |
| Success | “Stock take recorded successfully. The item master has not been changed.” |

### Reports

| | |
| --- | --- |
| Purpose | The nine reports |
| Roles | `reports.view`; exporting needs `reports.export` |
| Index | A card per report with its description and column count |
| Viewer | Filters declared by the report, sortable columns, pagination, summary figures |
| Actions | Excel, PDF, back to all reports |

### Final Output

| | |
| --- | --- |
| Purpose | Produce and share the closing file |
| Roles | `finaloutput.view`; generating needs `finaloutput.generate`; sharing needs `onedrive.share` |
| Columns | Generated, audit, file, records, verification, adjustment, OneDrive status |
| Actions | Generate for an audit, download, **Share to OneDrive**, retry a failed upload |
| Confirmation | The share dialog states this is the only point at which the file leaves the application |
| States | Not uploaded → Uploading (progress) → Uploaded, or Failed with the reason and a Retry |

### Users

| | |
| --- | --- |
| Purpose | Accounts, roles and shop assignment |
| Roles | `users.manage` |
| Columns | User, role, assigned shops, employee code, last login, status |
| Actions | Add, edit, reset password, activate / deactivate |
| Behaviour | The role's permissions are listed as the role is chosen; an empty shop selection means “all shops” |
| Guard | The last active administrator cannot be demoted or deactivated |

### Activity Log

| | |
| --- | --- |
| Purpose | The audit trail |
| Roles | `activity.view` |
| Columns | When, user, module, action, detail |
| Filters | Search, module, date range |

### Settings

| | |
| --- | --- |
| Purpose | Application settings and integration status |
| Roles | Viewing is open to signed-in users; saving needs `settings.manage` |
| Groups | General, Stock, Verification, OneDrive |
| Integrations | OneDrive driver, whether credentials are configured, destination folder — read only |
| Note | Database credentials and integration secrets are never returned by the API |

---

## 4. States used on every screen

| State | Treatment |
| --- | --- |
| Loading | Table dims with a spinner; the layout does not jump |
| Empty | Icon, a sentence explaining why, and the action that would fix it |
| Error | Icon, a readable message, and a Try again button |
| Success | Snackbar, bottom right |
| Permission | Sidebar entry hidden, route guarded, API refuses regardless |
