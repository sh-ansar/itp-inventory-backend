# ITP Inventory Backend

Isolated Laravel 12 + PHP 8.4 Inventory API. Based on architectural conventions inspected in sh-ansar/itp-hrm-backend, but **not** reusing its data, keys, passwords, sessions, controllers or HRM business domain.

## Included in the initial vertical slice

- First-party Sanctum session login (POST /auth/login, GET /auth/me, POST /auth/logout) with CSRF and a distinct INVENTORY-XSRF-TOKEN cookie. Browser login is **disabled by default**.
- Multi-company membership: company_admin, inventory_manager, viewer; company and nested asset isolation.
- Company-scoped register: server-side search/sort/pagination, create, read, optimistic version-controlled update, reason-required soft archive, transactional audit events.
- Per-company categories plus accounting/physical locations with company-scoped foreign key constraints.
- Opaque per-asset QR UUID resolver scoped to authenticated company membership. QR URL alone is never an access credential.
- Physical-location movements with reason, actor, immutable name snapshots, transactional audit event, optimistic lock and active-asset-only guards.
- Correlation IDs, stable API error codes, active-account revalidation.
- SQLite only for local/tests; planned production database is a separate PostgreSQL instance.

## Local development (Windows reference)

Use PHP 8.4 from C:\deploy\hrm\runtime\php-8.4.26-nts\php.exe, but do not change HRM services. This code lives under C:\DevProjects\itp-inventory-backend.

1. Composer install.
2. Copy .env.example to .env. For local development only: APP_ENV=local, DB_CONNECTION=sqlite, SESSION_SECURE_COOKIE=false, use a dedicated local SQLite file.
3. Generate your own APP_KEY, run Laravel migrations, then php artisan test.
4. Start php artisan serve --host=127.0.0.1 --port=18090.
5. Vue 3's isolated Vite server proxies /inventory-api/ to this loopback port.

Optional local-only QA fixture: run php artisan db:seed --class=LocalDevelopmentSeeder. It generates a random synthetic-only login secret under gitignored storage/app/private, refuses to run on PostgreSQL or outside APP_ENV=local, and creates test users/assets only. Never publish the local secret.

## Initial production administrator (interactive, no secrets in Git)

Production does **not** seed synthetic users or copy HRM passwords. An operator who controls the server must run the following on the isolated Inventory backend, using its own PostgreSQL configuration:

    php artisan inventory:bootstrap-admin --email=your-corporate-email@example.com --company="ITP Mining"

The command prompts twice for a **new Inventory-only password**, with hidden input (12+ characters, uppercase/lowercase, number and symbol). It only permits the first company administrator, and will not overwrite an existing user/password. Never pass the password in CLI arguments, chat messages, or checked-in files.

After creating the administrator and validating secure HTTPS session/CSRF isolation, set INVENTORY_BROWSER_LOGIN_ENABLED=true in the protected Inventory-only environment, reload the isolated Inventory FastCGI service and confirm POST /inventory-api/auth/login and logout on the public origin. No public registration is enabled.

## Deployment boundaries

- New DB role, data directory, APP_KEY, isolated PHP workers, session cookie and XSRF name; no HRM/Portal secrets or shared DB.
- Explicit SANCTUM_STATEFUL_DOMAINS; SESSION_COOKIE=itp_inventory_session; SESSION_SECURE_COOKIE=true; SESSION_HTTP_ONLY=true; HTTPS.
- Keep INVENTORY_BROWSER_LOGIN_ENABLED=false until security QA and explicit deployment approval.
- Publish frontend separately under /inventory/ and API under /inventory-api/. Never deploy inside Portal's dist folder.
- Review docs/API.md and docs/RELEASE-GATES.md before release.

Real legacy data import, camera scanning inside the web app, bulk QR printing, inventory counts, document storage, Excel and HRM/Portal SSO are subsequent phases.