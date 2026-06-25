<?php
/**
 * OneCatalog — синхронизация цен/остатков из B2B-фида (§13, Magento 2.4.x).
 *
 * scan-and-diff (§13.4): префетч public_id→entity_id (onecatalog_map) и сигнатур
 * (onecatalog_meta) одним запросом; пишем только изменившиеся. Цена → product price,
 * скидка → special_price (через ProductAction, без полной загрузки), остаток →
 * StockRegistry. Сигнатуры/коды — onecatalog_meta.
 */
namespace OneCatalog\Import\Service;

use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;

class B2bSync
{
    private $resource;
    private $scopeConfig;
    private $productAction;
    private $stockRegistry;
    private $stockItemRepository;

    public function __construct(
        ResourceConnection $resource,
        ScopeConfigInterface $scopeConfig,
        ProductAction $productAction,
        StockRegistryInterface $stockRegistry,
        StockItemRepositoryInterface $stockItemRepository
    ) {
        $this->resource = $resource;
        $this->scopeConfig = $scopeConfig;
        $this->productAction = $productAction;
        $this->stockRegistry = $stockRegistry;
        $this->stockItemRepository = $stockItemRepository;
    }

    public function processPage($start = 0, $limit = 200)
    {
        $api = new B2bApi(
            (string) ($this->scopeConfig->getValue('onecatalog/b2b/base') ?: 'https://api.onecatalog.net/b2b/v1'),
            (string) $this->scopeConfig->getValue('onecatalog/b2b/url_key'),
            (string) $this->scopeConfig->getValue('onecatalog/b2b/private_key')
        );
        if (!$api->configured()) {
            return ['error' => 'b2b not configured'];
        }
        $data = $api->fetchPage($start, $limit);
        if (!is_array($data)) {
            return ['error' => 'feed fetch failed', 'next' => $start, 'more' => false];
        }

        $total = (int) ($data['meta']['counts'] ?? $data['meta']['total'] ?? 0);
        $known = (array) ($data['products']['known'] ?? []);
        $cfg = $this->cfg();

        $map = $this->mapPublicIds(array_keys($known));
        $sigs = $this->sigPrefetch(array_values($map));

        $scanned = 0;
        $changed = 0;
        $unchanged = 0;
        $missing = 0;

        foreach ($known as $publicId => $offers) {
            $publicId = (string) $publicId;
            $offers = (array) $offers;
            $scanned++;
            if (!isset($map[$publicId])) {
                $missing++;
                continue;
            }
            $entityId = (int) $map[$publicId];
            $rec = PriceStock::resolveRecord($offers, $cfg);
            if (isset($sigs[$entityId]) && $sigs[$entityId] === $rec['sig']) {
                $unchanged++;
                continue;
            }
            $this->applyResolved($entityId, $rec, $offers);
            $changed++;
        }

        $next = $start + $limit;
        $more = ($scanned > 0) && ($total > 0 ? $next < $total : $scanned >= $limit);

        return [
            'scanned' => $scanned, 'changed' => $changed, 'unchanged' => $unchanged,
            'missing' => $missing, 'total' => $total, 'next' => $next, 'more' => $more,
        ];
    }

    private function applyResolved($entityId, array $rec, array $offers)
    {
        // Цена/скидка → массовое обновление атрибутов (без полной загрузки товара).
        $attrs = [];
        if ($rec['regular'] !== null) {
            $attrs['price'] = (float) $rec['regular'];
        }
        // special_price: задаём при наличии скидки, иначе очищаем (пустая строка → NULL).
        $attrs['special_price'] = ($rec['sale'] !== null) ? (float) $rec['sale'] : '';
        if ($attrs) {
            try {
                $this->productAction->updateAttributes([(int) $entityId], $attrs, 0);
            } catch (\Throwable $e) {
                // не валим синк
            }
        }

        // Остаток → StockRegistry.
        if (!empty($rec['manage'])) {
            try {
                $stockItem = $this->stockRegistry->getStockItem((int) $entityId);
                if ($stockItem && $stockItem->getItemId()) {
                    $qty = (int) round((float) $rec['qty']);
                    $stockItem->setQty($qty);
                    $stockItem->setIsInStock($qty > 0 ? 1 : 0);
                    $this->stockItemRepository->save($stockItem);
                }
            } catch (\Throwable $e) {
                // не валим синк
            }
        }

        // Служебное в meta (не атрибуты товара, §5.1).
        $this->metaSet($entityId, 'pricestock_sig', (string) $rec['sig']);
        $codes = PriceStock::extractCodes($offers);
        $flat = [];
        foreach ($codes as $c) {
            $flat[] = $c['supplier_id'] . ':' . $c['code'];
        }
        $this->metaSet($entityId, 'supplier_code', implode(',', $flat));
    }

    private function cfg()
    {
        return [
            'region_prio' => $this->csvInts($this->scopeConfig->getValue('onecatalog/b2b/region_priority')),
            'supplier_prio' => $this->csvInts($this->scopeConfig->getValue('onecatalog/b2b/supplier_priority')),
            'strategy' => (string) ($this->scopeConfig->getValue('onecatalog/b2b/strategy') ?: 'min'),
            'supplier_fix' => (int) $this->scopeConfig->getValue('onecatalog/b2b/supplier_fixed'),
            'promo_as_sale' => (string) $this->scopeConfig->getValue('onecatalog/b2b/promo_as_sale') !== '0',
            'manage_stock' => (int) $this->scopeConfig->getValue('onecatalog/b2b/manage_stock') === 1,
            'decimal_stock' => false,
        ];
    }

    private function csvInts($s)
    {
        $out = [];
        foreach (explode(',', (string) $s) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $out[] = (int) $part;
            }
        }
        return $out;
    }

    private function mapPublicIds(array $publicIds)
    {
        $publicIds = array_values(array_filter(array_map('strval', $publicIds)));
        if (!$publicIds) {
            return [];
        }
        $conn = $this->resource->getConnection();
        $t = $this->resource->getTableName('onecatalog_map');
        $rows = $conn->fetchAll('SELECT entity_id, public_id FROM ' . $t . ' WHERE public_id IN (?)', [$publicIds]);
        $map = [];
        foreach ((array) $rows as $r) {
            $map[(string) $r['public_id']] = (int) $r['entity_id'];
        }
        return $map;
    }

    private function sigPrefetch(array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $conn = $this->resource->getConnection();
        $t = $this->resource->getTableName('onecatalog_meta');
        $rows = $conn->fetchAll("SELECT entity_id, value FROM " . $t . " WHERE meta_key = 'pricestock_sig' AND entity_id IN (?)", [$ids]);
        $out = [];
        foreach ((array) $rows as $r) {
            $out[(int) $r['entity_id']] = (string) $r['value'];
        }
        return $out;
    }

    private function metaSet($entityId, $key, $value)
    {
        $conn = $this->resource->getConnection();
        $t = $this->resource->getTableName('onecatalog_meta');
        $conn->insertOnDuplicate($t, ['entity_id' => (int) $entityId, 'meta_key' => (string) $key, 'value' => (string) $value], ['value']);
    }
}
