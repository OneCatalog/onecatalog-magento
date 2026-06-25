# События расширения (§8) — точки расширения для сайтового слоя

Модуль диспатчит события Magento, через которые сайт/тема дополняют логику **без форка**.
Поля payload, не входящие в маппинг ядра (текстура, площадь упаковки, рейтинг, раскладка
цен по регионам / остатков по складам), дозаполняются в наблюдателе.

## Доступные события

| Событие | Когда | Данные (`$observer->getEvent()->getData(...)`) |
|---|---|---|
| `onecatalog_product_imported` | после импорта одного товара | `id_product`, `public_id`, `status` ('created'\|'updated'), `payload` |
| `onecatalog_pricestock_updated` | после записи цены/остатка (B2B, §13.7) | `entity_id`, `record`, `offers` — **сырые офферы** фида (все регионы/склады) |

`record` = `['regular','sale','purchasing','manage','qty','stock_raw','status','sig']`.

## Как подписаться

`app/code/Vendor/Module/etc/events.xml`:

```xml
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:Event/etc/events.xsd">
    <event name="onecatalog_pricestock_updated">
        <observer name="mysite_oc_pricestock" instance="Vendor\Module\Observer\PriceStock"/>
    </event>
</config>
```

```php
namespace Vendor\Module\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class PriceStock implements ObserverInterface
{
    public function execute(Observer $observer)
    {
        $entityId = (int) $observer->getEvent()->getData('entity_id');
        $offers = (array) $observer->getEvent()->getData('offers');
        // разложить цены по регионам / остатки по складам в свои поля/таблицы
    }
}
```

## Change-detection и события (важно, §13.4)

Синхронизация цен/остатков пишет товар и шлёт `onecatalog_pricestock_updated` **только
если изменилась сигнатура** результата (цена/скидка/остаток/статус). Если в наблюдателе
вы раскладываете весь payload (все регионы/склады), изменения в неосновных регионах/складах
**не вызовут** обновление — scan-and-diff их пропустит. Тогда расширьте сигнатуру (в будущих
версиях — через плагин) или примите, что обновление триггерится изменением основного
региона/итогового остатка.
