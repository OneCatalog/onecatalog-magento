# История изменений — OneCatalog Import (Magento)

Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.0.0/),
нумерация версий — по [семантическому версионированию](https://semver.org/lang/ru/).

Соответствие стандарту интеграции: **v1.2** (см.
[onecatalog-standard](https://github.com/OneCatalog/onecatalog-standard)).
Целевая платформа: **Magento 2.4.x Open Source**.

## [Не выпущено] — бэклог

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
