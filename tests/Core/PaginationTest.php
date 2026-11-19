<?php

declare(strict_types=1);

namespace LogiDock\Tests\Core;

use LogiDock\Core\Pagination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    /**
     * @return array<string, array{mixed, int}>
     */
    public static function requestedPages(): array
    {
        return [
            'normale' => ['2', 2],
            'intero' => [3, 3],
            'zero' => ['0', 1],
            'negativa' => ['-4', 1],
            'testo' => ['abc', 1],
            'decimale' => ['2.5', 1],
            'assente' => [null, 1],
            'vettore' => [['x'], 1],
            'oltre l\'ultima' => ['99', 5],
            'enorme' => ['99999999999999999999', 1],
        ];
    }

    #[DataProvider('requestedPages')]
    public function testDirtyPageNumbersFallBackToAValidPage(mixed $requested, int $expected): void
    {
        $pagination = new Pagination(110, 25, $requested);

        self::assertSame(5, $pagination->pages);
        self::assertSame($expected, $pagination->page);
    }

    public function testOffsetAndNeighbours(): void
    {
        $pagination = new Pagination(110, 25, '3');

        self::assertSame(50, $pagination->offset());
        self::assertTrue($pagination->hasPrevious());
        self::assertTrue($pagination->hasNext());
        self::assertFalse((new Pagination(110, 25, 1))->hasPrevious());
        self::assertFalse((new Pagination(110, 25, 5))->hasNext());
    }

    public function testNoResultsIsOneEmptyPage(): void
    {
        $pagination = new Pagination(0, 25, '4');

        self::assertSame(1, $pagination->pages);
        self::assertSame(1, $pagination->page);
        self::assertSame(0, $pagination->offset());
        self::assertFalse($pagination->hasNext());
    }

    public function testExactMultipleDoesNotCreateAnEmptyLastPage(): void
    {
        self::assertSame(4, (new Pagination(100, 25, 1))->pages);
        self::assertSame(5, (new Pagination(101, 25, 1))->pages);
    }

    public function testUrlKeepsFiltersAndDropsEmptyOnes(): void
    {
        $query = ['q' => 'olio', 'status' => '', 'pagina' => '2'];

        self::assertSame('/orders?q=olio&pagina=3', Pagination::url('/orders', $query, 3));
        self::assertSame('/orders?q=olio', Pagination::url('/orders', $query, 1));
        self::assertSame('/orders', Pagination::url('/orders', ['pagina' => '2'], 1));
        self::assertSame('/stock?aging=1&pagina=2#bancali', Pagination::url('/stock', ['aging' => '1'], 2, 'bancali'));
    }
}
