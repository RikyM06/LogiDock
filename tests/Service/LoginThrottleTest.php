<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use DateInterval;
use DateTimeImmutable;
use LogiDock\Core\Logger;
use LogiDock\Enum\UserRole;
use LogiDock\Repository\LoginAttemptRepository;
use LogiDock\Repository\SettingRepository;
use LogiDock\Repository\UserRepository;
use LogiDock\Service\LoginThrottle;
use LogiDock\Service\SettingService;
use LogiDock\Service\TooManyLoginAttemptsException;
use LogiDock\Tests\Support\IntegrationTestCase;

/**
 * Il blocco temporaneo del login sul database di test, con l'ora iniettata. L'anno è lontano (2035) così gli
 * eventuali tentativi rimasti nel database di test da prove HTTP non entrano mai nella finestra.
 */
final class LoginThrottleTest extends IntegrationTestCase
{
    private LoginThrottle $throttle;
    private SettingService $settings;
    private LoginAttemptRepository $attempts;
    private DateTimeImmutable $t0;
    private string $logFile;
    private string $email;
    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = new SettingService(new SettingRepository($this->database), $this->database);
        $this->attempts = new LoginAttemptRepository($this->database);
        $this->logFile = sys_get_temp_dir() . '/logidock-throttle-' . uniqid('', true) . '.log';
        $this->throttle = new LoginThrottle($this->attempts, $this->settings, new Logger($this->logFile));

        $this->t0 = new DateTimeImmutable('2035-05-10 09:00:00');
        // Indirizzi e-mail e IP diversi a ogni test: nessuna interferenza tra una prova e l'altra.
        $this->email = 'zz-' . uniqid() . '@example.test';
        $this->ip = '10.' . random_int(1, 250) . '.' . random_int(1, 250) . '.' . random_int(1, 250);
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        parent::tearDown();
    }

    public function testFourFailuresDoNotBlockButTheFifthDoes(): void
    {
        $this->recordFailures($this->email, 4);
        $this->throttle->assertAllowed($this->email, $this->ip, $this->t0);
        $this->addToAssertionCount(1);

        $this->recordFailures($this->email, 1);

        $this->expectException(TooManyLoginAttemptsException::class);
        $this->throttle->assertAllowed($this->email, $this->ip, $this->t0);
    }

    public function testItWorksOnTheTypedEmailWhetherOrNotItExists(): void
    {
        // L'e-mail non è di nessun utente: il blocco scatta lo stesso, nello stesso modo (non si scopre chi esiste).
        $this->recordFailures($this->email, 5);

        $this->expectException(TooManyLoginAttemptsException::class);
        $this->throttle->assertAllowed($this->email, '10.9.9.9', $this->t0);
    }

    public function testCaseAndSurroundingSpacesCountTogether(): void
    {
        $this->throttle->recordFailure(strtoupper($this->email), $this->ip, $this->t0);
        $this->throttle->recordFailure('  ' . $this->email . '  ', $this->ip, $this->t0);
        $this->recordFailures($this->email, 3);

        $this->expectException(TooManyLoginAttemptsException::class);
        $this->throttle->assertAllowed($this->email, $this->ip, $this->t0);
    }

    public function testTheBlockEndsWhenTheOldestFailureLeavesTheWindow(): void
    {
        $this->recordFailures($this->email, 5);

        $almost = $this->t0->add(new DateInterval('PT14M59S'));
        try {
            $this->throttle->assertAllowed($this->email, $this->ip, $almost);
            self::fail('A 14 minuti e 59 secondi il blocco dura ancora.');
        } catch (TooManyLoginAttemptsException $e) {
            self::assertSame(1, $e->retryAfterSeconds);
        }

        $this->throttle->assertAllowed($this->email, $this->ip, $this->t0->add(new DateInterval('PT15M')));
        $this->addToAssertionCount(1);
    }

    public function testTheWindowIsCorrectAcrossTheDaylightSavingChange(): void
    {
        // Alle 03:05 ora italiana del cambio all'ora legale: se la finestra si calcolasse sull'ora "del muro" prima
        // di passare a UTC, il limite sarebbe 45 minuti nel futuro e i tentativi si cancellerebbero a vicenda.
        $rome = new \DateTimeZone('Europe/Rome');
        $now = new DateTimeImmutable('2026-03-29 03:05:00', $rome);

        for ($i = 0; $i < 5; $i++) {
            $this->throttle->recordFailure($this->email, $this->ip, $now);
        }

        try {
            $this->throttle->assertAllowed($this->email, $this->ip, $now->add(new DateInterval('PT1M')));
            self::fail('Dopo 5 errori il login doveva essere bloccato anche nel giorno del cambio dell\'ora.');
        } catch (TooManyLoginAttemptsException $e) {
            self::assertSame(14 * 60, $e->retryAfterSeconds);
        }
    }

    public function testTheMinutesLeftAreRoundedUpAndWrittenInTheMessage(): void
    {
        $this->recordFailures($this->email, 5);

        try {
            $this->throttle->assertAllowed($this->email, $this->ip, $this->t0->add(new DateInterval('PT1M30S')));
            self::fail('Doveva essere bloccato.');
        } catch (TooManyLoginAttemptsException $e) {
            self::assertSame(13 * 60 + 30, $e->retryAfterSeconds);
            self::assertSame(14, $e->minutes());
            self::assertStringContainsString('Troppi tentativi', $e->getMessage());
            self::assertStringContainsString('14 minuti', $e->getMessage());
        }
    }

    public function testASuccessfulLoginClearsTheFailuresOfThatEmail(): void
    {
        $this->recordFailures($this->email, 4);
        $this->throttle->recordSuccess($this->email);
        $this->recordFailures($this->email, 4);

        $this->throttle->assertAllowed($this->email, $this->ip, $this->t0);
        $this->addToAssertionCount(1);
    }

    public function testTheIpLimitBlocksOtherEmailsFromTheSameAddressButNotOtherAddresses(): void
    {
        // 20 e-mail diverse (un solo errore ciascuna) dallo stesso indirizzo: scatta il limite dell'indirizzo.
        for ($i = 0; $i < 20; $i++) {
            $this->throttle->recordFailure('zz-' . uniqid() . '@example.test', $this->ip, $this->t0);
        }

        try {
            $this->throttle->assertAllowed($this->email, $this->ip, $this->t0);
            self::fail('Un\'e-mail nuova dallo stesso indirizzo doveva essere bloccata.');
        } catch (TooManyLoginAttemptsException) {
            $this->addToAssertionCount(1);
        }

        $this->throttle->assertAllowed($this->email, '10.250.250.250', $this->t0);
        $this->addToAssertionCount(1);
    }

    public function testOldFailuresAreRemovedWhenANewOneIsRecorded(): void
    {
        $this->throttle->recordFailure($this->email, $this->ip, $this->t0);
        $this->throttle->recordFailure($this->email, $this->ip, $this->t0->add(new DateInterval('P1D')));

        $count = $this->database->pdo()->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ?');
        $count->execute([$this->email]);

        self::assertSame(1, (int) $count->fetchColumn(), 'Il tentativo di un giorno prima non serve più e si cancella.');
    }

    public function testTheThresholdComesFromTheSettings(): void
    {
        $userId = (new UserRepository($this->database))->insert(
            'zz-throttle-' . uniqid() . '@example.test',
            'Utente di prova',
            password_hash('non-importa', PASSWORD_DEFAULT),
            UserRole::Admin
        );
        $this->settings->update(
            array_merge($this->settings->values(), [
                SettingService::LOGIN_MAX_ATTEMPTS => '3',
                SettingService::LOGIN_LOCKOUT_MINUTES => '30',
            ]),
            $userId
        );

        $this->recordFailures($this->email, 3);

        try {
            $this->throttle->assertAllowed($this->email, $this->ip, $this->t0->add(new DateInterval('PT10M')));
            self::fail('Con soglia 3 il terzo errore blocca.');
        } catch (TooManyLoginAttemptsException $e) {
            self::assertSame(20, $e->minutes(), 'Il blocco dura 30 minuti dal primo errore: ne restano 20.');
        }
    }

    public function testABlockWritesALogLine(): void
    {
        $this->recordFailures($this->email, 5);

        try {
            $this->throttle->assertAllowed($this->email, $this->ip, $this->t0);
        } catch (TooManyLoginAttemptsException) {
            // atteso
        }

        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('Login bloccato', $log);
        self::assertStringContainsString($this->email, $log);
        self::assertStringNotContainsString('password', strtolower($log));
    }

    private function recordFailures(string $email, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->throttle->recordFailure($email, $this->ip, $this->t0);
        }
    }
}
