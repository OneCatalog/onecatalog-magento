<?php
namespace OneCatalog\Import\Service;

class Units
{
    private static $weight = array(
        'g' => 1.0, 'gr' => 1.0, 'gram' => 1.0, 'г' => 1.0, 'гр' => 1.0,
        'kg' => 0.001, 'kgs' => 0.001, 'кг' => 0.001,
        'lb' => 0.00220462, 'lbs' => 0.00220462,
        'oz' => 0.0352739,
        't' => 0.000001, 'т' => 0.000001,
    );

    private static $length = array(
        'mm' => 1.0, 'мм' => 1.0,
        'cm' => 0.1, 'см' => 0.1,
        'dm' => 0.01, 'дм' => 0.01,
        'm' => 0.001, 'м' => 0.001,
        'in' => 0.0393701, 'inch' => 0.0393701, '"' => 0.0393701, 'дюйм' => 0.0393701,
        'ft' => 0.00328084,
    );

    /** Вес из граммов в единицу целевого класса. Неизвестная единица → как есть (граммы). */
    public static function weight($grams, $unit)
    {
        $f = self::factor(self::$weight, $unit);
        return $f === null ? (float) $grams : (float) $grams * $f;
    }

    /** Размер из миллиметров в единицу целевого класса. Неизвестная единица → как есть (мм). */
    public static function length($mm, $unit)
    {
        $f = self::factor(self::$length, $unit);
        return $f === null ? (float) $mm : (float) $mm * $f;
    }

    /**
     * Привести значение из единицы источника к граммам/мм, если в payload единица иная
     * (sizes.*_unit). База: вес — г, длина — мм. Возвращает значение в базовой единице.
     */
    public static function toBaseWeight($value, $srcUnit)
    {
        $f = self::factor(self::$weight, $srcUnit);
        return ($f === null || $f == 0.0) ? (float) $value : (float) $value / $f;
    }

    public static function toBaseLength($value, $srcUnit)
    {
        $f = self::factor(self::$length, $srcUnit);
        return ($f === null || $f == 0.0) ? (float) $value : (float) $value / $f;
    }

    private static function factor(array $table, $unit)
    {
        $u = trim(mb_strtolower((string) $unit, 'UTF-8'));
        if ($u === '') {
            return null;
        }
        return isset($table[$u]) ? $table[$u] : null;
    }
}
