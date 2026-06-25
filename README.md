# onecatalog-magento

Модуль **импорта каталога OneCatalog** и **синхронизации цен/остатков (B2B)** для
**Magento 2.4.x (Open Source / Mage-OS)**.

> ⚠️ **Статус: в разработке.** Production-релиза пока нет.
> Текущая работа — в ветке `dev`. В `main` попадают только подтверждённые
> production-версии (с тегом `vX.Y.Z`).

- Стандарт интеграции (канон): https://github.com/OneCatalog/onecatalog-standard — соответствует стандарту **v1.2**
- Эталонная реализация: https://github.com/OneCatalog/onecatalog-woocommerce
- Целевая платформа: **Magento 2.4.x Open Source** (модуль `OneCatalog_Import`, app/code)

## Возможности

- **Импорт каталога** из Wiki API: товары (`ProductRepository`), категории-дерево,
  характеристики → EAV-атрибуты (find-or-create), габариты (конверсия единиц), изображения
  (галерея с трекингом качества и дедупом). Идемпотентность по `public_id`, цена импортом
  не задаётся, статус/sku — только при создании.
- **Справочные сущности** (по умолчанию выкл, нативное прежде своего): бренд → нативный
  атрибут `manufacturer`, страна/коллекции/теги → атрибут или категория.
- **Виджет выбора** (пикер) + **AJAX-степпер** импорта: прогресс, сводка, отмена, persist.
- **Синхронизация цен/остатков (B2B)** по принципу **scan-and-diff**: пишутся только
  изменившиеся товары; цена/скидка → `ProductAction` (price/special_price), остаток →
  `StockRegistry`. Стратегии цены × приоритет регионов.
- **Журнал импорта**; **события** для сайтового слоя (см. `docs/EVENTS.md`).

## Установка

1. Скопировать `app/code/OneCatalog/Import` в Magento (или установить composer-пакетом
   `onecatalog/module-import`).
2. `bin/magento module:enable OneCatalog_Import`
3. `bin/magento setup:upgrade` (создаст служебные таблицы из `db_schema.xml`).
4. `bin/magento setup:di:compile` (на production) и `bin/magento cache:flush`.
5. **Stores → Configuration → OneCatalog** — внести API-токен и язык.
6. После массового импорта/синка — `bin/magento indexer:reindex` (или дождаться cron).

## Использование

- **OneCatalog → Import**: «Select products» (пикер) или вставить список `public_id`.
- **OneCatalog → Prices & stock**: задать `url_key`/`private_key` в конфигурации, «Synchronize now».
- **OneCatalog → Import log**: последние результаты.

## Структура

```
app/code/OneCatalog/Import/
  registration.php, composer.json, etc/module.xml
  etc/db_schema.xml(+whitelist), config.xml, acl.xml, adminhtml/{system,menu,routes}.xml
  Service/                 Api, Units, Media(+Store), B2bApi, PriceStock, Importer, B2bSync
  Controller/Adminhtml/    Import/{Index,Batch}, B2b/{Index,Sync}, Log/Index
  Block/Adminhtml/, view/adminhtml/{web/js,templates,layout}
  Model/Config/Source/, i18n/en_US.csv
docs/                      план, ответы, события (EVENTS.md)
tests/                     офлайн-тесты чистой логики
```

## Разработка

- Офлайн-тесты чистой логики (без Magento): `php tests/units-test.php`,
  `php tests/media-test.php`, `php tests/pricestock-test.php`.
- gitflow: работа в `dev`; релиз — merge `dev`→`main` + тег `vX.Y.Z` после проверки.
