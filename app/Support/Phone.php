<?php

namespace App\Support;

/**
 * Единая серверная нормализация телефонов для поиска, создания и изменения.
 *
 * Каноническая форма — цифры без форматирования:
 * - российские 8/7/10-значные мобильные приводятся к 7XXXXXXXXXX;
 * - иностранные/невалидные номера не «российизируются».
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

        $digits = preg_replace('/\D+/', '', $raw);

        if ($digits === null || $digits === '') {
            return null;
        }

        // 8XXXXXXXXXX (RU) → 7XXXXXXXXXX
        if (strlen($digits) === 11 && $digits[0] === '8') {
            return '7'.substr($digits, 1);
        }

        // 7XXXXXXXXXX (RU E.164 без +) — уже канонично
        if (strlen($digits) === 11 && $digits[0] === '7') {
            return $digits;
        }

        // XXXXXXXXXX (RU мобильный без префикса) → 7XXXXXXXXXX
        if (strlen($digits) === 10 && $digits[0] === '9') {
            return '7'.$digits;
        }

        $length = strlen($digits);

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
