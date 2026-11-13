<?php

declare(strict_types=1);

namespace LogiDock\Tests\Core;

use LogiDock\Core\HistoryUrl;
use LogiDock\Core\ReturnUrl;
use PHPUnit\Framework\TestCase;

final class HistoryUrlTest extends TestCase
{
    public function testUnknownTabFallsBackToTrips(): void
    {
        self::assertSame('viaggi', HistoryUrl::tab('boh'));
        self::assertSame('viaggi', HistoryUrl::tab(['incassi']));
        self::assertSame('viaggi', HistoryUrl::tab(null));
        self::assertSame('incassi', HistoryUrl::tab('incassi'));
    }

    public function testBuildKeepsOnlyTheFiltersOfTheTab(): void
    {
        $filters = [
            'from' => '2026-11-01', 'to' => null, 'round' => 3, 'status' => null,
            'supplier' => 9, 'customer' => 4, 'payment' => 'unpaid', 'type' => 'ddt',
        ];

        self::assertSame(
            '/storico?scheda=incassi&dal=2026-11-01&cliente=4&pagamento=unpaid',
            HistoryUrl::build('incassi', $filters)
        );
        self::assertSame('/storico?scheda=viaggi&dal=2026-11-01&giro=3', HistoryUrl::build('viaggi', $filters));
    }

    public function testBuiltUrlIsAcceptedAsReturnTarget(): void
    {
        $url = HistoryUrl::build('incassi', ['payment' => 'unpaid', 'from' => '2026-11-01']);

        self::assertSame($url, ReturnUrl::pick($url, '/orders'));
    }
}
