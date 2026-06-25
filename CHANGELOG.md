# История изменений — OneCatalog Import (Magento)

Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.0.0/),
нумерация версий — по [семантическому версионированию](https://semver.org/lang/ru/).

Соответствие стандарту интеграции: **v1.2** (см.
[onecatalog-standard](https://github.com/OneCatalog/onecatalog-standard)).
Целевая платформа: **Magento 2.4.x Open Source**.

## [Не выпущено] — бэклог

### Реализовано на `dev` — ядро импорта одного товара (версия 0.2.0)
- **`Service\Api` / `Service\Units`** — перенесены из PHP-портов (namespaced, чистая
  логика). Units покрыт офлайн-тестом (`tests/units-test.php`).
- **`Service\Importer`** — импорт одного товара через `ProductRepository`:
  идемпотентность по `onecatalog_map` (не по sku, §5.1); `sku ← article` или `OC-<public_id>`;
  название/описание; категории-дерево find-or-create (`CategoryRepository`); характеристики
  → EAV-атрибуты find-or-create (varchar, группа «OneCatalog», через `EavSetup`); габариты —
  Units (вес → кг нативно, размеры → ед. длины в `oc_*`-атрибуты). **Цена 0 и статус/sku —
  только при создании** (§5.6); ошибки атрибута/категории не валят импорт (§5.5).


### Реализовано на `dev` — каркас модуля (версия 0.1.0)
- **Модуль `OneCatalog_Import`** (Magento 2.4.x): `registration.php`, `etc/module.xml`
  (sequence Catalog/CatalogInventory/Backend), `composer.json` (PSR-4 `OneCatalog\Import`).
- **Служебные таблицы** (declarative `etc/db_schema.xml` + whitelist, §5.1 — служебное вне
  атрибутов товара): `onecatalog_map` (идемпотентность public_id ↔ entity_id),
  `onecatalog_meta`, `onecatalog_media`, `onecatalog_b2b_staging`, `onecatalog_log`.
- **Настройки** (`etc/config.xml` дефолты + `etc/adminhtml/system.xml`): Stores →
  Configuration → OneCatalog — база/токен Wiki, язык, шаг (≥10), статус новых, origin
  пикера. ACL (`acl.xml`), пункт меню (`menu.xml`), admin-route (`routes.xml`).
- ⚠️ Следующий инкремент 0.2.0 — ядро импорта одного товара (§5).


### Дизайн (до кода)
- `docs/integration-plan.md` — маппинг сущностей и реализация под Magento 2.4.x.
- `docs/integration-answers.md` — решения по вопросам (до старта кода).

### План инкрементов (на `dev`) — паритет = OpenCart 0.7.0
- 0.1.0 — каркас модуля (registration/module.xml/db_schema/system.xml/menu/acl) + настройки.
- 0.2.0 — ядро импорта одного товара (§5).
- 0.3.0 — медиа (качество + дедуп, §5.3).
- 0.4.0 — пикер (§2.4 v1.2) + AJAX-степпер + UX (§6 v1.2).
- 0.5.0 — справочные сущности + выбор цели (§3/§7).
- 0.6.0 — §13 B2B (цены/остатки, scan-and-diff).
- 0.7.0 — полировка (события §8, журнал импорта, README), затем стоп.
