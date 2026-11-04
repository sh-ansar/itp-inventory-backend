# Inventory — тестовая стратегия и QA handoff

## Обязательные уровни проверки

| Уровень | Что доказывает | Команда / контур |
| --- | --- | --- |
| Backend Unit/Feature | API права, миграции, домен, tenant, audit | php artisan test (APP_ENV=testing, SQLite :memory:) |
| PostgreSQL CI | Совместимость миграций и домена с реальной СУБД | Ephemeral PostgreSQL 16 service GitHub Actions |
| Frontend type/unit | Типы, Base-компоненты, контракт JS | npm run typecheck; npm run test:unit |
| UI build | Vite production chunks/SPA base | npm run build в modern/ |
| Browser smoke | Guest login, 404/routing, загрузка UI | Playwright tests/smoke, отдельный Vite |
| Authenticated E2E | Реальные CRUD, роли и multi-company в QA | isolated local Laravel+synthetic database |
| Visual acceptance | 11 основных страниц × темы/локали/viewport | Playwright + архив PNG/JSON |
| Security/backup | 401/403/404/419/409, isolation, restore | самостоятельный tenant/RBAC и DR rehearsal |

Backend feature тесты используют RefreshDatabase. Перед выполнением любой команды проверьте DB_DATABASE; unit и feature ни при каких обстоятельствах не должны работать на production PostgreSQL.

## Срез QA от 04.11.2026 (до новых handoff изменений)

- 20/20 migrations на свежей SQLite QA.
- PHPUnit 92/92; 1416 assertions.
- Vue Vitest 83/83; Playwright первичный 57/58, единственный flaky search rehydration сценарий повторно PASS.
- Anonymous HTTP API sweep 110/110; authenticated GET API 49/49.
- Дополнительные RBAC/foreign tenant/logout сценарии 3/3.
- Visual 330/330 (RU/EN/GE, light/dark, 5 ширин).
- Ограничения: PostgreSQL нагрузка/restore не полностью покрыты SQLite, все внешние интеграции выключены, пароль был заменен, но обратная смена на hex-only слабый старый пароль запрещена — полный тест обратной смены не проходил.

Evidence хранится вне Git, на QA Windows:
C:\deploy\inventory\deployment-state\inventory-fullqa-20261104-report.md
C:\deploy\inventory\deployment-state\inventory-fullqa-20261104-visual-evidence.zip

## Особая ловушка оптимизированного Composer vendor

На Windows у reused vendor/composer classmap может быть абсолютная привязка к прошлому checkout. Если в новой ветке просто создать vendor junction на чужую папку, тесты могут запускаться **по старому коду**, хотя у вас изменились классы.

Для CI и финальной подписи использовать **свежий composer install в своей директории** (composer cache допускается), проверить ReflectionClass(...)->getFileName() и путь worktree. Временный QA bootstrap с custom autoloader допустим только для локальной диагностики; не считать его production способом установки.

## Требования к новому PR

Каждый endpoint: позитивный сценарий, nonmember 404, viewer 403 для записи, неверная версия 409, валидация 422, попытка использовать foreign key, аудит и idempotency при повторном POST. Для файлов — отдельный private access test, для больших выгрузок — пагинация/лимиты и Excel injection. UI: keyboard и mobile; осознанно фиксировать hidden/readonly/loading/error.

Release gate: зеленый CI, вручную повторенный изолированный E2E, документированная миграция и rollback plan, review, успешный smoke соседних сервисов. Никаких production action из unit-тестов.
