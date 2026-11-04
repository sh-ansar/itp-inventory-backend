# Inventory Backend — architecture, boundaries and data lifecycle

Исходный код: Laravel 12, отдельный API /api/v1 под публичным /inventory-api. Production: собственные FastCGI/HTTP службы и отдельная PostgreSQL БД. Схема и права **не** являются частью Portal / HRM. Этот документ основан на рабочем коде и документах миграций на 04.11.2026.

## Поток запроса

Браузер → Apache HTTPS /inventory-api → Laravel web/Sanctum session → middleware (CSRF/active-account/correlation) → /api/v1 route → Controller → CompanyAccess/Domain → Eloquent + tenant-constrained DB → JSON с X-Request-ID.

POST /auth/login имеет throttling; браузерный login включается отдельным INVENTORY_BROWSER_LOGIN_ENABLED. Все business API через auth:sanctum + inventory.active-account. Чужой company UUID должен давать 404; Viewer без write — 403. 409 применяется для версий/конфликтов; 419 для CSRF; 422 для validation.

## Код и ответственность

| Каталог/класс | Ответственность |
| --- | --- |
| routes/api.php, routes/web.php | Единая таблица маршрутов; /v1 и /auth; не прятать новые endpoint в middleware или frontend |
| app/Http/Controllers/Api | Parse/validate, аутентификация, доменные вызовы, response; не смешивать авторизацию с видимостью UI |
| app/Domain/Inventory/CompanyAccess.php | Tenant membership, 404 для неучастников, capability write/admin |
| app/Domain/Inventory/CompanyRole.php | Три значения роли и их базовая capability-модель |
| app/Domain/Inventory/AssetAuditWriter.php | Immutable asset event, actor/id/name, version, before/after |
| app/Domain/Inventory/InventorySessionService.php | Снимки и операции инвентаризации; транзакции |
| app/Models | ORM/relations/tenant-scoped entities |
| database/migrations | Эволюция БД, индексы и FK; только reviewed forward migrations |
| app/Http/Middleware | Isolated XSRF, active-account, integrations, error codes и correlation ID |
| tests/Feature, tests/Unit | RBAC, tenant isolation, конфликт версий, бизнес-процессы |

Дополнительные domain services и команды смотрите в самих соответствующих каталогах. Не переносите бизнес-логику в API routes или модель, если её уже обслуживает предметный сервис.

## Данные и инварианты

- Company — пространство данных. User может быть членом нескольких компаний через company_memberships с role_code. Membership проверяется на каждом запросе, нельзя кэшировать право после отзыва роли.
- Asset принадлежит одной компании и имеет инвентарный номер, UUID QR, состояние и version. Номер уникален в company и не переиспользуется после архивирования. Оптимистическая блокировка защищает обновления.
- AssetCategory, accounting/physical locations, facility nodes и responsible people являются company-scoped; чужие FK не допускаются.
- Facility → floor → room/pavilion → placements/assets; план и фон хранятся в приватном storage, исторические события не переписываются.
- Movements сохраняют actor, reason и старые/новые имена locations. Массовые операции атомарны; чужие активы отклоняются целиком.
- Stocktake строит снимок активов выбранной области; QR подсчет, пересчет, завершение, повторное открытие и отчеты имеют версионирование и audit.
- Disposal использует отдельное согласование и документы; архивирование/списание не равно обычному DELETE.
- Industry catalogs / manufacturer / model / unit / department и динамические поля изолированы по company. Template publish имеет версии и приватный preview.
- Integration keys компании существуют в собственном режиме; INVENTORY_INTEGRATIONS_ENABLED=false до отдельного security acceptance.

## Безопасность

Логин не должен пользоваться Portal JWT. Отличные SESSION_COOKIE / INVENTORY-XSRF-TOKEN / APP_KEY; secure/httponly/same-site в production. Uploads не должны быть доступны по прямому публичному URL. QR public_id **не** токен доступа. Сервер проверяет разрешения независимо от скрытых UI кнопок.

Поддержка: коррелировать X-Request-ID из ответа и Laravel log; не логировать тело с паролями и личными данными; не менять вручную membership без audit.

## Согласованная эволюция

Новая доменная возможность требует: отдельный API endpoint/controller + предметный сервис при необходимости → tenant/permission/validation test → миграция с индексами и обратной совместимостью → типизированный frontend API → E2E → docs → staged release/rollback. При общих UI/SSO с Portal нужен отдельный ADR и контракт межсервисного API, не прямые DB joins.
