<?php

namespace App\Services\Payment;

/**
 * Validates a manually issued NPD («Моё налоговое дело») receipt link.
 *
 * Accepted shape (confirmed by a real user link):
 *   https://lknpd.nalog.ru/api/v1/receipt/{sellerInn}/{receiptId}/print
 *
 * Rules:
 * - https only;
 * - exact host lknpd.nalog.ru (no subdomain/suffix tricks);
 * - no userinfo, no explicit port, no query, no fragment;
 * - path is exactly /api/v1/receipt/{sellerInn}/{receiptId}/print;
 * - sellerInn must equal the configured seller INN;
 * - receiptId is a non-empty run of safe URL path characters
 *   (letters, digits, «_», «-») — no slashes, no path traversal,
 *   length not fixed from a single sample.
 *
 * Passing this validation does NOT prove the receipt exists, matches the
 * payment amount, or was registered with the FNS — that is entirely the
 * responsibility of the manual issuance process.
 */
class NpdReceiptUrlValidator
{
    public const HOST = 'lknpd.nalog.ru';

    private const PATH_PATTERN = '#^/api/v1/receipt/([0-9]{10}|[0-9]{12})/([A-Za-z0-9_-]+)/print$#';

    /**
     * @param  string  $url  candidate receipt URL as entered by the administrator
     * @param  string  $configuredSellerInn  billing.receipts.seller_inn
     * @return string|null human-readable error, or null when the URL is acceptable
     */
    public function validate(string $url, string $configuredSellerInn): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return 'Ссылка на чек не указана.';
        }

        if (! $this->isPlausibleSellerInn($configuredSellerInn)) {
            return 'ИНН продавца не настроен или некорректен. Прикрепление чека недоступно.';
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'Ссылка на чек должна быть полным URL вида https://lknpd.nalog.ru/api/v1/receipt/…/print.';
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return 'Ссылка на чек должна использовать HTTPS.';
        }

        if (strtolower($parts['host']) !== self::HOST) {
            return 'Ссылка на чек должна вести на '.self::HOST.'.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Ссылка на чек не должна содержать userinfo.';
        }

        if (isset($parts['port'])) {
            return 'Ссылка на чек не должна содержать явный порт.';
        }

        if (isset($parts['query'])) {
            return 'Ссылка на чек не должна содержать query-строку.';
        }

        if (isset($parts['fragment'])) {
            return 'Ссылка на чек не должна содержать fragment.';
        }

        $path = $parts['path'] ?? '';

        if (! preg_match(self::PATH_PATTERN, $path, $matches)) {
            return 'Путь ссылки на чек должен быть /api/v1/receipt/{ИНН}/{id_чека}/print.';
        }

        if ($matches[1] !== trim($configuredSellerInn)) {
            return 'ИНН в ссылке на чек не совпадает с настроенным ИНН продавца.';
        }

        // $matches[2] is receiptId: non-empty, safe path chars only —
        // guaranteed by the pattern (no slashes, no «.», no «%»).
        return null;
    }

    public function isPlausibleSellerInn(string $inn): bool
    {
        return preg_match('/^[0-9]{10}$|^[0-9]{12}$/', trim($inn)) === 1;
    }
}
