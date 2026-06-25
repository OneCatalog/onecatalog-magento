<?php
namespace OneCatalog\Import\Service;

class Api
{
    private $base;
    private $token;
    private $lang;

    public function __construct($base, $token = '', $lang = 'en')
    {
        $this->base = rtrim((string) $base, '/');
        $this->token = (string) $token;
        $this->lang = (string) ($lang ?: 'en');
    }

    public function base()
    {
        return $this->base;
    }

    /** GET → массив или null. lang добавляется автоматически. */
    public function get($url)
    {
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        $url .= $sep . 'lang=' . rawurlencode($this->lang);

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_HTTPHEADER     => $this->token !== '' ? array('X-API-Key: ' . $this->token) : array(),
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

    /** Товар по public_id → payload (data) или null. */
    public function getProduct($publicId)
    {
        $publicId = trim((string) $publicId);
        if ($publicId === '') {
            return null;
        }
        $r = $this->get($this->base . '/products/' . rawurlencode($publicId) . '/');
        if (!empty($r['success']) && isset($r['data']) && is_array($r['data'])) {
            return $r['data'];
        }
        return null;
    }

    /** Скачать бинарь (медиа) → ['body'=>..., 'content_type'=>...] или null (для §5.3). */
    public function getBinary($url)
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => $this->token !== '' ? array('X-API-Key: ' . $this->token) : array(),
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 300 || $body === '') {
            return null;
        }
        return array('body' => $body, 'content_type' => $type);
    }
}
