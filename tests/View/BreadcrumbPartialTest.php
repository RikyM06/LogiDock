<?php

declare(strict_types=1);

namespace LogiDock\Tests\View;

use LogiDock\Core\View;
use PHPUnit\Framework\TestCase;

/**
 * Il partial delle briciole di pane, renderizzato da solo (senza layout) con il template vero.
 */
final class BreadcrumbPartialTest extends TestCase
{
    /**
     * @param list<array{0: string, 1?: string}> $crumbs
     */
    private function render(array $crumbs): string
    {
        $view = new View(dirname(__DIR__, 2) . '/templates');

        return $view->render('partials/breadcrumb', ['crumbs' => $crumbs], null);
    }

    public function testHomeIsAlwaysTheFirstItemAndLinksToTheRoot(): void
    {
        $html = $this->render([['Richieste']]);

        self::assertStringContainsString('<a href="/">Home</a>', $html);
        self::assertLessThan(strpos($html, 'Richieste'), strpos($html, 'Home'), 'Home viene prima delle altre voci');
        self::assertStringContainsString('aria-label="Briciole di pane"', $html);
    }

    public function testIntermediateItemsWithAnUrlAreLinks(): void
    {
        $html = $this->render([['Richieste', '/orders'], ['ORD-2026-000004']]);

        self::assertStringContainsString('<a href="/orders">Richieste</a>', $html);
    }

    public function testTheLastItemIsNotALinkAndIsTheCurrentPage(): void
    {
        $html = $this->render([['Richieste', '/orders'], ['ORD-2026-000004', '/orders/4']]);

        self::assertStringContainsString('<span aria-current="page">ORD-2026-000004</span>', $html);
        self::assertStringNotContainsString('href="/orders/4"', $html, 'anche con un URL, la pagina corrente non è un link');
        self::assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    public function testAnItemWithoutAnUrlIsPlainText(): void
    {
        $html = $this->render([['Anagrafiche'], ['Clienti', '/customers'], ['Nuovo cliente']]);

        self::assertStringContainsString('<li>Anagrafiche</li>', $html);
        self::assertStringNotContainsString('<a href="">', $html);
    }

    public function testLabelsAndUrlsAreEscaped(): void
    {
        $html = $this->render([['<script>alert(1)</script>', '/x"onmouseover="alert(2)'], ['Ultima <b>voce</b>']]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('"onmouseover="', $html, 'le virgolette dell\'URL non chiudono l\'attributo');
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAnEmptyListPrintsNothing(): void
    {
        self::assertSame('', trim($this->render([])));
    }
}
