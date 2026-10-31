# Stocktake sessions — Inventory Laravel

## Scope

This phase migrates the one-company fixed-assets and physical-location stocktake flow from the legacy Inventory application. Responsible persons from HRM, cross-company batch checks, 1C synchronisation, XLSX and report attachments are deferred.

- Separate company per check; viewer reads/exports, inventory_manager creates/counts/completes, company_admin additionally reopens.
- On creation the API takes a frozen snapshot of ACTIVE assets: all company assets, a selected physical location, or explicitly selected asset IDs (max 1000). Mixed/foreign/archived selections fail atomically.
- Each line retains the expected inventory number, name and physical location at start. Later changes to live assets do not silently alter expectations.
- Count records actual physical location and user/time; mismatched location is a discrepancy. A recount requires reason and increments versions but not counted total twice.
- Unchecked assets remain missing; completion is allowed while some remain unchecked, reflected in the report.
- All mutations write immutable audit events transactionally. Stocktake session row and item row are locked in a consistent order; version mismatches return HTTP 409.
- QR accepts only the opaque public UUID in the scoped company and session. Second scan returns 409; use the recount form with a reason.
- Closing and reopening retain every prior count and event. Reopen is company_admin only with 10+ character reason.
- Counts DO NOT change accounting or physical location in the asset register; corrections require a separate audited movement.

## API (Inventory own Sanctum session, company scoped)

GET /api/v1/companies/{company}/inventory-sessions
POST /api/v1/companies/{company}/inventory-sessions: name, optional scope_location_id or asset_ids
GET /api/v1/companies/{company}/inventory-sessions/{session}
GET /api/v1/companies/{company}/inventory-sessions/{session}/items: search, counted=0|1, discrepancy=0|1, page/per_page
PUT /api/v1/companies/{company}/inventory-sessions/{session}/items/{item}: version, actual_location_id, optional note/reason
POST /api/v1/companies/{company}/inventory-sessions/{session}/scan: public_id, actual_location_id, optional note
POST /api/v1/companies/{company}/inventory-sessions/{session}/complete: version
POST /api/v1/companies/{company}/inventory-sessions/{session}/reopen: version, reason
GET /api/v1/companies/{company}/inventory-sessions/{session}/events
GET /api/v1/companies/{company}/inventory-sessions/{session}/report.csv

CSV uses a UTF-8 BOM, semicolon separator, same-origin session authorization and spreadsheet formula sanitization.

## Verification

- 33 Laravel PHPUnit tests / 419 assertions, including nine new stocktake feature tests.
- PostgreSQL 16: all seven migrations, foreign-key constraints, migration rollback and remigration tested on disposable Inventory-only database at localhost port 55434. The disposable database was dropped, production inventory_db was not modified by this test.
- New frontend supports RU/EN/GE, responsive list and detail views, manual counts and recounts, QR string scan, status/discrepancy filters, progress, journal and CSV report.
- Deferred: in-app camera decoder, HRM responsible person selector, cross-company session aggregation, report uploads, financial write-offs, 1C.

Release only after new GitHub CI, isolated browser QA, production database backup, and verified rollback. The existing Portal and HRM are independent services and must remain untouched.
