# ITP Inventory backend foundation

Independent Laravel 12 app for Inventory. Do not deploy on HRM or Portal runtime. Own browser session using Sanctum; **no SSO yet**.

- Browser endpoints: POST /auth/login, GET /auth/me, POST /auth/logout. The login feature is off by default `INVENTORY_BROWSER_LOGIN_ENABLED=false`. Laravel CSRF must remain enabled.
- API: GET /api/v1/health (public), GET /api/v1/me (auth), GET and POST /api/v1/companies/{company}/assets (auth + membership).
- Permissions: company_admin/inventory_manager can create assets; other company members can list. No anonymous registration.
- Database: separate Inventory database on isolated PostgreSQL, local SQLite only for automated tests.
- Deployment: separate frontend /inventory/ and API /inventory-api/ paths; do not touch the production Portal/HRM vhosts before approval.
- Next iteration: forms, account activation, audit writer, stronger company access policy, inventory workflows, QR, and privacy-aware company directory integration.