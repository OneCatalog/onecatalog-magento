<?php
namespace OneCatalog\Import\Service;

class B2bApi
{
    private $base;
    private $urlKey;
    private $privateKey;

    public function __construct($base, $urlKey, $privateKey)
    {
        $this->base = rtrim((string) $base, '/');
        $this->urlKey = (string) $urlKey;
        $this->privateKey = (string) $privateKey;
    }

    public function configured()
    {
        return $this->urlKey !== '' && $this->privateKey !== '';
    }

    /** Страница фида (start/limit) → декодированный ответ или null. */
    public function fetchPage($start = 0, $limit = 200)
    {
        if (!$this->configured()) {
            return null;
        }
        $url = $this->base . '/retailer-share-products/' . rawurlencode($this->urlKey) . '/?'
            . http_build_query(array(
                'private_key' => $this->privateKey,
                'start' => max(0, (int) $start),
                'limit' => max(1, (int) $limit),
            ));

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        $json = json_decode($body, true);
        return is_array($json) ? $json : null;
    }

    /** Общее число позиций (для пагинации). */
    public function total()
    {
        $data = $this->fetchPage(0, 1);
        if (!is_array($data)) {
            return 0;
        }
        return (int) ($data['meta']['counts'] ?? $data['meta']['total'] ?? 0);
    }

    /**
     * Разведка справочников фида (regions/warehouses/suppliers) с первой страницы.
     * @return array{regions:array,warehouses:array,suppliers:array}|null
     */
    public function discover($scan = 500)
    {
        $data = $this->fetchPage(0, $scan);
        if (!is_array($data)) {
            return null;
        }
        $regions = array();
        foreach ((array) ($data['regions'] ?? array()) as $id => $r) {
            $regions[(int) $id] = (string) ($r['menutitle'] ?? $r['slug'] ?? ('#' . $id));
        }
        $warehouses = array();
        foreach ((array) ($data['warehouses'] ?? array()) as $id => $w) {
            $warehouses[(int) $id] = (string) ($w['name'] ?? ($w['city'] ?? ('#' . $id)));
        }
        $suppliers = array();
        foreach ((array) ($data['suppliers'] ?? array()) as $id => $s) {
            $suppliers[(int) $id] = (string) ($s['name'] ?? ('#' . $id));
        }
        return array('regions' => $regions, 'warehouses' => $warehouses, 'suppliers' => $suppliers);
    }
}
