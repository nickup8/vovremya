<?php

namespace Tests\Unit\Support;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function normalizeProvider(): array
    {
        return [
            'empty' => [null, null],
            'blank' => ['   ', null],
            'ru with plus and mask' => ['+7 (900) 123-45-67', '79001234567'],
            'ru digits with 7' => ['79001234567', '79001234567'],
            'ru digits with 8' => ['89001234567', '79001234567'],
            'ru with 8 and mask' => ['8 (900) 123-45-67', '79001234567'],
            'ru without prefix' => ['9001234567', '79001234567'],
            'ru with dashes' => ['7-900-123-45-67', '79001234567'],
            'ru with spaces and parens' => ['8 (900) 123 45 67', '79001234567'],
            'us number keeps plus' => ['+1 415 555 2671', '+14155552671'],
            'too short' => ['123', null],
            'too long' => ['1234567890123456', null],
            'letters are not a phone' => ['call 9001234567', null],
            'letters mixed with digits' => ['900abc1234567', null],
            'garbage only' => ['---()', null],
            'extension suffix rejected' => ['9001234567 ext.5', null],
            'vietnam plus84 not russianized' => ['+84912345678', '+84912345678'],
            'vietnam plus84 with spaces keeps plus' => ['+84 912 345 678', '+84912345678'],
            'turkey plus90 ten digits not russianized' => ['+9012345678', '+9012345678'],
            'turkey plus90 full not russianized' => ['+90 532 123 45 67', '+905321234567'],
            'plus84 eleven digits without mobile second digit' => ['+8 491 234 5678', '+84912345678'],
        ];
    }

    #[DataProvider('normalizeProvider')]
    public function test_normalize(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, Phone::normalize($input));
    }

    /**
     * Хранящееся значение никогда не переинтерпретируется повторно:
     * международный номер с «+» не становится российским после
     * контроллера, сервиса, хранения и повторного поиска.
     *
     * @return array<string, array{0: string}>
     */
    public static function storedCanonicalProvider(): array
    {
        return [
            'international +84' => ['+84912345678'],
            'international +90' => ['+905321234567'],
            'international +1' => ['+14155552671'],
            'russian 7' => ['79001234567'],
            'russian 8-landline canonical' => ['74951234567'],
            'bare international stable' => ['14155552671'],
        ];
    }

    #[DataProvider('storedCanonicalProvider')]
    public function test_normalize_is_idempotent_for_stored_forms(string $stored): void
    {
        $once = Phone::normalize($stored);

        $this->assertNotNull($once);
        $this->assertSame($stored, $once, 'хранящееся значение должно быть каноническим');
        $this->assertSame($once, Phone::normalize($once), 'повторная нормализация не меняет представление');
    }

    public function test_international_plus84_is_never_russianized_on_round_trip(): void
    {
        $canonical = Phone::normalize('+84 912 345 678');

        $this->assertSame('+84912345678', $canonical);
        $this->assertSame('+84912345678', Phone::normalize($canonical));
        $this->assertNotSame('74912345678', $canonical);
        $this->assertNotSame('74912345678', Phone::normalize($canonical));
    }

    public function test_bare_eight_prefix_is_still_russian_legacy(): void
    {
        // Голая форма однозначно российская (legacy «8495…», «8491…»).
        $this->assertSame('74912345678', Phone::normalize('84912345678'));
        $this->assertSame('74951234567', Phone::normalize('84951234567'));
    }

    public function test_storage_variants_include_plus_eight_and_ten_digit_forms(): void
    {
        $variants = Phone::storageVariants('79001234567');

        $this->assertContains('79001234567', $variants);
        $this->assertContains('+79001234567', $variants);
        $this->assertContains('89001234567', $variants);
        $this->assertContains('+89001234567', $variants);
        $this->assertContains('9001234567', $variants);
    }

    public function test_storage_variants_for_russian_8landline_exclude_international_plus_form(): void
    {
        // «84912345678» — восьмичастная форма российского «74912345678»,
        // но её международный вид «+84912345678» — другой номер и в
        // варианты российского поиска не подмешивается.
        $variants = Phone::storageVariants('74912345678');

        $this->assertContains('74912345678', $variants);
        $this->assertContains('+74912345678', $variants);
        $this->assertContains('84912345678', $variants);
        $this->assertNotContains('+84912345678', $variants);
    }

    public function test_storage_variants_for_international_keep_plus_and_drop_double_plus(): void
    {
        $variants = Phone::storageVariants('+84912345678');

        $this->assertContains('+84912345678', $variants);
        $this->assertNotContains('++84912345678', $variants);
        // Голые цифры «84912345678» нормализуются как российский номер,
        // поэтому не являются вариантом международного поиска.
        $this->assertNotContains('84912345678', $variants);
        $this->assertNotContains('74912345678', $variants);
    }

    public function test_digit_forms_include_ten_digit_russian_mobile_form(): void
    {
        $forms = Phone::digitForms('79001234567');

        $this->assertContains('79001234567', $forms);
        $this->assertContains('89001234567', $forms);
        $this->assertContains('9001234567', $forms);
    }

    public function test_digit_forms_keep_significant_plus_for_international(): void
    {
        $forms = Phone::digitForms('+84912345678');

        $this->assertContains('+84912345678', $forms);
        $this->assertNotContains('74912345678', $forms);
    }

    public function test_same_number_matches_storage_forms_of_one_canonical(): void
    {
        $this->assertTrue(Phone::sameNumber('79001234567', '79001234567'));
        $this->assertTrue(Phone::sameNumber('+7 (900) 123-45-67', '79001234567'));
        $this->assertTrue(Phone::sameNumber('89001234567', '79001234567'));
        $this->assertTrue(Phone::sameNumber('900 123-45-67', '79001234567'));
        $this->assertTrue(Phone::sameNumber('14155552671', '+14155552671'));

        $this->assertFalse(Phone::sameNumber('74912345678', '+84912345678'));
        $this->assertFalse(Phone::sameNumber('84912345678', '+84912345678'));
        $this->assertFalse(Phone::sameNumber('9001234567', '+9001234567'));
        $this->assertFalse(Phone::sameNumber(null, '79001234567'));
    }

    public function test_mask_hides_middle_digits(): void
    {
        $masked = Phone::mask('79001234567');

        $this->assertStringNotContainsString('0012345', $masked);
        $this->assertStringEndsWith('67', $masked);
        $this->assertStringStartsWith('790', $masked);
    }
}
