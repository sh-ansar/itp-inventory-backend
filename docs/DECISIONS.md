# Inventory — архитектурные решения (ADR index)

Этот документ фиксирует сознательные решения для следующей команды; изменения, противоречащие им, требуют отдельного ADR с мотивацией, рисками, тестами миграции и планом отката.

## ADR-001 — самостоятельная система, а не модуль Portal
**Статус: принято.** Отдельные Frontend/Backend репозитории, PostgreSQL DB, сервисы Windows, релизные каталоги, APP_KEY, storage, cookie. Причина: независимое сопровождение и отсутствие риска при деплое HRM/Portal. Кросс-продуктовые сценарии только через версионированный API.

## ADR-002 — same-origin Sanctum сессия
**Статус: принято.** Browser login + уникальный INVENTORY-XSRF-TOKEN, auth:sanctum, роль компании проверяется Backend на каждом запросе. Portal имеет иные JWT/Sanctum механизмы и Spatie; не копировать его cookie/token. Общий SSO не включен.

## ADR-003 — три company роли, fail-closed
**Статус: принято.** company_admin, inventory_manager, viewer. CompanyRole содержит значения и базовые capability; nonmember получает 404, запрет записи — 403. Новые роли/permissions требуют отдельного model design, DB migration/feature tests, нельзя добавлять роль только в UI.

## ADR-004 — audit и версионирование
**Статус: принято.** События не меняются задним числом, assets и другие агрегаты используют version и транзакционные записи; snapshot инвентаризации сохраняет исторический набор; пакетная запись атомарна.

## ADR-005 — локальная модульность UI, без общей библиотеки прямо сейчас
**Статус: принято.** Inventory modern/ на Vue3/TS/Pinia3 и своих Base* компонентах, стилистика согласована с HRM. Portal использует @itpmining/vue-basic-components, JS/Pinia2; технический импорт напрямую создаст release coupling. Возможный общий UI-kit только как versioned dependency с semver/changelog и регрессией всех потребителей.

## ADR-006 — private файловый контур
**Статус: принято.** QR не credential, файлы документов и фоновые планы проходят authorization backend, private storage не публикуется Apache напрямую. При унификации с Portal storage нельзя делать общую папку без отдельной ACL/model proof.

## ADR-007 — future ERP integration
**Статус: не активировано.** /api/v1/external имеет закрытый feature flag; acceptance включает scoped API keys, dry-run imports, mapping, idempotency, обратимую синхронизацию, audit, ограничения нагрузки.

## ADR-008 — неразрушающие миграции и immutable releases
**Статус: принято.** Каждый release — новый каталог + junction; schema expand/contract и backup+restore gate. Rollback кода не выполняет автоматическое migrate:rollback и не сбрасывает пользовательские данные.
