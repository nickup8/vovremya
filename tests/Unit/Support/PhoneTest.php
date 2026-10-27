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
            'us number unchanged' => ['+1 415 555 2671', '14155552671'],
            'too short' => ['123', null],
            'too long' => ['1234567890123456', null],
            'letters are not a phone' => ['call 9001234567', null],
            'letters mixed with digits' => ['900abc1234567', null],
            'garbage only' => ['---()', null],
            'extension suffix rejected' => ['9001234567 ext.5', null],
            'vietnam plus84 not russianized' => ['+84912345678', '84912345678'],
            'turkey plus90 ten digits not russianized' => ['+9012345678', '9012345678'],
            'turkey plus90 full not russianized' => ['+90 532 123 45 67', '905321234567'],
            'plus84 eleven digits without mobile second digit' => ['+8 491 234 5678', '84912345678'],
        ];
    }

    #[DataProvider('normalizeProvider')]
    public function test_normalize(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, Phone::normalize($input));
    }

    public function test_does_not_convert_foreign_number_to_russian(): void
    {
        $this->assertSame('14155552671', Phone::normalize('+1 415 555 2671'));
        $this->assertNotSame('74155552671', Phone::normalize('+1 415 555 2671'));
    }

    public function test_explicit_international_prefixes_are_not_russianized(): void
    {
        $this->assertSame('84912345678', Phone::normalize('+84 912 345 678'));
        $this->assertSame('905321234567', Phone::normalize('+90 532 123 45 67'));
        $this->assertSame('9012345678', Phone::normalize('+9012345678'));
        $this->assertStringStartsWith('84', Phone::normalize('+84912345678'));
        $this->assertStringStartsWith('90', Phone::normalize('+9012345678'));
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

    public function test_digit_forms_include_ten_digit_russian_mobile_form(): void
    {
        $forms = Phone::digitForms('79001234567');

        $this->assertContains('79001234567', $forms);
        $this->assertContains('89001234567', $forms);
        $this->assertContains('9001234567', $forms);
    }

    public function test_mask_hides_middle_digits(): void
    {
        $masked = Phone::mask('79001234567');

        $this->assertStringNotContainsString('0012345', $masked);
        $this->assertStringEndsWith('67', $masked);
        $this->assertStringStartsWith('790', $masked);
    }
}
