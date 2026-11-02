# ITP Inventory — detailed printable forms v1

All output is generated from the **authenticated user's company**, not from caller-supplied company names. The endpoint is `GET /inventory-api/api/v1/companies/{company}/reports/forms/{kind}` under `auth:sanctum` and `inventory.active-account`.

## Forms

| `kind` | Title (RU) | Source | Important distinction |
| --- | --- | --- | --- |
| `asset-register` | Реестр имущества | assets + categories + facility nodes + custodians | Excludes archived assets, retains active, written-off, disposed |
| `by-facility` | Ведомость имущества по объектам | Asset register | Groups by exact facility, unassigned separately |
| `by-custodian` | Ведомость по МОЛ | Asset register | Groups by responsible person, unassigned separately |
| `warehouse-balances` | Ведомость складских остатков | stock_balances + stock_items + facility_nodes | Displays quantities per **unit**, never sums unlike units |
| `stocktake-act` | Акт/сличительная ведомость | Single inventory session and its items | Requires company-scoped `session_id`; shows expected / actual and discrepancies; unfinished sessions print with preliminary warning |
| `disposal-register` | Реестр документов на списание | asset_disposal_cases + assets | Includes draft/submitted/approved/disposed cases, approvals are never inferred from the printout |

## Query filters

- `status`: one of active / written_off / disposed, where meaningful.
- `facility_node_id` and `responsible_person_id`: exact company-owned UUIDs, when meaningful.
- `warehouse_node_id`: company-owned **warehouse** UUID, only warehouse report.
- `session_id`: required company-owned session UUID, stocktake act.
- `disposal_status`: draft / submitted / approved / disposed, only disposal register.
- `search`: max 120 characters, matches inventory number / name for asset and disposal reports, item code / name for warehouse. Percent, underscore and exclamation are literal, never SQL wildcard operators.
- Print forms use the current asset filters from the Reports page, with additional per-form choices.

## Security, output, and acceptance

- The API returns **JSON data**, not a public PDF URL. Browser prints this data into an isolated HTML document with A4 CSS (landscape registers, portrait reconciliation act). Save as PDF using the browser print dialogue.
- Every lookup includes company scope, every foreign selected reference must belong to the requested company. Viewer membership can read and print but cannot mutate data.
- There are **no** changes to asset, stock, stocktake, approval, or signature fields, and no new database migration for this module.
- The print source limits records to **2,000**; more than that returns HTTP 413 rather than silently omitting entries. Narrow the facility / custodian / warehouse / status / search filters and retry.
- Document number and signature fields are intentionally blank; creating a printout **does not sign or approve** any asset disposal or stocktake.
- The UI escapes untrusted values, sets a Content Security Policy on the printable HTML, reuses the current user's locale RU/EN/GE and uses the server company name.
- Browser acceptance checks all six forms, API counts, header alignment, cross-company isolation, printer views, languages and responsive layouts, followed by full Inventory Playwright regression.

## Rollout order

1. Merge and deploy Backend to a separate release directory; no migration required. Verify existing active Inventory and unrelated portal services remain healthy.
2. Deploy Frontend with reversible junction switch. Verify new JS/CSS hashes, 9 protected routes, session behavior, print act, HTTP/JS errors.
3. Do not enable the 1C integration; `INVENTORY_INTEGRATIONS_ENABLED=false` remains unchanged.
