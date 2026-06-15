<?php
declare(strict_types=1);

namespace Tests;

use App\Services\ExchangeRateService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ExchangeRateService identity path (no network I/O).
 *
 * The curl/HTTP failure paths are verified by post-deploy smoke tests
 * (documented in 06-VALIDATION.md) — they require network/DB I/O and
 * are intentionally not scaffolded here per the Nyquist principle.
 */
final class ExchangeRateServiceTest extends TestCase
{
    public function testLatestEurToEurReturnsOnePointZero(): void
    {
        $service = new ExchangeRateService();
        $this->assertSame(1.0, $service->latest('EUR', 'EUR'));
    }

    public function testLatestUsdToUsdReturnsOnePointZero(): void
    {
        $service = new ExchangeRateService();
        $this->assertSame(1.0, $service->latest('USD', 'USD'));
    }

    public function testLatestLowercaseIdentityReturnsOnePointZero(): void
    {
        $service = new ExchangeRateService();
        $this->assertSame(1.0, $service->latest('usd', 'usd'));
    }
}
