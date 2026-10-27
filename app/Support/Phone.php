<?php

namespace App\Support;

/**
 * Единая серверная нормализация телефонов для поиска, создания и изменения.
 *
 * Каноническая форма — цифры без форматирования:
 * - российские 8/7/10-значные мобильные приводятся к 7XXXXXXXXXX;
 * - явно международные номера (+84, +90 и т.п.) не «российизируются»;
 * - буквы и произвольный мусор не принимаются как номер.
 */
final class Phone
{
    public const MIN_DIGITS = 7;

    public const MAX_DIGITS = 15;

    /**
     * Нормализует входной телефон. null — пустой/непригодный для хранения.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        if ($trimmed === '') {
            return null;
        }

        // Допустимы только цифры, разделители формата и ведущий «+»:
        // буквы и прочий мусор не должны превращаться в «номер».
        if (preg_match('/^\+?[0-9\s\-\(\)\.]*$/', $trimmed) !== 1) {
            return null;
        }

        $hasPlus = str_starts_with($trimmed, '+');
        $digits = preg_replace('/\D+/', '', $trimmed);

        if ($digits === null || $digits === '') {
            return null;
        }

        $length = strlen($digits);

        // 8XXXXXXXXXX → 7XXXXXXXXXX. Без «+» это российский домашний формат
        // (в т.ч. legacy «8495…»). С явным «+» конвертируем только мобильный
        // вид «89…» («+8 900…»); «+84…», «+90…» остаются международными.
        if ($length === 11 && $digits[0] === '8') {
            if (! $hasPlus || $digits[1] === '9') {
                return '7'.substr($digits, 1);
            }
        }

        // 7XXXXXXXXXX (RU E.164 без +) — уже канонично
        if ($length === 11 && $digits[0] === '7') {
            return $digits;
        }

        // XXXXXXXXXX (RU мобильный без префикса) → 7XXXXXXXXXX.
        // При явном «+» это может быть международный номер (например +90…),
        // поэтому с плюсом не превращаем.
        if ($length === 10 && $digits[0] === '9' && ! $hasPlus) {
            return '7'.$digits;
        }

        if ($length < self::MIN_DIGITS || $length > self::MAX_DIGITS) {
            return null;
        }

        return $digits;
    }

    /**
     * Все записываемые/поисковые варианты, которые должны сопоставляться
     * с уже существующими (возможно, ненормализованными) значениями phone.
     *
     * @return list<string>
     */
    public static function storageVariants(?string $normalized): array
    {
        if ($normalized === null || $normalized === '') {
            return [];
        }

        $variants = [$normalized, '+'.$normalized];

        if (preg_match('/^7\d{10}$/', $normalized) === 1) {
            $eightPrefixed = '8'.substr($normalized, 1);
            $variants[] = $eightPrefixed;
            $variants[] = '+'.$eightPrefixed;

            // Десятизначная российская мобильная форма («9001234567»).
            if (preg_match('/^79\d{9}$/', $normalized) === 1) {
                $variants[] = substr($normalized, 1);
            }
        }

        return array_values(array_unique($variants));
    }

    /**
     * «Голые» цифровые формы для сравнения с legacy-значениями,
     * в которых могли остаться пробелы, скобки и дефисы.
     *
     * @return list<string>
     */
    public static function digitForms(?string $normalized): array
    {
        if ($normalized === null || $normalized === '') {
            return [];
        }

        $forms = [$normalized];

        if (preg_match('/^7\d{10}$/', $normalized) === 1) {
            $forms[] = '8'.substr($normalized, 1);

            // Уже сохранённый 10-значный вид («9001234567») должен
            // сопоставляться с каноническим «79001234567» и наоборот.
            if (preg_match('/^79\d{9}$/', $normalized) === 1) {
                $forms[] = substr($normalized, 1);
            }
        }

        return array_values(array_unique($forms));
    }

    /**
     * Маска для логов/диагностики: не раскрывает полный номер.
     */
    public static function mask(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === null || $digits === '') {
            return '—';
        }

        $length = strlen($digits);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        $head = substr($digits, 0, min(3, $length - 2));
        $tail = substr($digits, -2);

        return $head.str_repeat('*', max(0, $length - 5)).$tail;
    }
}
