<?php
declare(strict_types=1);

namespace Tests;

use App\Services\ProductImportService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProductImportService pure methods (no network I/O).
 *
 * The curl/DNS fetch paths (fetch(), fetchImageDataUri(), resolveAndValidate())
 * are the SSRF guard's I/O layer; they require real network and are verified by
 * post-deploy smoke tests (documented in 10-VALIDATION.md), not here. What IS
 * covered here is the security-critical pure logic: the per-IP SSRF allow/deny
 * (isPublicIp), the HTML extraction (parse), and the currency conversion
 * (convert) — the layer phpunit.xml scopes for coverage (src/Services/).
 */
final class ProductImportServiceTest extends TestCase
{
    // ---- SSRF IP guard (isPublicIp) ---------------------------------------
    public function testIsPublicIpRejectsLoopback(): void
    {
        $this->assertSame(false, (new ProductImportService())->isPublicIp('127.0.0.1'));
    }

    public function testIsPublicIpRejectsPrivateRanges(): void
    {
        $service = new ProductImportService();
        $this->assertSame(false, $service->isPublicIp('10.0.0.1'));      // 10/8
        $this->assertSame(false, $service->isPublicIp('172.16.0.1'));    // 172.16/12
        $this->assertSame(false, $service->isPublicIp('192.168.1.1'));   // 192.168/16
    }

    public function testIsPublicIpRejectsMetadataAddress(): void
    {
        // Cloud metadata (AWS/GCP) — explicit denial + link-local 169.254/16.
        $this->assertSame(false, (new ProductImportService())->isPublicIp('169.254.169.254'));
    }

    public function testIsPublicIpRejectsIpv6Loopback(): void
    {
        $this->assertSame(false, (new ProductImportService())->isPublicIp('::1'));
    }

    public function testIsPublicIpRejectsIpv6Ula(): void
    {
        // fc00::/7 unique-local address.
        $this->assertSame(false, (new ProductImportService())->isPublicIp('fc00::1'));
    }

    public function testIsPublicIpRejectsIpv4MappedBypass(): void
    {
        // ::ffff:127.0.0.1 embeds loopback; the filter flags miss it → explicit deny.
        $this->assertSame(false, (new ProductImportService())->isPublicIp('::ffff:127.0.0.1'));
    }

    public function testIsPublicIpAcceptsPublic(): void
    {
        $this->assertSame(true, (new ProductImportService())->isPublicIp('8.8.8.8'));
    }

    // ---- HTML extraction (parse) ------------------------------------------
    public function testParseExtractsOpenGraph(): void
    {
        $html = '<!DOCTYPE html><html><head>'
            . '<meta property="og:title" content="Gadget Pro X">'
            . '<meta property="og:price:amount" content="39.99">'
            . '<meta property="og:price:currency" content="USD">'
            . '<meta property="og:image" content="https://cdn.example.com/gadget.jpg">'
            . '</head><body></body></html>';

        $result = (new ProductImportService())->parse($html);
        $this->assertSame('Gadget Pro X', $result['name']);
        $this->assertSame('39.99', $result['price']);
        $this->assertSame('USD', $result['currency']);
        $this->assertSame('https://cdn.example.com/gadget.jpg', $result['image_url']);
    }

    public function testParseFallsBackToTitle(): void
    {
        // No og:title — name must fall back to the <title> text.
        $html = '<!DOCTYPE html><html><head><title>Joli Produit</title></head><body></body></html>';

        $result = (new ProductImportService())->parse($html);
        $this->assertSame('Joli Produit', $result['name']);
        $this->assertSame(null, $result['price']);
        $this->assertSame(null, $result['currency']);
        $this->assertSame(null, $result['image_url']);
    }

    public function testParseEmptyReturnsNulls(): void
    {
        $result = (new ProductImportService())->parse('');
        $this->assertSame(null, $result['name']);
        $this->assertSame(null, $result['price']);
        $this->assertSame(null, $result['currency']);
        $this->assertSame(null, $result['image_url']);
    }

    public function testParseGarbageReturnsNulls(): void
    {
        // Malformed input must not throw and must yield all-null keys.
        $result = (new ProductImportService())->parse('<garbage><<>not html');
        $this->assertSame(null, $result['name']);
        $this->assertSame(null, $result['price']);
        $this->assertSame(null, $result['currency']);
        $this->assertSame(null, $result['image_url']);
    }

    // ---- Currency conversion (convert) ------------------------------------
    public function testConvertWithInjectedRate(): void
    {
        // 39.99 * 0.92 = 36.7908 -> 36.79
        $result = (new ProductImportService())->convert(39.99, 'USD', 0.92);
        $this->assertSame(39.99, $result['price']);
        $this->assertSame(36.79, $result['converted_eur']);
        $this->assertSame(false, $result['needs_verify']);
    }

    public function testConvertUnknownCurrencyFlagsNeedsVerify(): void
    {
        // Currency unknown → keep raw value, flag for verification (no mislabel).
        $result = (new ProductImportService())->convert(39.99, null, null);
        $this->assertSame(39.99, $result['price']);
        $this->assertSame(null, $result['converted_eur']);
        $this->assertSame(true, $result['needs_verify']);
    }

    public function testConvertNullRateFlagsNeedsVerify(): void
    {
        // FX failed (rate null) → keep raw value, flag for verification.
        $result = (new ProductImportService())->convert(39.99, 'USD', null);
        $this->assertSame(39.99, $result['price']);
        $this->assertSame(null, $result['converted_eur']);
        $this->assertSame(true, $result['needs_verify']);
    }
}
