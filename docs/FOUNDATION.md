# Inventory backend — архитектурные основы

Исходный foundation (октябрь 2026) был существенно расширен: на 04.11.2026 реализованы инвентаризации, QR, МОЛ, склад, списание, отчеты, отраслевые справочники, динамические поля и настройки компании.

**Не используйте ранний список «Next iteration» как актуальный backlog.**

Актуальные документы:
- README.md — точка входа, сборка и изоляция.
- docs/ARCHITECTURE.md — код, данные, безопасность и потоки.
- docs/FUNCTIONAL-CATALOG.md — реализованные процессы и роли.
- docs/DEPLOYMENT.md, docs/OPERATIONS.md — установка, release, backup/rollback и инциденты.
- docs/RELEASE-GATES.md, docs/TESTING.md — quality gates.

Инвариант, сохранившийся с foundation: Inventory — отдельный продукт Laravel 12 и собственная БД; никаких общих cookies, APP_KEY, директории storage или миграций Portal/HRM. Portal SSO пока **не реализован**; внешние интеграции выключены по умолчанию.
