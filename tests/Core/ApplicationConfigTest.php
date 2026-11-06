<?php

declare(strict_types=1);

namespace LogiDock\Tests\Core;

use LogiDock\Core\Application;
use PHPUnit\Framework\TestCase;

/**
 * Chi vince tra una variabile d'ambiente vera e lo stesso nome in .env.
 * Regola: vince quella vera. (Un bug reale: con php -S e con variables_order=GPCS le variabili
 * vere sono visibili solo da getenv(), e prima di questa correzione .env le copriva.)
 */
final class ApplicationConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/logidock-config-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);

        file_put_contents($this->dir . '/.env', "ZZ_FROM_BOTH=dal-file\nZZ_ONLY_FILE=solo-file\n");
        file_put_contents(
            $this->dir . '/config/app.php',
            "<?php return ['both' => env('ZZ_FROM_BOTH'), 'file' => env('ZZ_ONLY_FILE'), 'none' => env('ZZ_NOWHERE', 'predefinito')];"
        );
    }

    protected function tearDown(): void
    {
        foreach (['ZZ_FROM_BOTH', 'ZZ_ONLY_FILE'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        unlink($this->dir . '/.env');
        unlink($this->dir . '/config/app.php');
        rmdir($this->dir . '/config');
        rmdir($this->dir);
    }

    public function testRealEnvironmentVariableWinsOverTheEnvFile(): void
    {
        // Come l'ambiente di un servizio: visibile solo da getenv(), non da $_ENV né da $_SERVER.
        putenv('ZZ_FROM_BOTH=dall-ambiente');

        $config = Application::loadConfig($this->dir);

        self::assertSame('dall-ambiente', $config['both']);
    }

    public function testEnvFileFillsWhatTheEnvironmentDoesNotHave(): void
    {
        $config = Application::loadConfig($this->dir);

        self::assertSame('solo-file', $config['file']);
        self::assertSame('dal-file', $config['both']);
    }

    public function testMissingEverywhereGivesTheDefault(): void
    {
        $config = Application::loadConfig($this->dir);

        self::assertSame('predefinito', $config['none']);
    }
}
