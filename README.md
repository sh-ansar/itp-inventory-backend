# ITP Inventory — Backend Laravel 12

Сервис корпоративного учета имущества с независимой PostgreSQL БД, многокомпанейским разграничением и собственным session/Sanctum API. **Не является модулем базы Portal или HRM**.

На 04.11.2026 production использует отдельный Backend r11, Frontend r22; это исторический снимок, не автоматический target деплоя. Фактический release определяется junction + manifest + Git SHA.

## Основные возможности

Многокомпанейский реестр активов и история изменений, дерево объектов/этажей/помещений, интерактивные планы, МОЛ, перемещения, QR, складские операции, инвентаризации и пересчет, списание, отчеты/печать/XLSX, справочники (включая единицы, производители и модели), динамические поля, шаблоны документов, роли, профиль и аудит.

Код: Laravel 12 + PHP 8.4 для текущего Windows runtime; отдельная PostgreSQL БД production, SQLite для локальных тестов. API /inventory-api/api/v1; frontend /inventory/. Авторизация login/logout и CSRF в /inventory-api/auth/*; публичная регистрация с подтверждением e-mail реализована, но отключена до отдельной приемки (см. docs/commercial/README.md). Внешние интеграции отключены по умолчанию.

## Старт новой команды

Читайте по порядку:
1. **docs/ARCHITECTURE.md** — доменные границы, сервисы, схема данных, доступ.
2. **docs/FUNCTIONAL-CATALOG.md** — функционал, ключевые сценарии и ролевая матрица.
3. **docs/CONFIGURATION.md** — env-параметры без секретных значений.
4. **docs/DEPLOYMENT.md** — запуск QA/Windows/PG, release, rollback, backup gate.
5. **docs/OPERATIONS.md** — здоровье, ошибки, инциденты, поддержка.
5a. **docs/PERFORMANCE-AND-RESTORE-20261104.md** — подтвержденные PostgreSQL 16 backup/restore и нагрузочные испытания 10k.
6. **docs/TESTING.md** — модульные, сценарные, авторизованные и PostgreSQL тесты.
7. docs/API.md, docs/STOCKTAKES.md, docs/ASSET_DISPOSAL.md, docs/FACILITIES_AND_WAREHOUSES.md, docs/FLOORPLANS.md — подробные предметные контракты.

## Безопасный локальный цикл

~~~powershell
composer install
Copy-Item .env.example .env
# Установите APP_ENV=local, отдельную SQLite БД и SESSION_SECURE_COOKIE=false.
php artisan key:generate
php artisan migrate
php artisan db:seed --class=LocalDevelopmentSeeder
php artisan test
php artisan serve --host=127.0.0.1 --port=18090
~~~

Seeder разрешен только при local + SQLite; пароль генерируется в gitignored storage/app/private. Для production создавать администратора интерактивной командой inventory:bootstrap-admin без передачи пароля в аргументах/логах.

**Не запускать** локальный seed, migrate:fresh, key:generate и тесты с production .env. Для операций по рабочей базе сначала изучите docs/DEPLOYMENT.md и согласуйте change.

## Совместимость и Git

Source of truth — отдельный репозиторий sh-ansar/itp-inventory-backend. Frontend — sh-ansar/itp-inventory-frontend/modern. Все изменения через feature/* → PR → review/CI. Версии Portal/HRM, их ключи/сессии/права/БД не используются. PHP namespace: App\Domain\Inventory — процессы/политики; App\Http\Controllers\Api — HTTP boundary; App\Models — persistence; database/migrations — схема.
