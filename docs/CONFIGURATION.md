# Inventory — конфигурация окружения (без секретов)

Для локальной/QA/production среды **свой** файл .env, APP_KEY, PostgreSQL роль и STORAGE. Не копировать .env Portal/HRM, не вставлять реальные значения в issue/PR/log.

| Ключ | Назначение / правило |
| --- | --- |
| APP_NAME | Читаемое название Inventory |
| APP_ENV | local/testing для QA, production на выпуске |
| APP_KEY | Секрет Laravel; постоянный для среды, меняется только через согласованный key rotation |
| APP_DEBUG | false в production; не выводить stack trace пользователю |
| APP_URL | Публичная canonical URL Inventory API, с учетом reverse proxy |
| APP_LOCALE, APP_FALLBACK_LOCALE | ru/en/ka, fallback en |
| DB_CONNECTION | pgsql в production, sqlite только local/tests |
| DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD | Отдельная Inventory БД, минимум прав, секреты вне Git |
| SESSION_DRIVER | database в production, изолированный file/array в QA |
| SESSION_LIFETIME | Период действия сеанса; согласовать security policy |
| SESSION_COOKIE | Уникальное имя itp_inventory_session (не Portal) |
| SESSION_SECURE_COOKIE | true на HTTPS production |
| SESSION_HTTP_ONLY | true |
| SESSION_SAME_SITE | lax, проверить особенности SSO перед изменением |
| SESSION_PATH | / при текущем reverse proxy, проверить collision cookie |
| INVENTORY_XSRF_COOKIE_NAME | INVENTORY-XSRF-TOKEN; не использовать общий XSRF-TOKEN |
| SANCTUM_STATEFUL_DOMAINS | Разрешенные доверенные домены, без произвольного * |
| INVENTORY_BROWSER_LOGIN_ENABLED | Feature gate, true только после secure HTTPS/auth QA |
| INVENTORY_INTEGRATIONS_ENABLED | false до отдельной приемки API ERP |
| CACHE_STORE, QUEUE_CONNECTION | Изолированные tables/driver, без конкуренции с Portal/HRM |

Используйте .env.example как стартовый шаблон, а не как набор production значений. При использовании localhost с HTTP безопасный cookie флаг в local можно выключить; в production запрещено.

## Диагностика без раскрытия секретов

- php artisan about показывает версию без необходимости печатать полный .env.
- php artisan migrate:status проверяет миграции, но не должен заменять backup.
- Не публикуйте php artisan config:show database или весь printenv: они могут раскрыть credentials.
- При любом обновлении .env — reload **только Inventory FastCGI/HTTP**, затем health + login + CSRF + другие приложения.
- Отключение ERP интеграций должно возвращать 404, а не открытую выдачу данных.
