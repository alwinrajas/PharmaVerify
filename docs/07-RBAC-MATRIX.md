# 07 — RBAC Matrix

Authorisation is **permission based**. A role is a named collection of
permissions, so what a role can reach is changed by editing its permissions —
not by editing code. Permissions are defined once in
`backend/app/Support/Permissions.php` and seeded by `RolePermissionSeeder`.

**Enforcement is server side.** Every controller action checks its permission and
returns `403` if it is not held. The frontend hides menu entries and buttons, and
guards routes, purely as a convenience — bypassing it changes nothing.

---

## 1. Roles

| Role | Intended for | Shop reach |
| --- | --- | --- |
| **Administrator** | System owner | All shops |
| **Supervisor / Authorised User** | Operations lead | All shops |
| **Shop User / Shop Keeper** | Branch staff | Only the shops assigned to them |

Shop reach is decided by the `shops.view_all` permission. A user without it sees
only the shops linked to them through `shop_user`; the restriction is applied in
the query, not in the interface.

---

## 2. Module matrix

| Module | Administrator | Supervisor | Shop User |
| --- | --- | --- | --- |
| Dashboard | Full | Full | Assigned shops |
| Shops | Full | View, create, edit | View (assigned only) |
| Items | Full | View, create, edit | View |
| HHT Devices | Full | View, create, edit | View |
| Stock Import | Full | Full | No |
| Item Stock | Full | Full | View (assigned only) |
| HHT Submissions | Full | Full | View (assigned only) |
| Stock Audit | Full | Full | View (assigned only) |
| Verification | Full | Full | View only — cannot edit |
| Variance | Full | Full | View (assigned only) |
| Stock Adjustment | Full | Full | View only — cannot post |
| Stock Take | Full | Full | View and create |
| Reports | Full | Full | View and export (assigned only) |
| Final Output | Full | Full | View only |
| Share to OneDrive | Yes | Yes | No |
| User Management | Full | No | No |
| Settings | Full | View only | View only |
| Activity Log | Full | Full | No |

---

## 3. Permission matrix

| Permission | Administrator | Supervisor | Shop User |
| --- | :---: | :---: | :---: |
| `shops.view` | ✓ | ✓ | ✓ |
| `shops.view_all` | ✓ | ✓ | — |
| `shops.create` | ✓ | ✓ | — |
| `shops.edit` | ✓ | ✓ | — |
| `shops.delete` | ✓ | — | — |
| `items.view` | ✓ | ✓ | ✓ |
| `items.create` | ✓ | ✓ | — |
| `items.edit` | ✓ | ✓ | — |
| `items.delete` | ✓ | — | — |
| `devices.view` | ✓ | ✓ | ✓ |
| `devices.create` | ✓ | ✓ | — |
| `devices.edit` | ✓ | ✓ | — |
| `devices.delete` | ✓ | — | — |
| `stock.view` | ✓ | ✓ | ✓ |
| `stock.import` | ✓ | ✓ | — |
| `hht.view` | ✓ | ✓ | ✓ |
| `hht.submit` | ✓ | ✓ | — |
| `audits.view` | ✓ | ✓ | ✓ |
| `verification.edit` | ✓ | ✓ | — |
| `variance.view` | ✓ | ✓ | ✓ |
| `adjustments.view` | ✓ | ✓ | ✓ |
| `adjustments.create` | ✓ | ✓ | — |
| `stocktake.view` | ✓ | ✓ | ✓ |
| `stocktake.create` | ✓ | ✓ | ✓ |
| `reports.view` | ✓ | ✓ | ✓ |
| `reports.export` | ✓ | ✓ | ✓ |
| `finaloutput.view` | ✓ | ✓ | ✓ |
| `finaloutput.generate` | ✓ | ✓ | — |
| `onedrive.share` | ✓ | ✓ | — |
| `users.manage` | ✓ | — | — |
| `settings.manage` | ✓ | — | — |
| `activity.view` | ✓ | ✓ | — |

The Supervisor role is defined as “every permission except delete on master
data, user management and settings”, so a permission added later is granted to
Supervisors unless it is deliberately excluded.

---

## 4. Where each permission is enforced

| Permission | Endpoint(s) |
| --- | --- |
| `shops.*` | `/shops` and `/shops/{shop}` |
| `items.*` | `/items` and `/items/{item}` |
| `devices.*` | `/devices` and `/devices/{device}` |
| `stock.view` | `/item-stocks`, `/stock-imports` (read) |
| `stock.import` | `POST /stock-imports` |
| `hht.view` | `GET /hht/submissions` |
| `audits.view` | `/audits`, `/audits/{audit}/lines`, `GET /verification` |
| `verification.edit` | `PATCH /verification/lines/{line}`, `POST /audits/{audit}/verify` |
| `variance.view` | `/variance`, `/variance/summary` |
| `adjustments.view` | `GET /adjustments` |
| `adjustments.create` | `POST /adjustments` |
| `stocktake.view` | `GET /stock-takes`, `/stock-takes/candidates` |
| `stocktake.create` | `POST`, `PUT`, `DELETE /stock-takes` |
| `reports.view` | `GET /reports`, `GET /reports/{report}` |
| `reports.export` | `GET /reports/{report}?format=xlsx\|pdf` |
| `finaloutput.view` | `GET /final-outputs`, download |
| `finaloutput.generate` | `POST /final-outputs` |
| `onedrive.share` | `POST /final-outputs/{id}/share-onedrive` |
| `users.manage` | `/users/*` |
| `settings.manage` | `PUT /settings` |
| `activity.view` | `GET /activity-log` |

---

## 5. Shop scoping

The `ScopesToUserShops` trait adds a `visibleTo($user)` scope, applied on:

`item_stocks` · `audits` · `audit_lines` · `hht_submissions` ·
`stock_adjustments` · `stock_takes` · `final_outputs` · `devices` ·
`stock_imports` · every report query.

A restricted user reaching a record outside their shops receives `403`, not an
empty result, so the refusal is explicit.

---

## 6. Additional guards

| Guard | Behaviour |
| --- | --- |
| Last administrator | The last active administrator cannot be demoted or deactivated (`422`) |
| Inactive account | Cannot sign in; existing tokens are revoked on deactivation |
| Password reset | Revokes the user's tokens, forcing a fresh sign-in |
| Closed audit | Cannot be edited, whatever permission the user holds |
| Already adjusted line | Cannot be adjusted a second time |

---

## 7. Changing the matrix

1. Add the constant to `Permissions` and place it in a group.
2. Add or remove it in `Permissions::roleMatrix()`.
3. Run `php artisan db:seed --class=RolePermissionSeeder`.
4. Check it in the controller action that should require it.
5. Reference it from the sidebar entry or route guard in the frontend.
6. Update this document.
