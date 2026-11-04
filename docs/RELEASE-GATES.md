# Inventory — релизные критерии (действующая версия)

Этот gate применяется к **каждому** изменению Frontend, Backend, миграций или инфраструктуры. Он не означает автоматического разрешения деплоя после зелёного теста. Архитектура, процессы и восстановление: docs/ARCHITECTURE.md, docs/DEPLOYMENT.md и docs/OPERATIONS.md.

## Gate A — Git и изменения

- Отдельная feature/* ветка каждого репозитория, согласованные версии API.
- PR с описанием бизнес-сценариев, ролей, миграций, привязкой к issue и rollback plan.
- Review независимым разработчиком, отсутствие production секретов/DB/документов в diff.
- PHPUnit и TypeScript/Vitest/build, Playwright smoke — зеленые. PostgreSQL CI должен быть зеленым при database-affecting change.

## Gate B — модель данных и безопасность

- Separate PostgreSQL database/role/storage + APP_KEY + уникальные cookies/XSRF, login rate limit.
- Проверить multi-company 404, viewer 403, version conflict 409, CSRF 419 и validation 422.
- Private files и QR не дают доступ по угадываемому URL; Audit event сохраняет actor/company/version.
- Действия массового изменения атомарны, справочники уникальны внутри компании, неизвестная роль fail-closed.
- ERP/1C integration disabled до независимой security review.

## Gate C — подготовка выпуска

- Release manifest с SHA, immutable каталогами, контрольными суммами, old/new junction и операторами.
- PostgreSQL backup и проверенный restore rehearsal; REVIEW всех pending migrations через --pretend.
- Проверка наличия vendor/autoload и **чистой собственной composer classmap**, без vendor junction на другой checkout.
- Совместимость схемы с предыдущим API релизом, rollback plan до переключения.
- Соседние HRM/Portal/ТОиР не затрагиваются.

## Gate D — post-release

- Проверены /inventory/, API health, /me 401 гостя, авторизованный /me и компании, один полный read/write workflow, UI под admin/manager/viewer.
- Ошибки Laravel/HTTP и 5xx отсутствуют на контрольном окне, health соседних приложений PASS.
- Утвержден change log, rollback и backup доступны. Release только после письменного QA sign-off.

## Не объявлять готовым по одному smoke

Историческая QA база SQLite и 330 visual screenshots подтверждают свои сценарии, но не заменяют тест PostgreSQL concurrency, restore, ACL файлов и отдельных ERP интеграций. Документацию текущего релиза и реальные release SHA обновлять вместе с выпуском.
