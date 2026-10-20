<?php

namespace Tests\Unit\Services;

use App\Services\Payment\NpdReceiptUrlValidator;
use PHPUnit\Framework\TestCase;

class NpdReceiptUrlValidatorTest extends TestCase
{
    private NpdReceiptUrlValidator $validator;

    private const INN = '5248061993';

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new NpdReceiptUrlValidator;
    }

    public function test_accepts_confirmed_user_sample_shape(): void
    {
        $url = 'https://lknpd.nalog.ru/api/v1/receipt/5248061993/200glz8o7h/print';

        $this->assertNull($this->validator->validate($url, self::INN));
    }

    public function test_accepts_twelve_digit_inn_and_longer_receipt_id(): void
    {
        $url = 'https://lknpd.nalog.ru/api/v1/receipt/7707083893/'.str_repeat('aB9', 40).'/print';

        $this->assertNull($this->validator->validate($url, '7707083893'));
    }

    public function test_rejects_http_scheme(): void
    {
        $error = $this->validator->validate(
            'http://lknpd.nalog.ru/api/v1/receipt/'.self::INN.'/abc/print',
            self::INN,
        );

        $this->assertNotNull($error);
    }

    public function test_rejects_foreign_host(): void
    {
        foreach ([
            'https://evil.com/api/v1/receipt/'.self::INN.'/abc/print',
            'https://lknpd.nalog.ru.evil.com/api/v1/receipt/'.self::INN.'/abc/print',
            'https://sub.lknpd.nalog.ru/api/v1/receipt/'.self::INN.'/abc/print',
            'https://lknpd-nalog.ru/api/v1/receipt/'.self::INN.'/abc/print',
        ] as $url) {
            $this->assertNotNull($this->validator->validate($url, self::INN), $url);
        }
    }

    public function test_rejects_userinfo_port_query_fragment(): void
    {
        $paths = [
            'https://user@lknpd.nalog.ru/api/v1/receipt/'.self::INN.'/abc/print',
            'https://user:pass@lknpd.nalog.ru/api/v1/receipt/'.self::INN.'/abc/print',
            'https://lknpd.nalog.ru:443/api/v1/receipt/'.self::INN.'/abc/print',
            'https://lknpd.nalog.ru:8443/api/v1/receipt/'.self::INN.'/abc/print',
            'https://lknpd.nalog.ru/api/v1/receipt/'.self::INN.'/abc/print?x=1',
            'https://lknpd.nalog.ru/api/v1/receipt/'.self::INN.'/abc/print#frag',
        ];

        foreach ($paths as $url) {
            $this->assertNotNull($this->validator->validate($url, self::INN), $url);
        }
    }

    public function test_rejects_wrong_path_shapes(): void
    {
        $paths = [
            'https://lknpd.nalog.ru/',
            'https://lknpd.nalog.ru/api/v1/receipt/'.self::INN.'/abc',
            'https://lknpd.nalog.ru/api/v1/receipt/'.self::INN.'/abc/print/',
            'https://lknpd.nalog.ru/api/v1/receipt/'.self::INN.'/abc/print/extra',
            'https://lknpd.nalog.ru/api/v2/receipt/'.self::INN.'/abc/print',
            'https://lknpd.nalog.ru/api/v1/receipt/print',
        ];

        foreach ($paths as $url) {
            $this->assertNotNull($this->validator->validate($url, self::INN), $url);
        }
    }

    public function test_rejects_receipt_id_with_slash_dot_or_encoding(): void
    {
        $paths = [
            'https://lknpd.nalog.ru/api/v1/receipt/'.self::INN.'/ab/c/print',
            'https://lknpd.nalog.ru/api/v1/receipt/'.self::INN.'/../x/print',
            'https://lknpd.nalog.ru/api/v1/receipt/'.self::INN.'/..%2Fx/print',
            'https://lknpd.nalog.ru/api/v1/receipt/'.self::INN.'/ab%2Fcd/print',
            'https://lknpd.nalog.ru/api/v1/receipt/'.self::INN.'/ab.cd/print',
            'https://lknpd.nalog.ru/api/v1/receipt/'.self::INN.'/ab cd/print',
            'https://lknpd.nalog.ru/api/v1/receipt/'.self::INN.'//print',
        ];

        foreach ($paths as $url) {
            $this->assertNotNull($this->validator->validate($url, self::INN), $url);
        }
    }

    public function test_rejects_foreign_seller_inn_in_path(): void
    {
        $error = $this->validator->validate(
            'https://lknpd.nalog.ru/api/v1/receipt/7707083893/abc/print',
            self::INN,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('ИНН', $error);
    }

    public function test_rejects_any_url_when_configured_inn_missing_or_malformed(): void
    {
        $url = 'https://lknpd.nalog.ru/api/v1/receipt/'.self::INN.'/abc/print';

        foreach (['', '   ', '123', 'not-a-number', '12345678901'] as $inn) {
            $error = $this->validator->validate($url, $inn);
            $this->assertNotNull($error, "inn='{$inn}'");
            $this->assertStringContainsString('ИНН', $error);
        }
    }

    public function test_rejects_empty_url(): void
    {
        $this->assertNotNull($this->validator->validate('', self::INN));
        $this->assertNotNull($this->validator->validate('   ', self::INN));
    }
}
