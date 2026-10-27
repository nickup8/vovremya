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
            'us number unchanged' => ['+1 415 555 2671', '14155552671'],
            'too short' => ['123', null],
            'too long' => ['1234567890123456', null],
        ];
    }

    #[DataProvider('normalizeProvider')]
    public function test_normalize(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, Phone::normalize($input));
    }

    public function test_does_not_convert_foreign_number_to_russian(): void
    {
        $this->assertSame('14155552671', Phone::normalize('14155552671'));
        $this->assertNotSame('74155552671', Phone::normalize('14155552671'));
    }

    public function test_storage_variants_include_plus_and_eight(): void
    {
        $variants = Phone::storageVariants('79001234567');

        $this->assertContains('79001234567', $variants);
        $this->assertContains('+79001234567', $variants);
        $this->assertContains('89001234567', $variants);
        $this->assertContains('+89001234567', $variants);
    }

    public function test_mask_hides_middle_digits(): void
    {
        $masked = Phone::mask('79001234567');

        $this->assertStringNotContainsString('0012345', $masked);
        $this->assertStringEndsWith('67', $masked);
        $this->assertStringStartsWith('790', $masked);
    }
}
