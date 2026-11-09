<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use DateTimeImmutable;
use DateTimeZone;
use LogiDock\Service\AgingRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La regola dell'avviso di giacenza con date fisse: "oggi" è lunedì 9 novembre 2026, soglia 2 giorni.
 */
final class AgingRuleTest extends TestCase
{
    private function rule(int $thresholdDays = 2): AgingRule
    {
        return new AgingRule($thresholdDays, new DateTimeImmutable('2026-11-09 18:45:00', new DateTimeZone('Europe/Rome')));
    }

    #[DataProvider('daysProvider')]
    public function testDaysInStockCountsCalendarDays(string $receivedAt, int $expectedDays): void
    {
        self::assertSame($expectedDays, $this->rule()->daysInStock($receivedAt));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function daysProvider(): array
    {
        return [
            'arrivato oggi' => ['2026-11-09 00:00:00', 0],
            'arrivato oggi, con l\'ora' => ['2026-11-09 17:00:00', 0],
            'ieri sera tardi: conta il giorno, non le ore' => ['2026-11-08 23:59:59', 1],
            'solo la data' => ['2026-11-06', 3],
            'prima del cambio dell\'ora legale (25/10)' => ['2026-10-20 00:00:00', 20],
            'nel futuro conta zero' => ['2026-11-12 00:00:00', 0],
        ];
    }

    public function testAPalletIsAgingOnlyAfterMoreDaysThanTheThreshold(): void
    {
        $rule = $this->rule(2);

        self::assertFalse($rule->isAging('2026-11-08 08:00:00'), '1 giorno');
        self::assertFalse($rule->isAging('2026-11-07 08:00:00'), '2 giorni = soglia: non ancora');
        self::assertTrue($rule->isAging('2026-11-06 08:00:00'), '3 giorni: fermo');
    }

    public function testTheThresholdComesFromTheConstructor(): void
    {
        self::assertFalse($this->rule(5)->isAging('2026-11-06 08:00:00'), '3 giorni con soglia 5');
        self::assertTrue($this->rule(5)->isAging('2026-11-03 08:00:00'), '6 giorni con soglia 5');
        self::assertSame(5, $this->rule(5)->thresholdDays);
    }

    public function testReceivedBeforeAgreesWithIsAging(): void
    {
        $rule = $this->rule(2);

        self::assertSame('2026-11-07', $rule->receivedBefore());

        // Il database usa "received_at < receivedBefore": deve dare la stessa risposta di isAging().
        foreach (['2026-11-05 10:00:00', '2026-11-06 23:59:59', '2026-11-07 00:00:00', '2026-11-08 12:00:00'] as $receivedAt) {
            self::assertSame(
                $rule->isAging($receivedAt),
                $receivedAt < $rule->receivedBefore() . ' 00:00:00',
                $receivedAt
            );
        }
    }

    public function testReceivedBeforeCrossesMonthsAndDaylightSavingChanges(): void
    {
        $rule = new AgingRule(10, new DateTimeImmutable('2026-11-02', new DateTimeZone('Europe/Rome')));

        self::assertSame('2026-10-23', $rule->receivedBefore());
    }
}
