<?php

declare(strict_types=1);

namespace LogiDock\Tests\Core;

use InvalidArgumentException;
use LogiDock\Core\Thousandths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThousandthsTest extends TestCase
{
    #[DataProvider('decimalsProvider')]
    public function testFromDecimalConvertsToWholeThousandths(string $decimal, int $expected): void
    {
        self::assertSame($expected, Thousandths::fromDecimal($decimal));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function decimalsProvider(): array
    {
        return [
            'intero' => ['12', 12000],
            'un decimale' => ['12.5', 12500],
            'due decimali (come DECIMAL(10,2))' => ['6500.00', 6500000],
            'tre decimali (come DECIMAL(12,3))' => ['0.350', 350],
            'zero' => ['0.000', 0],
            'negativo' => ['-1.250', -1250],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testFromDecimalRejectsAnythingElse(string $invalid): void
    {
        $this->expectException(InvalidArgumentException::class);

        Thousandths::fromDecimal($invalid);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'vuoto' => [''],
            'virgola italiana' => ['12,5'],
            'quattro decimali' => ['1.2345'],
            'testo' => ['abc'],
            'senza parte intera' => ['.5'],
            'spazi' => [' 12 '],
        ];
    }

    public function testToDecimalAlwaysWritesThreeDecimals(): void
    {
        self::assertSame('12.500', Thousandths::toDecimal(12500));
        self::assertSame('0.007', Thousandths::toDecimal(7));
        self::assertSame('0.000', Thousandths::toDecimal(0));
        self::assertSame('-1.250', Thousandths::toDecimal(-1250));
    }

    public function testSumsAreExactWhereFloatsAreNot(): void
    {
        // Con i float 0.1 + 0.2 non è uguale a 0.3; con i millesimi interi sì.
        $sum = Thousandths::fromDecimal('0.1') + Thousandths::fromDecimal('0.2');

        self::assertSame('0.300', Thousandths::toDecimal($sum));
    }
}
