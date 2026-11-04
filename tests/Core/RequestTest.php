<?php

declare(strict_types=1);

namespace LogiDock\Tests\Core;

use LogiDock\Core\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function pathProvider(): array
    {
        return [
            'radice' => ['/', '/'],
            'senza slash iniziale' => ['products', '/products'],
            'slash finale' => ['/products/', '/products'],
            'percorso annidato' => ['/products/3/edit', '/products/3/edit'],
        ];
    }

    #[DataProvider('pathProvider')]
    public function testPathIsNormalized(string $raw, string $expected): void
    {
        self::assertSame($expected, (new Request('GET', $raw))->path());
    }

    public function testMethodIsUppercased(): void
    {
        self::assertSame('POST', (new Request('post', '/'))->method());
    }

    public function testInputAndQueryFallBackToTheDefault(): void
    {
        $request = new Request('POST', '/', ['q' => 'viti'], ['code' => 'ABC']);

        self::assertSame('viti', $request->query('q'));
        self::assertSame('ABC', $request->input('code'));
        self::assertNull($request->input('assente'));
        self::assertSame('x', $request->query('assente', 'x'));
        self::assertSame(['code' => 'ABC'], $request->allInput());
    }
}
