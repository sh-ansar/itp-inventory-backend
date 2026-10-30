# Inventory isolated release gates

This branch is deliberately not production-ready. All conditions below must pass before release:

1. Frontend and backend each published in separate intended GitHub repos. No secrets, vendor, build output, synthetic test DB or generated keys tracked.
2. Provision PostgreSQL under new Inventory-only service/database role. Verify clean migrations, tenant-scoped FK constraints, rollback and restore from a disposable backup. Never use HRM/Portal production DB.
3. Isolated Apache alias /inventory/ and backend proxy /inventory-api/ with independent PHP worker; no collisions with Portal, HRM, Unityre and ETP.
4. Review APP_ENV=production, APP_DEBUG=false, HTTPS, trusted proxy headers, SESSION_SECURE_COOKIE=true, SESSION_HTTP_ONLY=true, same-site session and unique session/XSRF cookie names.
5. Only then enable INVENTORY_BROWSER_LOGIN_ENABLED. QA login/logout, CSRF enforcement, blocking and revocation, expired sessions, parallel Portal/HRM sessions.
6. Tenant/role matrix (nonmember, viewer, inventory_manager, company_admin) and injection of foreign reference IDs; pagination/search/optimistic edit conflict.
7. Test authorized concurrency, rollback on failure, 10–20 simultaneous operations, and restore from snapshot.
8. Mobile/tablet/desktop at 360/390/768/1440 widths and RU/EN/GE, dark/light, dialogs, keyboard focus, long names and zero page overflow.
9. Verify QR label print dimensions, scanner link routing, company switching and 404 for foreign/archived assets; reject stale transfer versions, test movement history after archive and cross-company foreign keys.
10. Reconcile the legacy Inventory functional flows and actual data: QR, movements, inventory counts, responsible persons, locations, disposal, 1C and exports. Do not claim real migration without source DB mapping.
11. Deploy with an explicit rollback path, then smoke-test /inventory/ and /inventory-api/, inspect logs, and confirm Portal/HRM are unaffected.

Known gaps: real legacy data transfer, inventory stocktakes, accounting transfers and movement approvals, in-app camera decoder, bulk QR printing, exports/imports, document attachments and SSO. Leave production configuration untouched until approved.