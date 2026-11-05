# Inventory Commercial: перенос паттернов Portal (05.11.2026)

## Архитектура

ITP Inventory получил публичную зону (landing, pricing, product, legal, login, register), отдельную от приватной SPA. Модели и механизмы аналогичны Portal: версионирование тарифов, snapshot подписок, супер-админка, счета, юридические документы, согласия и аудит.

Portal не модифицирован. Базы, пользовательские сессии и секреты Portal/HRM не используются. Свои таблицы inventory_*, маршруты /inventory-api/api/v1/{commercial,platform-admin}, отдельные Inventory grants.

Frontend: modern/src/pages/public, modern/src/pages/PlatformAdminPage.vue, CompanyBillingPage.vue, components/commercial, commercialTranslations.ts, api/commercial.ts. Супер-админка отделена от основной оболочки через AdminGate, а реестр доступен на /registry. Backend: app/Services/Commercial, app/Http/Controllers/Api/Commercial, app/Http/Middleware/EnsureCommercialEntitlement.php.

## UI маршруты

| URL (все относительно /inventory/) | Назначение |
| --- | --- |
| / | публичная главная, вместо бывшего реестра |
| /pricing | тарифы published + public; без draft и legacy |
| /auth/login, /auth/register, /verify-email | Inventory login, регистрация только по флагу + e-mail |
| /legal, /legal/:type | только действующие published документы |
| /product/modules/:module | описание Inventory модулей |
| /registry, /facilities, /stocktakes, /reports и прочие | старый приватный функционал без удаления |
| /billing | платежи и документы текущей компании |
| /admin/companies, /admin/plans, /admin/payments, /admin/documents, /admin/consents, /admin/access | супер-админка по grant |

RBAC backend: auth:sanctum + active-account + Access::need. Роли компании через Access::company (membership, write для company_admin). Обязательная отдельная grant запись inventory_platform_grants; последнего platform.manage удалить невозможно. Названия разрешений: platform.manage, companies.read, users.write, plans.read, plans.write, subscriptions.write, billing.read, billing.write, legal.read, legal.write, legal.approve, legal.publish.

## Тарифы и подписки

Immutable published версия. Terms: names.ru/en/ge, description, price_minor (сотые валюты), currency KZT/GEL/USD/EUR, period_months, limits.users/companies/storage_bytes/max_file_bytes, modules assets/disposals/facilities/references/people/warehouses/stocktakes/reports. Каждый новый редактор сохраняет draft новой версии. Подписка хранит полный snapshot terms; обновление тарифа не меняет существующий контракт. Новые клиенты с тарифом получают pending_payment до ручного назначения. Старые компании без подписки продолжают работу по существующим политикам.

Middleware ограничивает статус подписки/срок, выбранные модули, лимит новых пользователей, максимальный размер файлов. **Не реализован расчёт занятого хранилища** и лимит количества дочерних компаний (отдельного сценария их создания сейчас нет). Не вводить paywall для текущих компаний без отдельного решения.

## Счета и документы

Счета: draft -> issued -> uploaded proof -> paid или rejected; или cancelled. Реквизиты поставщика/покупателя отдельные от Portal; в invoice snapshot. Флаг INVENTORY_INVOICE_ISSUANCE_ENABLED=false запрещает выпуск (но не сохранение черновика). PDF выводится через dompdf/dompdf/DejaVu Sans без remote. **Dompdf закреплён в composer.json/composer.lock и протестирован на PHP 8.4.27**. Для выпуска реальных счетов всё ещё нужно утвердить реквизиты и проверить печатную форму с фактическими юридическими данными. Онлайн-эквайринга нет, оплата подтверждается администратором вручную.

Юридические документы имеют type, locale ru/en/ge, jurisdiction KZ/GE, version, SHA-256, draft -> approved (с основанием) -> published (только после разрешения отдельного флага). Публично показываются только published с наступившим effective_at. Согласия сохраняют user_id, SHA-256, version, source и действие. Обязательные опубликованные тексты нужно принять явно.

## Регистрация/SMTP

Регистрация отключена по умолчанию. Для включения нужен SMTP Inventory, отдельная оценка защиты от ботов. Никакой User/Company до подтверждения: pending регистрация с hashed token (24 часа) и защищённым паролем; только одноразовая транзакция verify создаёт компанию и пользователя. SMTP письма RU/EN/GE. Пароль минимум 12 символов, uppercase/lowercase + numbers, honeypot, throttle 3/min/IP. По выбранному тарифу регистрируется pending_payment.

## Активация

1. Переход на PHP >=8.4.1: текущий C:/xampp/php/php.exe на WIN-487ME2E68DR — 8.2.35, нельзя выполнять artisan со старым runtime из-за composer.lock.
2. Обеспечить DOMPDF, composer install, тесты SQLite и PostgreSQL 16, frontend npm build, Vitest и Playwright.
3. Подготовить отдельную QA DB; перед production миграцией создать и проверить восстановление бэкапа.
4. На исправном PHP8.4 запустить миграцию с --force. Сменить атомарный frontend/backend release только после QA; проверить /inventory/ и /inventory/registry.
5. Вручную выдать первому проверенному пользователю право, пример: php artisan inventory:platform-grant <existing-admin-email> platform.manage --yes. Никому автоматически не выдавать права; не включать случайные или временные пароли.
6. Создать и опубликовать три юридически и коммерчески согласованных тарифа — цены не заимствовать из другой компании без согласия.
7. Тесты нескольких компаний/ролей/счетов/PDF/SMTP/локализаций.
8. ТОЛЬКО после приёмки изменить INVENTORY_PUBLIC_REGISTRATION_ENABLED, INVENTORY_INVOICE_ISSUANCE_ENABLED, INVENTORY_LEGAL_PUBLICATION_ENABLED на true по отдельности.
9. При сбое откатить атомарный frontend release, backend только с проверкой обратной совместимости миграций. Не удалять inventory_* таблицы.

## Тестирование

Backend: php artisan test --filter=CommercialPlatformTest и CommercialBillingLifecycleTest на SQLite и PostgreSQL16, затем полный php artisan test. Frontend: npm run build, npm run test:unit -- --run tests/unit/commercialTranslations.spec.ts, npm run test:e2e -- tests/e2e/commercial-public.spec.ts. После обновления Symfony повторный composer audit в QA показал 0 известных advisories. Mock Playwright не заменяет DB-интеграционный тест. Mock Playwright не заменяет DB-интеграционный тест.

Значимые приемочные случаи: guest 401; company A не видит company B; regular user 403 admin; revoked grant; last admin protected; immutable versions; suspended/expired subscription 402; disabled module 403; required legal consent; SMTP verification; выданный PDF RU/EN/GE; мобильный 320/768/1440px.

НЕ СЧИТАТЬ ГОТОВЫМИ ДО ПРИЕМКИ: реальные цены, юридические редакции, изолированный PHP 8.4 runtime в production, SMTP в production, учет реально занятого storage и лимита дочерних компаний, live платёжный провайдер. PDF отрендерен в QA, но не утвержден как финальный юридический бланк. Frontend на базе шаблона Portal адаптирован, но не заявляется как пиксельная копия до отдельной визуальной приёмки.
