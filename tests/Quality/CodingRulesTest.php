<?php

declare(strict_types=1);

namespace LogiDock\Tests\Quality;

use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Le regole di progetto che una macchina può controllare da sola (vedi "Conventions" in CLAUDE.md).
 * Ogni regola elenca i file colpevoli, così si sa subito cosa sistemare.
 */
final class CodingRulesTest extends TestCase
{
    /** Cartelle che non fanno parte del codice nostro. */
    private const SKIPPED_DIRECTORIES = ['vendor', '.git', '.phpunit.cache', 'node_modules', 'storage', 'LinkedIn', 'images', '.superpowers'];

    /** Estensioni dei file di testo del progetto. */
    private const TEXT_EXTENSIONS = ['php', 'sql', 'md', 'css', 'js', 'html', 'ps1', 'sh', 'yml', 'yaml', 'neon', 'json'];

    public function testEveryPhpFileDeclaresStrictTypes(): void
    {
        $missing = [];
        foreach ($this->files(['php']) as $relative => $path) {
            // I template sono pagine HTML con un po' di PHP: lì la dichiarazione non ha senso.
            if (str_starts_with($relative, 'templates/')) {
                continue;
            }
            if (!str_contains((string) file_get_contents($path), 'declare(strict_types=1);')) {
                $missing[] = $relative;
            }
        }

        self::assertSame([], $missing, "Manca declare(strict_types=1); in:\n" . implode("\n", $missing));
    }

    public function testEveryTextFileEndsWithASingleNewline(): void
    {
        $wrong = [];
        foreach ($this->files(self::TEXT_EXTENSIONS) as $relative => $path) {
            $content = (string) file_get_contents($path);
            if ($content === '') {
                continue;
            }
            $endsWithNewline = str_ends_with($content, "\n");
            $endsWithBlankLine = str_ends_with($content, "\n\n") || str_ends_with($content, "\r\n\r\n");
            if (!$endsWithNewline || $endsWithBlankLine) {
                $wrong[] = $relative . ($endsWithNewline ? ' (righe vuote in fondo)' : ' (manca la riga finale)');
            }
        }

        self::assertSame([], $wrong, "Ogni file deve finire con una sola riga a capo. Da sistemare:\n" . implode("\n", $wrong));
    }

    public function testNoDebugCallsAreLeftBehind(): void
    {
        $found = [];
        foreach ($this->files(['php']) as $relative => $path) {
            if (!str_starts_with($relative, 'src/') && !str_starts_with($relative, 'config/') && !str_starts_with($relative, 'templates/')) {
                continue;
            }
            if (preg_match('/\b(var_dump|print_r|dd)\s*\(/', (string) file_get_contents($path), $match) === 1) {
                $found[] = $relative . ' (' . $match[1] . ')';
            }
        }

        self::assertSame([], $found, "Chiamate di debug rimaste nel codice:\n" . implode("\n", $found));
    }

    public function testMigrationsAreNumberedWithoutGaps(): void
    {
        $files = glob(self::root() . '/database/migrations/*.sql');
        self::assertIsArray($files);
        $numbers = [];
        $badNames = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (preg_match('/^(\d{4})_[a-z0-9_]+\.sql$/', $name, $match) !== 1) {
                $badNames[] = $name;
                continue;
            }
            $numbers[] = (int) $match[1];
        }
        sort($numbers);

        self::assertSame([], $badNames, "Nomi di migrazione non validi (serve NNNN_nome_in_minuscolo.sql):\n" . implode("\n", $badNames));
        self::assertNotSame([], $numbers, 'Non ho trovato nessuna migrazione.');
        self::assertSame(
            range(1, count($numbers)),
            $numbers,
            'Le migrazioni devono essere numerate da 0001 senza buchi né numeri doppi. Numeri trovati: ' . implode(', ', $numbers)
        );
    }

    public function testEveryEnvVariableOfTheConfigIsInEnvExample(): void
    {
        $config = (string) file_get_contents(self::root() . '/config/app.php');
        preg_match_all("/env\('([A-Z0-9_]+)'/", $config, $matches);
        $variables = array_values(array_unique($matches[1]));
        self::assertNotSame([], $variables, 'Non ho trovato nessuna env() in config/app.php.');

        $example = (string) file_get_contents(self::root() . '/.env.example');
        $missing = [];
        foreach ($variables as $variable) {
            if (preg_match('/^' . preg_quote($variable, '/') . '=/m', $example) !== 1) {
                $missing[] = $variable;
            }
        }

        self::assertSame(
            [],
            $missing,
            "Queste variabili sono lette da config/app.php ma mancano in .env.example:\n" . implode("\n", $missing)
        );
    }

    public function testTheScanSeesTheRealFiles(): void
    {
        // Se la ricerca dei file si rompe, le altre regole passerebbero a vuoto: qui lo scopriamo.
        $all = $this->files(self::TEXT_EXTENSIONS);

        foreach (['src/Core/Router.php', 'config/app.php', 'database/migrations/0001_create_users.sql', 'templates/layout.php', 'README.md'] as $known) {
            self::assertArrayHasKey($known, $all, 'La scansione non vede ' . $known);
        }
        self::assertGreaterThan(100, count($all));
        self::assertArrayNotHasKey('vendor/autoload.php', $all);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * I file del progetto con una di queste estensioni, in ordine alfabetico.
     *
     * @param list<string> $extensions
     * @return array<string, string> percorso relativo (con "/") => percorso completo
     */
    private function files(array $extensions): array
    {
        $root = realpath(self::root());
        self::assertIsString($root);

        $directory = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            static fn (SplFileInfo $file): bool => !($file->isDir() && in_array($file->getFilename(), self::SKIPPED_DIRECTORIES, true))
        );

        $found = [];
        foreach (new RecursiveIteratorIterator($filter) as $file) {
            if (!$file instanceof SplFileInfo || !in_array($file->getExtension(), $extensions, true)) {
                continue;
            }
            $found[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = $file->getPathname();
        }
        ksort($found);

        return $found;
    }
}
