# Интеграционный план: Magento 2.4.x (Open Source)

Порт OneCatalog Import под **Magento 2.4.x Open Source / Mage-OS** по стандарту **v1.2**.
Эталон логики — onecatalog-woocommerce; чистое ядро (Units, PriceStock-резолверы,
Media-хелперы, Api) переносим из PHP-портов (с namespace `OneCatalog\Import\Service`).

Два сценария стандарта внедряем по отдельности:
1. **Импорт каталога** из Wiki API (§1–§12). Цену импорт НЕ задаёт (§5.6).
2. **Синхронизация цен/остатков** из B2B-фида (§13) — change-detection.

---

## Платформа и упаковка

- **Модуль `OneCatalog_Import`** (vendor `OneCatalog`, module `Import`), путь
  `app/code/OneCatalog/Import/` (или composer-пакет `onecatalog/module-import`).
- **Структура (Magento DI):**
  ```
  registration.php, composer.json, etc/module.xml
  etc/db_schema.xml                     служебные таблицы (declarative schema)
  etc/config.xml, etc/adminhtml/system.xml   настройки (Stores → Configuration)
  etc/acl.xml, etc/adminhtml/menu.xml, etc/adminhtml/routes.xml, etc/di.xml
  etc/events.xml (+ Observer)            точки расширения / события (§8)
  Service/Api.php Units.php Media.php B2bApi.php PriceStock.php   ядро (порт)
  Service/Importer.php B2bSync.php Staging.php                   логика
  Controller/Adminhtml/{Import,B2b,Log}/...                     страницы + AJAX
  view/adminhtml/{web/js,templates,layout}                      пикер/степперы/шаблоны
  i18n/en_US.csv                         локализация
  ```

---

## Маппинг сущностей → Magento

| OneCatalog | Magento 2.4 | Примечание |
|---|---|---|
| product | `Catalog\Product` (ProductRepository) | type `simple`, цена `0` (не синтезируем, §5.6) |
| **public_id** | **своя таблица `onecatalog_map`** (entity_id ↔ public_id) | идемпотентность по ней, не по `sku` |
| article | нативный **`sku`** (+ `sku` обязателен) | §5.2; если пуст — генерируем из public_id (sku обязателен в Magento) |
| options[] | **атрибуты товара (EAV)** — text/select | find-or-create атрибута по коду/метке; select → опции |
| categories[] | `Catalog\Category` (дерево) | find-or-create по имени; CategoryLinkManagement |
| **brand** | нативный атрибут **`manufacturer`** (select) | §3 «нативное прежде своего» (есть в дефолтном наборе) |
| country | атрибут / выбор цели | по умолчанию выкл (§7) |
| collections[] | атрибут / категория (выбор цели) | по умолчанию выкл; поля → событие (§8) |
| tags[] | атрибут (в Magento 2 нет нативных тегов) | по умолчанию выкл |
| images_urls/files | media gallery товара | ручное скачивание, MIME по содержимому (§2.3) |
| sizes/weight | `weight` + custom-атрибуты размеров | конверсия г→кг, мм→ед. (§5.6) |
| прочее | событие `onecatalog_product_imported` (сайтовый слой) | §8 |

---

## Обязательные инварианты на Magento

- **§5.1 идемпотентность**: поиск по `public_id` (таблица `onecatalog_map`) → update/insert
  через `ProductRepository`. Справочники (категории/атрибуты/опции) — find-or-create по имени.
- **§5.1 служебное вне формы**: сигнатуры идемпотентности (медиа, цены/остатки), коды
  поставщиков — таблица `onecatalog_meta` (key-value по entity_id), НЕ атрибуты товара.
- **§5.2 артикул**: `sku` ← `article`; пуст → `sku = 'OC-'+public_id` (sku обязателен).
- **§5.3 медиа**: качество (min/middle/max) + дедуп по контент-ключу (`onecatalog_media`),
  общие файлы не удаляем; галерея идемпотентна по имени файла.
- **§5.6**: цена `0` (не синтезируем); статус/`sku` — только при создании; единицы — Units.
- **§6 очередь**: браузерный AJAX-степпер (admin-контроллер); UX v1.2.
- **§2.4 пикер (v1.2)**: `parentOrigin = window.location.origin`; origin виджета —
  настройкой; доверие по `event.source === iframe`; JSON-строка; × + Esc.
- **§8 события**: `EventManager::dispatch('onecatalog_product_imported', …)`,
  `onecatalog_pricestock_updated` (сырые офферы, §13.7) — наблюдатели через events.xml.
- **§7 настройки** (`system.xml` + `ScopeConfigInterface`): токен/база Wiki, язык, шаг,
  статус новых, origin пикера; справочные — по умолчанию выкл; B2B (url_key/private_key/
  стратегия/приоритеты/промо/расписание).
- **§9 i18n**: `i18n/en_US.csv` (EN исходный) + `ru_RU.csv`.
- **§13**: B2bApi + PriceStock-резолверы (порт), scan-and-diff (префетч
  public_id→entity_id + сигнатуры одним запросом); цена→product price, скидка→
  `special_price`, остаток→`StockRegistry`/`SourceItem` (MSI).

---

## ❓ Вопросы (ответить ДО старта) — см. integration-answers.md
1. public_id: своя таблица (рекомендуется). 🟢
2. Набор атрибутов: использовать Default attribute set + добавлять атрибуты в него? 🟡
3. Остаток: legacy `StockRegistryInterface` vs MSI `SourceItem`? (Open Source 2.4 — MSI вкл) 🟡
4. Скидка B2B → `special_price` (атрибут) vs Catalog Price Rule? 🟡
5. Мультисайт/мультисклад (MSI sources) — один источник по умолчанию? 🟡
6. Расписание §13.6: Magento cron (crontab.xml) — на этапе полировки. 🟡
7. Версия: 2.4.x Open Source, PHP 8.1+. 🟢

## 🚫 Специфика Magento
- EAV + DI + декларативная схема — много бойлерплейта; зато всё нативно и расширяемо.
- `sku` обязателен и уникален — генерируем из public_id, если артикула нет.
- Индексация: после массового импорта нужна переиндексация (предупредить/триггерить).
