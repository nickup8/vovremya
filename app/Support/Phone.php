<?php

namespace App\Support;

/**
 * Единая серверная нормализация телефонов для поиска, создания и изменения.
 *
 * Однозначное представление номера:
 * - российские 8/7/10-значные формы хранятся голыми цифрами 7XXXXXXXXXX;
 * - явно международные номера (+84, +90, +1 …) хранятся со знаком «+»,
 *   поэтому повторная нормализация хранящегося значения никогда не
 *   переинтерпретирует его как российский;
 * - буквы и произвольный мусор не принимаются как номер.
 */
final class Phone
{
    public const MIN_DIGITS = 7;

    public const MAX_DIGITS = 15;

    /**
     * Нормализует входной телефон. null — пустой/непригодный для хранения.
     * Функция идемпотентна: normalize(normalize(x)) === normalize(x).
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

        // Международный номер: значимый «+» сохраняется в канонической
        // форме, иначе повторная нормализация «84912345678» превратила бы
        // его в российский «74912345678».
        return $hasPlus ? '+'.$digits : $digits;
    }

    /**
     * Хранится ли $stored тем же номером, что и канонический $canonical:
     * точное совпадение канона либо однозначная голая форма
     * («14155552671» для «+14155552671»), никогда не означающая другое.
     */
    public static function sameNumber(?string $stored, string $canonical): bool
    {
        $normalized = self::normalize($stored);

        if ($normalized === null) {
            return false;
        }

        if ($normalized === $canonical) {
            return true;
        }

        if (! str_starts_with($canonical, '+')) {
            return false;
        }

        $bare = substr($canonical, 1);

        return $normalized === $bare && self::normalize($bare) === $bare;
    }

    /**
     * Все записываемые/поисковые варианты, которые должны сопоставляться
     * с уже существующими (возможно, ненормализованными) значениями phone.
     *
     * Кандидат включается только тогда, когда его повторная нормализация
     * даёт тот же номер: это исключает переинтерпретацию (например,
     * «84912345678» с плюсом не подмешивается к российскому «74912345678»).
     *
     * @return list<string>
     */
    public static function storageVariants(?string $normalized): array
    {
        if ($normalized === null || $normalized === '') {
            return [];
        }

        $candidates = [$normalized];

        if (str_starts_with($normalized, '+')) {
            // Голая форма международного номера (legacy-хранилище).
            $candidates[] = substr($normalized, 1);
        } elseif (preg_match('/^7\d{10}$/', $normalized) === 1) {
            // Восьмичастный и десятизначный российские legacy-формы.
            $candidates[] = '8'.substr($normalized, 1);
            $candidates[] = substr($normalized, 1);
        }

        $variants = [];

        foreach ($candidates as $candidate) {
            if ($candidate === ''
                || self::normalize($candidate) === $candidate
                || self::normalize($candidate) === $normalized
            ) {
                $variants[] = $candidate;

                if (! str_starts_with($candidate, '+')) {
                    $plusForm = '+'.$candidate;

                    if (self::normalize($plusForm) === $normalized) {
                        $variants[] = $plusForm;
                    }
                }
            }

        }

        return array_values(array_unique($variants));
    }

    /**
     * Формы для сравнения с legacy-значениями phone, в которых могли
     * остаться пробелы, скобки, дефисы и значимый ведущий «+»
     * (regexp_replace оставляет «+», поэтому формы включают и его).
     *
     * @return list<string>
     */
    public static function digitForms(?string $normalized): array
    {
        return self::storageVariants($normalized);
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
