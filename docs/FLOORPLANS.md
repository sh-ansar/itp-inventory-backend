# Floorplan editor: contract and release notes

## Geometry and the facility hierarchy

Each company-owned `facility_node` can optionally have **one** `facility_plan`. An absent plan is read-only and renders as a default 1200 × 800 canvas (version 0) until the first authorized change. Every placement is either a **direct child facility node** (floor/room/warehouse/zone) or an **active serialized asset whose current `facility_node_id` equals the plan node**. Subtree assets appear on the plan of their immediate room, never as a duplicate marker on every ancestor. Opening a child plan navigates into that room. Moves of an icon do not change the asset register, its custodian or its accounting/physical location.

Coordinates and sizes are integer virtual canvas units, with grid snap at 10-unit intervals on pointer drag. The browser scales the virtual canvas to 50–150% and contains horizontal overflow inside the editor rather than expanding the application. Room rectangles may be dragged and resized. Asset cards may be moved. Arrow keys move a focused item (10 units, Shift+arrow 1 unit). Layout saving is automatic on pointer release or arrow movement, not on every pointermove.

A plan background can be uploaded as PNG/WebP max 2 MiB with 100–2500 pixel source dimensions. On the server it is decoded and **re-encoded to private PNG**, max 1600x1600, max 5 MiB output. Uploaded SVG/HTML is not accepted; GET requires membership in the owning company. Image dimensions are stretched to the selected virtual canvas bounds, so align ratio with the original architectural plan. The editor is a visual placement board and not a measured architectural/CAD drawing.

## API

All routes below are relative to `/api/v1/companies/{company}/facility-nodes/{node}/plan` and externally reverse-proxied via `/inventory-api`:

| Action | Endpoint | Privilege |
| --- | --- | --- |
| Read snapshot | GET `/` | member |
| Change canvas dimensions | PUT `/` `canvas_width`, `canvas_height`, `expected_version` | manager/admin |
| Add symbol | POST `/placements` with exactly one of `asset_id`, `child_node_id`, plus `x,y,width,height,expected_version` | manager/admin |
| Move or resize symbol | PATCH `/placements/{placement}` with `x,y,width,height,expected_version` | manager/admin |
| Remove symbol | DELETE `/placements/{placement}` with `expected_version` | manager/admin |
| Upload background | POST multipart `/background` with `file,expected_version` | manager/admin |
| Clear background | DELETE `/background` with `expected_version` | manager/admin |
| Read private image | GET `/background` | member |
| Audit trail (last 100) | GET `/events` | member |

All writes take a database transaction, **lock the owning facility node first**, check company membership and plan version and increment it exactly once after a mutation, emitting an immutable event with actor/time/before/after. A stale revision returns HTTP 409. An invalid rectangle or out-of-bounds item returns HTTP 422. Cross-company IDs are rejected by API company scopes and composite PostgreSQL foreign keys; a placement cannot represent both child and asset (Postgres XOR CHECK). Indexes enforce uniqueness of a node or asset per plan.

Deleting a placement only removes it from the visual layout. Historical plan events remain. The database does not delete asset records when a marker is removed. When an asset is archived or assigned elsewhere, it disappears from that plan's current visible list; the placement row and history stay to preserve audit. Cleanup of orphaned rows as explicit auditable events is future work.

## UI and QA gates

- Entry point: `Карта объектов` → select building/floor/room → `План объекта`.
- Page: `/inventory/facilities/:nodeId/plan` (Vue 3, locale RU/EN/GE, light/dark tokens).
- Playwright Chromium: build node hierarchy, create asset, place room and asset, pointer drag, resize, refresh and compare coordinates, keyboard move, remove placement, change canvas size, upload and clear background, inspect history, viewer read-only, viewport width 360/390/768/1024/1440.
- Backend Laravel: 5 `FacilityPlanTest` methods / 95 assertions, company boundaries, viewer denial, target ownership, conflict, duplicates, bounds and image upload. Full backend suite from `main` remains green.
- PostgreSQL 16: disposable `inv_plan_qa_...` database, migration, eight targeted foreign-key/check/unique constraints, rollback, remigration, guaranteed drop; no Portal/HRM or Inventory production database involved.
- Before any production rollout: backup Inventory's own DB and uploaded images, check PHP GD/Git permissions, CSRF/session path isolation, test icon/background GET through Apache proxy, restart only isolated Inventory service, record rollback release and smoke-test all other systems independently.
