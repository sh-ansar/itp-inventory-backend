## Назначение изменения
Опишите одну бизнес-задачу и связанный Frontend PR/issue; укажите API compatibility и затронутые company роли.

## Что изменилось
- [ ] API/домен/схема данных описаны
- [ ] Новые/обновленные миграции совместимы с предыдущим релизом
- [ ] Проверены права admin/manager/viewer, чужой company UUID и private files
- [ ] Обновлены документы: ARCHITECTURE/FUNCTIONAL/DEPLOYMENT/API при необходимости

## Приложите доказательства
- [ ] PHP lint / PHPUnit / PostgreSQL CI
- [ ] E2E с отдельной QA базой, только синтетические данные
- [ ] Negative paths (401/403/404/409/419/422) и audit/version
- [ ] Нет реальных секретов, .env, DB dump, файлов пользователей

## Release/rollback
Ожидаемые SHA, миграции/locks, backup+restore, остановка только Inventory, previous backend junction, health/neighbor smoke.

## Reviewer
Проверьте, что vendor/classmap и тестируемые классы взяты именно из текущего checkout; не принимайте green PHPUnit на symlink чужого vendor без независимого CI.
