<?php
/**
 * OneCatalog Import — медиа для Magento (§2.3, §5.3).
 *
 * Скачивание исходников вручную (MIME по содержимому), дедуп по контент-ключу
 * (таблица onecatalog_media — один исходник качается один раз). Добавление в медиа-
 * галерею товара через addImageToMediaGallery (обложка → image/small/thumbnail).
 * Идемпотентность набора — сигнатура (имена+размеры); меняется → применяем.
 */
namespace OneCatalog\Import\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class MediaStore
{
    private $resource;
    private $scopeConfig;
    private $logger;

    public function __construct(ResourceConnection $resource, ScopeConfigInterface $scopeConfig, LoggerInterface $logger)
    {
        $this->resource = $resource;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * Применить медиа к модели товара (до save). Возвращает сигнатуру набора, если
     * применили, или null если ничего не изменилось (priorSig совпал).
     *
     * @param \Magento\Catalog\Model\Product $product
     */
    public function applyMediaToProduct($product, array $p, $priorSig = '')
    {
        $hasToken = (string) $this->scopeConfig->getValue('onecatalog/general/api_token') !== '';

        $items = [];
        $coverUrls = is_array($p['images_urls'] ?? null) ? $p['images_urls'] : [];
        if ($coverUrls) {
            $cand = Media::sizeCandidates($coverUrls, $hasToken);
            if ($cand) {
                $items[] = ['candidates' => $cand, 'key' => 'cover', 'cover' => true];
            }
        }
        foreach (($p['files'] ?? []) as $f) {
            if (!is_array($f)) {
                continue;
            }
            $cat = is_scalar($f['category'] ?? null) ? (string) $f['category'] : '';
            if ($cat !== '' && $cat !== 'images') {
                continue;
            }
            $urls = is_array($f['urls'] ?? null) ? $f['urls'] : [];
            $cand = Media::sizeCandidates($urls, $hasToken);
            if (!$cand) {
                continue;
            }
            $firstUrl = $cand[0]['url'];
            $name = is_scalar($f['name'] ?? null) && (string) $f['name'] !== ''
                ? (string) $f['name']
                : (string) (Media::fileKey($firstUrl) ?: $firstUrl);
            $items[] = ['candidates' => $cand, 'key' => $name, 'cover' => false];
        }

        if (!$items) {
            return null;
        }

        // Сигнатура набора — по предпочитаемому размеру (детерминированно из payload).
        $sigParts = [];
        foreach ($items as $it) {
            $sigParts[] = $it['key'] . ':' . $it['candidates'][0]['size'];
        }
        $sig = sha1(implode('|', $sigParts));
        if ($priorSig !== '' && $priorSig === $sig) {
            return null; // не изменилось и качество не лучше
        }

        $applied = 0;
        foreach ($items as $it) {
            $file = $this->sideloadFirst($it['candidates']);
            if ($file === '') {
                continue;
            }
            try {
                $types = $it['cover'] ? ['image', 'small_image', 'thumbnail'] : [];
                $product->addImageToMediaGallery($file, $types, false, false);
                $applied++;
            } catch (\Throwable $e) {
                // одна битая картинка не валит импорт (§5.5), но фиксируем причину
                $this->logger->warning('OneCatalog: addImageToMediaGallery failed: ' . $e->getMessage(), ['key' => $it['key']]);
            }
        }

        if ($applied === 0) {
            // Ничего не применили — НЕ сохраняем сигнатуру, чтобы повтор (напр. после ввода
            // токена) попробовал снова. Причина уже залогирована в sideload/getBinary.
            $this->logger->warning('OneCatalog: no images applied', [
                'public_id' => is_scalar($p['public_id'] ?? null) ? (string) $p['public_id'] : '',
                'items' => count($items),
                'has_token' => $hasToken,
            ]);
            return null;
        }
        return $sig;
    }

    /** Скачать первый из кандидатов, что отдался успешно (фолбэк по размерам). */
    private function sideloadFirst(array $candidates)
    {
        foreach ($candidates as $c) {
            $file = $this->sideloadSource($c['url'], $c['size']);
            if ($file !== '') {
                return $file;
            }
        }
        return '';
    }

    /** Скачать исходник с дедупом по контент-ключу → локальный путь или ''. */
    private function sideloadSource($url, $size)
    {
        $key = Media::fileKey($url);
        if ($key === '') {
            $key = sha1($url);
        }
        $conn = $this->resource->getConnection();
        $t = $this->resource->getTableName('onecatalog_media');
        $cached = (string) $conn->fetchOne('SELECT file FROM ' . $t . ' WHERE content_key = ?', [$key]);
        if ($cached !== '' && is_file($cached)) {
            return $cached;
        }
        if ($cached !== '') {
            $conn->delete($t, ['content_key = ?' => $key]);
        }

        $api = new Api(
            (string) $this->scopeConfig->getValue('onecatalog/general/api_base'),
            (string) $this->scopeConfig->getValue('onecatalog/general/api_token'),
            (string) ($this->scopeConfig->getValue('onecatalog/general/lang') ?: 'en')
        );
        $bin = $api->getBinary($url);
        if (!is_array($bin) || ($bin['body'] ?? null) === null || $bin['body'] === '') {
            $this->logger->warning('OneCatalog: image download failed', [
                'url' => $url,
                'http' => is_array($bin) ? ($bin['code'] ?? 0) : 0,
                'curl_error' => is_array($bin) ? ($bin['error'] ?? '') : '',
            ]);
            return '';
        }
        $ext = Media::mimeToExt($bin['content_type']);
        if ($ext === null && function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $ext = Media::mimeToExt(finfo_buffer($fi, $bin['body']));
            finfo_close($fi);
        }
        if ($ext === null) {
            $this->logger->warning('OneCatalog: unknown image MIME', ['url' => $url, 'content_type' => $bin['content_type']]);
            return '';
        }
        $dir = rtrim(sys_get_temp_dir(), '/') . '/onecatalog';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }
        $file = $dir . '/' . $key . '.' . $ext;
        if (false === @file_put_contents($file, $bin['body'])) {
            return '';
        }
        $conn->insertOnDuplicate($t, ['content_key' => $key, 'size' => $size, 'file' => $file, 'shared' => 0], ['file', 'size']);
        return $file;
    }
}
