<?php

declare(strict_types=1);

namespace LogiDock\Tests\View;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Ogni pagina deve avere le sue briciole di pane: se un template di pagina nuovo se ne dimentica, questo test
 * fallisce e dice quale.
 */
final class BreadcrumbCoverageTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../templates';

    public function testEveryPageTemplateShowsTheBreadcrumb(): void
    {
        $missing = [];

        foreach ($this->pageTemplates() as $relative => $file) {
            $source = (string) file_get_contents($file);

            // Le liste passano da list_header, che stampa le briciole (vedi il test qui sotto).
            if (!str_contains($source, "partial('breadcrumb'") && !str_contains($source, "partial('list_header'")) {
                $missing[] = $relative;
            }
        }

        self::assertSame([], $missing, 'Template di pagina senza briciole di pane: ' . implode(', ', $missing));
    }

    public function testTheListHeaderPrintsTheBreadcrumbForTheListsThatUseIt(): void
    {
        $source = (string) file_get_contents(self::TEMPLATES . '/partials/list_header.php');

        self::assertStringContainsString("partial('breadcrumb'", $source);
    }

    public function testTheCoverageTestSeesTheRealPages(): void
    {
        $pages = array_keys($this->pageTemplates());

        // Se la ricerca dei template si rompe, il test di copertura passerebbe a vuoto: lo evitiamo.
        self::assertContains('orders/show.php', $pages);
        self::assertContains('customers/form.php', $pages);
        self::assertContains('history/index.php', $pages);
        self::assertNotContains('home.php', $pages);
        self::assertNotContains('layout.php', $pages);
        self::assertNotContains('auth/login.php', $pages);
        self::assertGreaterThan(20, count($pages));
    }

    /**
     * I template che sono pagine intere: tutti tranne layout, home, login, errori, partial e i pezzi delle schede.
     *
     * @return array<string, string> percorso relativo => percorso del file
     */
    private function pageTemplates(): array
    {
        $root = realpath(self::TEMPLATES);
        self::assertIsString($root);

        $pages = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $excluded = in_array($relative, ['layout.php', 'home.php'], true)
                || str_starts_with($relative, 'auth/')
                || str_starts_with($relative, 'errors/')
                || str_starts_with($relative, 'partials/')
                // I pezzi delle schede dello storico (history/_viaggi.php ...): la pagina è history/index.php.
                || (str_starts_with($relative, 'history/') && str_starts_with(basename($relative), '_'));

            if (!$excluded) {
                $pages[$relative] = $file->getPathname();
            }
        }
        ksort($pages);

        return $pages;
    }
}
