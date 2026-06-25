<?php
namespace OneCatalog\Import\Service;

class PriceStock
{
    /** ID поставщика оффера (новый формат — supplier_id; старый — supplier.id). */
    public static function offerSupplierId(array $offer)
    {
        if (isset($offer['supplier_id'])) {
            return (int) $offer['supplier_id'];
        }
        return (int) ($offer['supplier']['id'] ?? 0);
    }

    /** Цена оффера по приоритету регионов → ['base','promo','purchasing'] или null. */
    public static function priceForOffer(array $offer, array $regionPriority)
    {
        $byRegion = array();
        foreach ((array) ($offer['product_prices'] ?? array()) as $pr) {
            $rid = (int) ($pr['region_id'] ?? 0);
            if ($rid > 0 && !isset($byRegion[$rid])) {
                $byRegion[$rid] = $pr;
            }
        }
        if (!$byRegion) {
            return null;
        }
        $order = $regionPriority ?: array_keys($byRegion);
        foreach ($order as $rid) {
            $pr = $byRegion[(int) $rid] ?? null;
            if (!$pr) {
                continue;
            }
            $base = (float) ($pr['base_price'] ?? 0);
            if ($base > 0) {
                return array(
                    'base' => $base,
                    'promo' => (float) ($pr['promo_price'] ?? 0),
                    'purchasing' => (isset($pr['purchasing_price']) && $pr['purchasing_price'] !== null) ? (float) $pr['purchasing_price'] : null,
                );
            }
        }
        return null;
    }

    public static function priceWithSale(array $price, $promoAsSale)
    {
        $sale = null;
        if ($promoAsSale && $price['promo'] > 0 && $price['promo'] < $price['base']) {
            $sale = $price['promo'];
        }
        return array('regular' => $price['base'], 'sale' => $sale, 'purchasing' => $price['purchasing']);
    }

    /** @return array{regular:?float,sale:?float,purchasing:?float} */
    public static function resolvePrice(array $offers, array $regionPriority, array $supplierPriority, $strategy = 'priority', $supplierFixed = 0, $promoAsSale = true)
    {
        $none = array('regular' => null, 'sale' => null, 'purchasing' => null);

        if ($strategy === 'supplier') {
            $candidates = array();
            foreach ($offers as $o) {
                if (self::offerSupplierId($o) === (int) $supplierFixed) {
                    $candidates[] = $o;
                }
            }
        } else {
            $candidates = $offers;
        }

        $priced = array();
        foreach ($candidates as $o) {
            $p = self::priceForOffer($o, $regionPriority);
            if ($p !== null) {
                $priced[] = array('offer' => $o, 'price' => $p);
            }
        }
        if (!$priced) {
            return $none;
        }

        if ($strategy === 'priority' && $supplierPriority) {
            foreach ($supplierPriority as $sid) {
                foreach ($priced as $row) {
                    if (self::offerSupplierId($row['offer']) === (int) $sid) {
                        return self::priceWithSale($row['price'], $promoAsSale);
                    }
                }
            }
        }
        if ($strategy === 'supplier') {
            return self::priceWithSale($priced[0]['price'], $promoAsSale);
        }
        // min (или priority без совпадения) — минимальная база.
        usort($priced, function ($a, $b) {
            return $a['price']['base'] <=> $b['price']['base'];
        });
        return self::priceWithSale($priced[0]['price'], $promoAsSale);
    }

    /** Суммарный остаток по всем складам всех офферов. */
    public static function resolveStock(array $offers)
    {
        $sum = 0.0;
        foreach ($offers as $o) {
            foreach ((array) ($o['products_stocks'] ?? array()) as $s) {
                $sum += (float) ($s['quantity'] ?? $s['amount'] ?? 0);
            }
        }
        return $sum;
    }

    public static function anyAvailable(array $offers)
    {
        foreach ($offers as $o) {
            if (!empty($o['status'])) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int,array{supplier_id:int,code:string}> */
    public static function extractCodes(array $offers)
    {
        $out = array();
        $seen = array();
        foreach ($offers as $o) {
            $code = trim((string) ($o['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $sid = self::offerSupplierId($o);
            $key = $sid . '|' . $code;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = array('supplier_id' => $sid, 'code' => $code);
            }
        }
        return $out;
    }

    /** Сигнатура того, ЧТО будет записано (цена+скидка+остаток+статус). */
    public static function signature(array $r)
    {
        $manage = !empty($r['manage']);
        return md5(implode('|', array(
            $r['regular'] === null ? '-' : (string) (float) $r['regular'],
            $r['sale'] === null ? '-' : (string) (float) $r['sale'],
            $manage ? 'm' : 's',
            ($manage && ($r['qty'] ?? null) !== null) ? (string) (float) $r['qty'] : '-',
            (string) ($r['status'] ?? ''),
        )));
    }

    /**
     * Полный резолв записи из офферов + cfg.
     * cfg: region_prio[], supplier_prio[], strategy, supplier_fix, promo_as_sale,
     *      manage_stock, decimal_stock.
     */
    public static function resolveRecord(array $offers, array $cfg)
    {
        $price = self::resolvePrice(
            $offers,
            (array) ($cfg['region_prio'] ?? array()),
            (array) ($cfg['supplier_prio'] ?? array()),
            (string) ($cfg['strategy'] ?? 'priority'),
            (int) ($cfg['supplier_fix'] ?? 0),
            !empty($cfg['promo_as_sale'])
        );
        $stock = self::resolveStock($offers);
        $available = self::anyAvailable($offers) && $stock > 0;
        $manage = !empty($cfg['manage_stock']);
        $qty = $manage ? (!empty($cfg['decimal_stock']) ? $stock : (float) floor($stock)) : null;

        $rec = array(
            'regular' => $price['regular'],
            'sale' => $price['sale'],
            'purchasing' => $price['purchasing'] ?? null,
            'manage' => $manage,
            'qty' => $qty,
            'stock_raw' => $stock,
            'status' => $available ? 'instock' : 'outofstock',
        );
        $rec['sig'] = self::signature($rec);
        return $rec;
    }
}
