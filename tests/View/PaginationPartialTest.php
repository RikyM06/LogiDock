<?php

declare(strict_types=1);

namespace LogiDock\Tests\View;

use LogiDock\Core\Pagination;
use LogiDock\Core\View;
use PHPUnit\Framework\TestCase;

/**
 * Il partial della paginazione, renderizzato da solo (senza layout) con il template vero.
 */
final class PaginationPartialTest extends TestCase
{
    /**
     * @param array<string, mixed> $query
     */
    private function render(Pagination $pagination, array $query = [], string $fragment = ''): string
    {
        $view = new View(dirname(__DIR__, 2) . '/templates');

        return $view->render(
            'partials/pagination',
            ['pagination' => $pagination, 'path' => '/orders', 'query' => $query, 'fragment' => $fragment],
            null
        );
    }

    public function testASinglePageShowsOnlyTheTotalAndNoLinks(): void
    {
        $html = $this->render(new Pagination(7, 25, 1));

        self::assertStringContainsString('7 risultati', $html);
        self::assertStringNotContainsString('<a ', $html);
        self::assertStringNotContainsString('pagina 1 di', $html);
    }

    public function testOneResultIsSingular(): void
    {
        self::assertStringContainsString('1 risultato', $this->render(new Pagination(1, 25, 1)));
        self::assertStringNotContainsString('1 risultati', $this->render(new Pagination(1, 25, 1)));
    }

    public function testMiddlePageHasBothLinksAndKeepsTheFilters(): void
    {
        $html = $this->render(new Pagination(110, 25, 3), ['q' => 'olio', 'status' => 'open']);

        self::assertStringContainsString('pagina 3 di 5', $html);
        self::assertStringContainsString('href="/orders?q=olio&amp;status=open&amp;pagina=2"', $html);
        self::assertStringContainsString('href="/orders?q=olio&amp;status=open&amp;pagina=4"', $html);
        self::assertStringContainsString('aria-label="Paginazione"', $html);
    }

    public function testFirstPageDisablesPreviousAndTheLinkToPageOneIsClean(): void
    {
        $first = $this->render(new Pagination(110, 25, 1), ['q' => 'olio']);

        self::assertStringContainsString('aria-disabled="true">&larr; Precedente', $first);
        self::assertStringContainsString('href="/orders?q=olio&amp;pagina=2"', $first);

        $second = $this->render(new Pagination(110, 25, 2), ['q' => 'olio']);
        self::assertStringContainsString('href="/orders?q=olio"', $second, 'la pagina 1 non porta il parametro');
    }

    public function testLastPageDisablesNext(): void
    {
        $html = $this->render(new Pagination(110, 25, 5));

        self::assertStringContainsString('aria-disabled="true">Successivo &rarr;', $html);
        self::assertStringContainsString('href="/orders?pagina=4"', $html);
    }

    public function testTheFragmentIsAppendedToTheLinks(): void
    {
        $html = $this->render(new Pagination(110, 25, 1), ['aging' => '1'], 'bancali');

        self::assertStringContainsString('href="/orders?aging=1&amp;pagina=2#bancali"', $html);
    }

    public function testFilterValuesAreEscaped(): void
    {
        $html = $this->render(new Pagination(110, 25, 1), ['q' => '"><script>']);

        self::assertStringNotContainsString('<script>', $html);
    }
}
