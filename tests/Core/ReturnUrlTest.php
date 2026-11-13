<?php

declare(strict_types=1);

namespace LogiDock\Tests\Core;

use LogiDock\Core\ReturnUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReturnUrlTest extends TestCase
{
    public function testHistoryPathsAreAccepted(): void
    {
        self::assertSame('/storico', ReturnUrl::pick('/storico', '/orders/1'));
        self::assertSame(
            '/storico?scheda=incassi&dal=2026-11-01',
            ReturnUrl::pick('/storico?scheda=incassi&dal=2026-11-01', '/orders/1')
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function refusedValues(): array
    {
        return [
            'external url' => ['https://evil.test'],
            'protocol relative' => ['//evil.test'],
            'other page' => ['/trips/1'],
            'history lookalike' => ['/storico-finto'],
            'history with host trick' => ['/storico@evil.test'],
            'backslash' => ['/storico?x=\\evil.test'],
            'newline' => ["/storico?x=1\r\nSet-Cookie: a=b"],
            'empty' => [''],
            'array' => [['/storico']],
            'null' => [null],
        ];
    }

    #[DataProvider('refusedValues')]
    public function testEverythingElseFallsBackToTheDefault(mixed $value): void
    {
        self::assertSame('/orders/1', ReturnUrl::pick($value, '/orders/1'));
    }
}
