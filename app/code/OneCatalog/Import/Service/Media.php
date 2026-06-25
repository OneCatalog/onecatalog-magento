<?php
namespace OneCatalog\Import\Service;

class Media
{
    /**
     * Упорядоченный список доступных размеров (для скачивания с фолбэком).
     * С токеном — max→middle→min; без токена — middle→max→min
     * (`min` — крайний, нежелательный вариант). При неудаче одного размера
     * скачивание пробует следующий по порядку.
     */
    public static function sizeCandidates(array $urls, $hasToken)
    {
        $order = $hasToken ? array('max', 'middle', 'min') : array('middle', 'max', 'min');
        $out = array();
        foreach ($order as $size) {
            if (!empty($urls[$size])) {
                $out[] = array('size' => (string) $size, 'url' => (string) $urls[$size]);
            }
        }
        return $out;
    }

    /** Предпочитаемый размер (первый кандидат) — для сигнатуры/одиночного выбора. */
    public static function pickSizeInfo(array $urls, $hasToken)
    {
        $cand = self::sizeCandidates($urls, $hasToken);
        return $cand ? $cand[0] : array('url' => '', 'size' => '');
    }

    public static function sizeRank($size)
    {
        $map = array('min' => 1, 'middle' => 2, 'max' => 3);
        return isset($map[$size]) ? $map[$size] : 0;
    }

    /** Размер из base64-префикса media_files (вида «size|path|token»). */
    public static function guessSizeFromUrl($url)
    {
        if (preg_match('~/media_files/([A-Za-z0-9_-]+)~', $url, $m)) {
            $raw = base64_decode(strtr($m[1], '-_', '+/'), false);
            if ($raw && false !== ($pos = strpos($raw, '|'))) {
                $size = substr($raw, 0, $pos);
                if (in_array($size, array('min', 'middle', 'max'), true)) {
                    return $size;
                }
            }
        }
        return '';
    }

    /**
     * Стабильный контент-ключ файла для дедупа. В base64-префиксе media_files зашит
     * `size|path|token`; берём path+size (неизменны между запросами/сменой токена) → sha1.
     * Регэксп [A-Za-z0-9_-]+ сам обрывается на точке-подписи (token волатилен).
     */
    public static function fileKey($url)
    {
        if (!preg_match('~/media_files/([A-Za-z0-9_-]+)~', $url, $m)) {
            return '';
        }
        $raw = base64_decode(strtr($m[1], '-_', '+/'), false);
        if (!$raw || false === strpos($raw, '|')) {
            return '';
        }
        $parts = explode('|', $raw, 3);
        $size = (string) (isset($parts[0]) ? $parts[0] : '');
        $path = (string) (isset($parts[1]) ? $parts[1] : '');
        if ($path === '') {
            return '';
        }
        return sha1($path . '#' . $size);
    }

    /** MIME → расширение файла. Неизвестный тип → null (не сохраняем). */
    public static function mimeToExt($mime)
    {
        $mime = strtolower(trim((string) $mime));
        // отбрасываем «; charset=…»
        if (false !== ($semi = strpos($mime, ';'))) {
            $mime = trim(substr($mime, 0, $semi));
        }
        $map = array(
            'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/pjpeg' => 'jpg',
            'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
            'image/avif' => 'avif', 'image/bmp' => 'bmp',
        );
        return isset($map[$mime]) ? $map[$mime] : null;
    }
}
