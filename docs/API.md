# Inventory API v1

All paths are relative to Inventory's isolated backend base. Public paths are planned as /inventory-api/ (backend) and /inventory/ (frontend).

## Session

- GET /sanctum/csrf-cookie issues INVENTORY-XSRF-TOKEN.
- POST /auth/login {email,password}: independent Laravel web session, limited to 5 attempts/min. Disabled by default by INVENTORY_BROWSER_LOGIN_ENABLED=false.
- GET /auth/me returns {id,name}; POST /auth/logout invalidates Inventory session and rotates CSRF token.
- GET /api/v1/health returns {status,service,browser_login}; public.
- GET /api/v1/me and /api/v1/companies require active authenticated user.

## Scoped registry

Membership is mandatory for each company. Non-members get 404. Viewers can read; inventory_manager and company_admin can create/update; only company_admin can archive.

- GET /api/v1/companies/{company}/assets?search=...&status=active&sort=inventory_number&direction=asc&per_page=25&page=1
  - Sort whitelist: inventory_number, name, created_at, updated_at; per_page 10,25,50,100. Search includes number/name/serial. Laravel JSON resources return data, links, meta.
- POST /api/v1/companies/{company}/assets: required inventory_number/name, optional serial_number/description/category_id/accounting_location_id/physical_location_id; 201 {data:asset}.
- GET /api/v1/companies/{company}/assets/{asset}: resource. Foreign company's UUID -> 404.
- PUT/PATCH /api/v1/companies/{company}/assets/{asset}: editable fields and required integer version. Stale version -> 409. Request-provided tenant, status, IDs and version changes are not accepted.
- DELETE /api/v1/companies/{company}/assets/{asset}: {version,reason}; soft archive with reason 5+ chars, company_admin only. The inventory number remains reserved.
- GET /api/v1/companies/{company}/assets/{asset}/events: paginated and ordered by version descending; remains available after archive.

Each mutation and audit event are written in one DB transaction. Cross-tenant category/location IDs are checked by validator and composite foreign keys.

## QR lookup (authenticated)

- GET /api/v1/companies/{company}/assets/scan/{publicId} -> {data:asset}. Uses a random globally unique public_id UUID and resolves only inside a company the current user may access. Returns 404 for foreign, archived, malformed or unknown tokens.
- The QR payload is a same-origin frontend link, /inventory/scan/{publicId}. Opening the link requires login. A public UUID is **not** a bearer token.

## Physical movements

- GET /api/v1/companies/{company}/assets/{asset}/movements -> paginated records, newest version first, including after soft archive.
- POST /api/v1/companies/{company}/assets/{asset}/movements:
  {version:number,to_location_id:uuid,reason:string (5..1000)}
  -> HTTP 201 {data:{asset,movement}}.
  - Requires inventory_manager or company_admin. No write permission for viewer, no other-company access.
  - Asset must be active; target must be a **different** physical location belonging to this company.
  - Asset row is locked in the DB transaction; version mismatch or written-off asset -> 409. Same-location/wrong-kind/wrong-company target -> 422.
  - Transaction updates physical_location_id, increments version, writes asset_movements and asset_events with reason/actor snapshots.
  - accounting_location_id is **not** changed by physical movements. Accounting movement must use a separate controlled workflow later.
  - Historical from/to location names are preserved as text snapshots even if a location is renamed.

## Dictionaries

- GET /api/v1/companies/{company}/reference-data -> categories, accounting_locations, physical_locations.
- POST /api/v1/companies/{company}/categories -> {code,name}, unique code within company.
- POST /api/v1/companies/{company}/locations -> {kind:"accounting"|"physical",code,name}, unique by company, kind and code.

## State invariants

Active, written_off and disposed are different business statuses. This initial CRUD creates assets with status active; regular updates cannot change business status. Status transition and movement endpoints require separate controlled workflows in the next phase.

## Errors and tracing

HTTP errors retain Laravel message/errors and add stable code values such as UNAUTHENTICATED, FORBIDDEN, NOT_FOUND, CONFLICT and VALIDATION_FAILED. API responses include server-issued X-Request-ID.