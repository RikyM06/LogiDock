<?php

declare(strict_types=1);

namespace LogiDock\Tests\Core;

use InvalidArgumentException;
use LogiDock\Core\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function testParseAndPrint(): void
    {
        $version = Version::parse(" 0.0.10.0\n");

        self::assertSame([0, 0, 10, 0], [$version->major, $version->minor, $version->phase, $version->commit]);
        self::assertSame('0.0.10.0', $version->toString());
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidVersionsAreRejected(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);

        Version::parse($text);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'vuota' => [''],
            'tre numeri (semver)' => ['1.2.3'],
            'cinque numeri' => ['0.0.10.0.1'],
            'con la v davanti' => ['v0.0.10.0'],
            'lettere' => ['0.0.dieci.0'],
            'negativo' => ['0.0.-1.0'],
        ];
    }

    public function testEveryCommitAddsOneToTheLastNumber(): void
    {
        $version = Version::parse('0.0.10.0')->nextCommit()->nextCommit();

        self::assertSame('0.0.10.2', $version->toString());
    }

    public function testNewPhaseResetsTheLastNumber(): void
    {
        self::assertSame('0.0.11.0', Version::parse('0.0.10.7')->startPhase(11)->toString());
    }

    public function testPhaseCannotGoBackOrStayTheSame(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Version::parse('0.0.10.3')->startPhase(10);
    }

    public function testTheProjectVersionFileIsValid(): void
    {
        $version = Version::fromFile(dirname(__DIR__, 2) . '/VERSION');

        self::assertGreaterThanOrEqual(10, $version->phase);
    }
}
