# Inventory Operations — поддержка, мониторинг, инциденты

Граница: этот runbook относится только к Inventory. Не менять сервисы, DB, конфигурации Portal, HRM, ТОиР, ETP/Spyon по инциденту Inventory без отдельного одобрения.

## Ежедневная проверка без записи

1. Проверить https://platform.itpmining.kz/inventory/ и фактический index.html/assets; 200 на HTML без JS не доказывает работоспособность SPA.
2. GET https://platform.itpmining.kz/inventory-api/api/v1/health должен возвращать 200. GET /inventory-api/api/v1/me без сессии ожидаемо 401.
3. Проверить Windows службы ITP-INVENTORY-HTTP, ITP-INVENTORY-FastCGI, Apache; не считать общий Apache достаточным.
4. Сверить C:\deploy\inventory\backend\current и site\current с последним release manifest. Любая неожиданная смена цели — incident.
5. Проверить Laravel log в C:\deploy\inventory\shared\storage\logs\laravel.log (доступ согласно ACL), HTTP/FCGI ошибки, X-Request-ID, рост 500/419/429/409, диск storage и PostgreSQL sessions/locks.
6. Проверить доступность /hrm/, /mro/ и корня Portal как соседних систем, без запуска изменений в них.

## Триаж по HTTP кодам

| Код | Ожидаемое значение / действие |
| --- | --- |
| 200 | Успех HTTP, но проверить полезный JSON и пользовательский сценарий |
| 401 | Сессия отсутствует/истекла: восстановить login + /me, проверить cookie/domain |
| 403 | Реальный запрет роли: проверить membership и endpoint, не отключать middleware |
| 404 | Неизвестный объект/чужая компания/выключенная интеграция — не раскрывать существование |
| 409 | Конфликт версии/изменение другим пользователем: UI должен предложить обновить |
| 419 | XSRF cookie или session domain/path, прокси/HTTPS, не отключать CSRF |
| 422 | Поля/пароль/бизнес-ограничения: проверить payload по документации без сохранения PII |
| 429 | Rate limiter: не отключать; проверить аномально частые запросы и частоту автотестов |
| 500 | Сверить X-Request-ID, последнее изменение/логи, не включать APP_DEBUG в production |

## Runbook по типовым сбоям

**Вход работает, список компаний 401:** проверить stateful Sanctum, INVENTORY-XSRF-TOKEN, SESSION_COOKIE и APP_URL, без смешения Portal токенов; проверить /auth/me и /api/v1/me. Проверять с синтетической учетной записью лишь на QA.

**Белая страница / 404 после деплоя:** сравнить активный frontend junction, versioned JS/CSS из index.html, SPA rewrite /inventory/, router base; не заменять общий Apache конфиг наобум.

**Справочники/карточки дают 409/500:** проверить миграции и FK в Inventory DB, optimistic version/tenant boundary, отсутствие сетевых retries с дублированием POST; не чистить данные вручную.

**Загрузка документа:** проверить private storage junction, ACL сервиса и MIME/size; файлы не должны открываться по незадокументированным public URL.

**Нет данных компании:** список memberships и текущая компания; 404 на чужой tenant — защита. Не копировать membership другого клиента как workaround.

**Интеграция ERP 404:** обычно INVENTORY_INTEGRATIONS_ENABLED=false; включение только через отдельную приемку ключей и импортов, не через hotfix.

## Инциденты и эскалация

P0: массовая недоступность, чужие данные видны, разрушение данных, утечка private files. Изолировать Inventory, зафиксировать время/релиз/симптомы, оповестить ответственного за данные и security; запретить auto-migrate и не очищать логи.

P1: ошибки ключевого бизнес-цикла, 500 при движениях/списаниях, невозможность входа ролей. Сохранить X-Request-ID, ID (без персональных данных), шаги, tenant и версию.

P2: визуальные недочеты, отдельные отчеты, перевод и UX. Issue в GitHub с обезличенным воспроизведением и ожиданием результата.

После решения: postmortem (причина, воздействие, почему тест не нашел, мера предотвращения, владелец, дата), обновление docs и тестового сценария.

## Security и retention

Не печатать production .env или секреты в чат, incident, stacktrace и скриншоты. Backup/restore делать с доступом по ролям и retention; сроки хранения согласовать с владельцем данных, не придумывать автоматически. APP_KEY нельзя просто сгенерировать заново на существующей БД. Для диагностической команды php artisan -- проверить путь приложения и APP_ENV; в текущем production нет безопасного режима migrate:fresh.
