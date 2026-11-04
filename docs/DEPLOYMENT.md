# Inventory Backend — установка, Windows release, backup и rollback

**Production по состоянию на 04.11.2026:** Backend r11 / Frontend r22. При следующем выпуске не подставлять эти версии вместо фактических current junction. См. Frontend docs/inventory-deployment.md для общей схемы Apache/Vue.

## Требования

PHP 8.4 runtime (Laravel 12) с pdo_pgsql, pdo_sqlite, mbstring, xml, gd, curl, fileinfo; Composer 2; PostgreSQL 16; Apache HTTPS и независимый FastCGI/HTTP сервис. Отдельный DB role (минимально необходимые права), директория storage с NTFS ACL, SSL chain и собственный APP_KEY. Frontend Node.js 24 + npm ci + Vite 7.

## 1. Локальная установка (отдельная тестовая база)

~~~powershell
git clone https://github.com/sh-ansar/itp-inventory-backend.git
cd itp-inventory-backend
composer install --no-interaction --prefer-dist
Copy-Item .env.example .env
# Создать новый отдельный файл database\inventory-qa.sqlite, задать APP_ENV=local,
# DB_CONNECTION=sqlite, DB_DATABASE=<absolute path to inventory-qa.sqlite>,
# SESSION_DRIVER=file, SESSION_SECURE_COOKIE=false, MAIL_MAILER=log.
php artisan key:generate
php artisan migrate
php artisan db:seed --class=LocalDevelopmentSeeder
php artisan test
php artisan serve --host=127.0.0.1 --port=18090
~~~

LocalDevelopmentSeeder **откажет** вне local+SQLite и генерирует случайный пароль в storage/app/private/local-inventory-qa.secret. Этот файл и сессии не помещать в Git. Vite proxy INVENTORY_LOCAL_API_TARGET направить на http://127.0.0.1:18090. Не направлять QA API к prod PostgreSQL.

## 2. Staging PostgreSQL (обязателен для DB change)

- Создать **отдельные** QA PostgreSQL database/role с только необходимыми grants; не использовать production instance или общие credential HRM/Portal.
- APP_ENV=local/test, свой APP_KEY, STORAGE, cookie и отключенные интеграции.
- Запустить миграции с нуля и синтетические сценарии через тесты/изолированные компании.
- Проверить FK company_id, индексы, 409 оптимистической блокировки, согласованные transaction boundaries, два клиента одновременно и restore из staging backup.
- В CI используется ephemeral PostgreSQL 16 container. Это **не** означает проверку восстановления реальной production БД.

## 3. Подготовка промышленного релиза

1. PR Backend после review и зеленых PHPUnit + PostgreSQL checks, SHA merge зафиксирован. На сервере не редактировать код внутри releases.
2. Новая backend директория вида C:\deploy\inventory\backend\releases\<new-id>; composer install --no-dev --optimize-autoloader; APP_ENV=production, APP_DEBUG=false, проверенные env, схему storage и разрешения NTFS.
3. Проверить, что **vendor/composer autoload** создан именно в новой директории: повторное использование оптимизированной папки vendor из другого Git worktree опасно, потому что classmap может указывать на старый каталог. Допустимо использовать Composer cache, но не vendor junction между production releases.
4. Проверить подключение к **Inventory** PostgreSQL и схему, php artisan migrate:status и php artisan migrate --pretend; просмотреть SQL и lock-risk. Никаких migrate:fresh.
5. Сделать PostgreSQL backup в restricted location, проверить завершение pg_dump и список объектов архива (pg_restore --list), проверить отдельный restore rehearsal. Указать размер/хеш/UTC timestamp/срок хранения/ответственного.
6. Согласовать и применить additive миграции один раз, затем проверить migrate:status и tenant constraints. Несовместимая миграция требует expand→deploy→contract, а не одновременного удаления полей.
7. Перед переключением узнать фактические active junction, сравнить их с expected old targets и подготовить проверяемый rollback target. Проверить health /inventory-api/api/v1/health, /inventory/, /hrm/, /mro/, Portal.
8. Переключать **только** C:\deploy\inventory\backend\current и связанные Inventory службы по проверенному release-script. Frontend поставляется отдельно, по согласованному порядку и совместимому API. Общий Apache нельзя перезаписывать.
9. После переключения: health API, POST auth/login, /api/v1/me, company list, изоляция компаний и роли, документы, private file access, логи, соседние приложения.

## 4. Рабочие каталоги и службы

- C:\deploy\inventory\backend\current — активный backend junction
- C:\deploy\inventory\backend\releases\ — immutable backend versions
- C:\deploy\inventory\shared\storage\ — private persistent Laravel files/logs
- C:\deploy\inventory\deployment-state\ — release manifest, checksums, change logs
- ITP-INVENTORY-FastCGI и ITP-INVENTORY-HTTP — независимые службы
- Проверка версий и состояния PowerShell: Get-Item C:\deploy\inventory\backend\current -Force; Get-Service ITP-INVENTORY*

Конфиг Apache/TLS обслуживает соседние проекты — изменения только через отдельный change с проверкой критичных HTTP routes.

## 5. Сохранение данных / rollback

Backup: используйте pg_dump custom-format в ограниченное место, без DB_PASSWORD в CLI и без копирования dump в Git или общий workspace. Проверяйте хеш и тестовый restore в отдельной базе. Операция восстановления production требует остановки записей, согласования владельца данных и схемы миграций.

Откат кода: зафиксировать incident, переключить **только** Inventory junction на предыдущую совместимую release и перезапустить только Inventory службы; проверить health/auth/компании/соседние приложения. Примененные миграции не откатываются простым переключением ссылки. НЕ выполнять миграции down или pg_restore поверх живой базы «для скорости». Для существенных записей — отдельно согласованный point-in-time recovery.

## 6. Доказательство релиза

В change log: Git SHAs, SHA256 важных артефактов, old/new junctions, миграции до/после, backup checksum и restore rehearsal, approved operator, время, smoke и дефекты. Отсутствие доказательства = релиз не закрыт. Режим rollback не означает восстановление пользовательских файлов — storage остается общим между релизами и требует собственного retention/backup.
