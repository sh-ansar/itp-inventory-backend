# Inventory: facilities, custodians, equipment types and warehouses

## Single source of truth

An Inventory company owns its own **facility tree**, **responsible custodians**, **equipment categories** and **warehouse stock catalog**. All HTTP endpoints are protected by Inventory's own same-origin session; GET requires company membership, mutations require `company_admin` or `inventory_manager`. `viewer` cannot write. Routes are under `/api/v1/companies/{company}` (reverse-proxied under `/inventory-api`).

The historical Inventory Vue 2 system and its backend remain independent: this implementation does **not** purport to migrate actual production inventory data or to replace legacy 1C sync. Import must later map legacy immutable IDs, accounting locations, actual rooms, asset identifiers, and historical custodians; verify counts and rollback.

### Facilities

Hierarchy: `site -> building -> floor -> room`, plus pavilion, warehouse, zone and shelf. A root may be site, building or warehouse. The server enforces permitted parent/child types, depth bounds, tenant-scoped composite foreign keys, cycle prevention and optimistic `version` edits. Nodes have company-unique `code`, display `name`, optional address/floor, optional custodian, and `sort_order`. Creation and modification generate versioned audit events.

Routes:

- GET, POST `/facility-nodes`: list all up to 3,000 company nodes; create
- GET, PUT `/facility-nodes/{node}`: detail including descendants; update
- GET `/facility-nodes/{node}/events`: edit history

The Vue 3 `FacilitiesPage` supports a hierarchy sidebar, incremental drilldown diagram, horizontal panning, zoom, drag-to-reparent, and a table with filters. The view shows equipment in the selected subtree using backend-side company filters; it is **not** an architectural floor-plan editor (no XY drawings or measured room polygons). Assets have `facility_node_id` and `responsible_person_id` in addition to the distinct accounting/legacy physical location fields.

### Responsible people

- GET, POST `/responsible-people`: paginated, searchable list; add a manual custodian
- PUT `/responsible-people/{person}`: change a manual record under optimistic lock

Company-unique code, optional email, role/position and active flag; an active person holding active equipment cannot be deactivated until reassignment. Records have `source=manual|hrm` and `external_employee_id` for future HRM reconciliation. HRM-based records are read-only here, and **no live HRM employee sync or Portal SSO is implemented** in this phase. Do not copy HRM passwords or query HRM's PostgreSQL tables directly.

### Equipment types

Reuses **asset_categories** instead of creating a second competing directory. Normalized, company-unique names protect against duplicates. `POST /equipment-presets/initialize` idempotently adds up to 12 baseline equipment types, skipping collisions. `PUT /categories/{category}` edits a type and chooses from supported Lucide icon keys. `POST /categories/{category}/icon` accepts PNG/WebP max 512 KiB, validates dimensions and re-encodes to 128x128 PNG on private storage; `GET /categories/{category}/icon` is company-authenticated. Never expose disk paths or accept SVG/HTML as uploaded icons.

### Warehouses

A `facility_node` of type `warehouse` also serves as a stock location. `stock_items` is company-unique by SKU code and stores a unit and optional equipment category. `stock_balances` has unique (company, item, warehouse), with company-composite foreign keys. `stock_transactions` records immutable receipts, issues and transfers with reason, actor, before/after balances, and a single transaction per operation. On issue or transfer, stock cannot drop below zero. Rows are locked in deterministic order to prevent reciprocal transfer deadlocks.

- GET, POST `/stock-items`
- GET `/stock-balances?warehouse_node_id=...`
- GET, POST `/stock-transactions`

Quantity uses fixed milliunits to preserve three decimals for length/mass/volume without floats. `pcs` and `pack` require integer quantities. Serialized hardware (fixed assets) remains in **assets**; commodity stock counts remain in **stock_items**. There is no automatic creation of an asset on receipt or automatic destruction on issue; the serial asset-commissioning workflow is deferred.

### Facility-scope stocktakes

`POST /inventory-sessions` can accept `facility_node_id` to capture all active assets in the selected node and its descendants; `scope_facility_node_name` and every expected node/name are immutable snapshots. Item counting and QR scan accept `actual_facility_node_id` for assets with expected mapped node; discrepancies compare expected versus actual *facility IDs*. Legacy assets without a node continue to use actual physical location. CSV export includes both old and mapped physical locations. Inventory reconciliation **does not silently move live assets**; the separate audited asset edit/movement must record a change.

No deletion of an occupied node; composite keys and `restrictOnDelete` protect stock items, assets and historical check snapshots. The API prevents cross-company access at both Laravel validation and database foreign-key layers.

## Verification and rollout boundaries

- Laravel PHPUnit includes tenant isolation, hierarchy edits, cycle tests, idempotent templates, icon upload, warehouse stock operations and facility-scope count/recount.
- Isolated PostgreSQL 16 migrates/rolls back/re-migrates on a disposable database, with composite FK checks, then drops the database.
- Vue typecheck, unit tests, production Vite compilation; local Playwright real Chromium QA on synthetic Inventory test company for creation, stocktake and responsive layouts.
- Existing Portal and HRM serving directories, services, databases and the live Inventory release are **not changed** as part of the isolated branch tests.

**Not complete yet:** Portal one-use-code SSO integration; HRM employee sync; 1C bridge; legacy data migration/reconciliation; warehouse receiving documents and asset commissioning; uploaded architectural floorplans and plotted XY positions; arbitrary batch select/filter of thousands of facilities; XLSX import/report attachments. These must not be called released features until tested.
