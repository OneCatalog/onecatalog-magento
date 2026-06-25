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

class MediaStore
{
    private $resource;
    private $scopeConfig;

    public function __construct(ResourceConnection $resource, ScopeConfigInterface $scopeConfig)
    {
        $this->resource = $resource;
        $this->scopeConfig = $scopeConfig;
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
            $pick = Media::pickSizeInfo($coverUrls, $hasToken);
            if ($pick['url'] !== '') {
                $items[] = ['url' => $pick['url'], 'size' => $pick['size'], 'key' => 'cover', 'cover' => true];
            }
        }
        foreach (($p['files'] ?? []) as $f) {
            if (!is_array($f)) {
                continue;
            }
            $cat = (string) ($f['category'] ?? '');
            if ($cat !== '' && $cat !== 'images') {
                continue;
            }
            $urls = is_array($f['urls'] ?? null) ? $f['urls'] : [];
            $pick = Media::pickSizeInfo($urls, $hasToken);
            if ($pick['url'] === '') {
                continue;
            }
            $name = (string) ($f['name'] ?? Media::fileKey($pick['url']) ?? $pick['url']);
            $items[] = ['url' => $pick['url'], 'size' => $pick['size'], 'key' => $name, 'cover' => false];
        }

        if (!$items) {
            return null;
        }

        $sigParts = [];
        foreach ($items as $it) {
            $sigParts[] = $it['key'] . ':' . $it['size'];
        }
        $sig = sha1(implode('|', $sigParts));
        if ($priorSig !== '' && $priorSig === $sig) {
            return null; // не изменилось и качество не лучше
        }

        foreach ($items as $it) {
            $file = $this->sideloadSource($it['url'], $it['size']);
            if ($file === '') {
                continue;
            }
            try {
                $types = $it['cover'] ? ['image', 'small_image', 'thumbnail'] : [];
                $product->addImageToMediaGallery($file, $types, false, false);
            } catch (\Throwable $e) {
                // одна битая картинка не валит импорт (§5.5)
            }
        }
        return $sig;
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
        if ($bin === null) {
            return '';
        }
        $ext = Media::mimeToExt($bin['content_type']);
        if ($ext === null && function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $ext = Media::mimeToExt(finfo_buffer($fi, $bin['body']));
            finfo_close($fi);
        }
        if ($ext === null) {
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
