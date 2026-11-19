<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use DateInterval;
use DateTimeImmutable;
use LogiDock\Core\Csrf;
use LogiDock\Core\Logger;
use LogiDock\Core\Session;
use LogiDock\Enum\UserRole;
use LogiDock\Repository\LoginAttemptRepository;
use LogiDock\Repository\SettingRepository;
use LogiDock\Repository\UserRepository;
use LogiDock\Service\AuthService;
use LogiDock\Service\LoginThrottle;
use LogiDock\Service\SettingService;
use LogiDock\Service\TooManyLoginAttemptsException;
use LogiDock\Tests\Support\IntegrationTestCase;

/**
 * Il login con il blocco dei tentativi (solo i percorsi che non aprono la sessione: l'accesso riuscito, che
 * cambia l'ID di sessione, si prova con la prova HTTP). L'ora è iniettata, l'anno lontano (2035).
 */
final class AuthServiceTest extends IntegrationTestCase
{
    private const PASSWORD = 'una-password-lunga-e-giusta';

    private AuthService $auth;
    private UserRepository $users;
    private DateTimeImmutable $t0;
    private string $logFile;
    private string $email;
    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->database);
        $this->logFile = sys_get_temp_dir() . '/logidock-auth-' . uniqid('', true) . '.log';
        $logger = new Logger($this->logFile);
        $settings = new SettingService(new SettingRepository($this->database), $this->database);
        $session = new Session('logidock_test_session', 3600, false);

        $this->auth = new AuthService(
            $this->users,
            $session,
            new Csrf($session),
            $logger,
            new LoginThrottle(new LoginAttemptRepository($this->database), $settings, $logger)
        );

        $this->t0 = new DateTimeImmutable('2035-05-10 09:00:00');
        $this->email = 'zz-auth-' . uniqid() . '@example.test';
        $this->ip = '10.' . random_int(1, 250) . '.' . random_int(1, 250) . '.' . random_int(1, 250);
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        parent::tearDown();
    }

    public function testTheRightPasswordIsRefusedWhileTheLoginIsBlocked(): void
    {
        $userId = $this->createUser(true);
        for ($i = 0; $i < 5; $i++) {
            self::assertNull($this->auth->attempt($this->email, 'password-sbagliata-1', $this->ip, $this->t0));
        }

        try {
            $this->auth->attempt($this->email, self::PASSWORD, $this->ip, $this->t0->add(new DateInterval('PT1M')));
            self::fail('Durante il blocco anche la password giusta doveva essere rifiutata.');
        } catch (TooManyLoginAttemptsException $e) {
            self::assertSame(14 * 60, $e->retryAfterSeconds);
        }

        self::assertNull($this->users->findById($userId)?->lastLoginAt, 'Nessun accesso è stato registrato.');
    }

    public function testEveryKindOfFailureCountsOnce(): void
    {
        $activeId = $this->createUser(true);
        $inactiveEmail = 'zz-auth-off-' . uniqid() . '@example.test';
        $inactiveId = $this->users->insert($inactiveEmail, 'Disattivato', password_hash(self::PASSWORD, PASSWORD_DEFAULT), UserRole::Operatore);
        $this->users->setActive($inactiveId, false);
        $unknown = 'zz-auth-ghost-' . uniqid() . '@example.test';

        self::assertNull($this->auth->attempt($this->email, 'password-sbagliata-1', $this->ip, $this->t0), 'password errata');
        self::assertNull($this->auth->attempt($unknown, self::PASSWORD, $this->ip, $this->t0), 'e-mail sconosciuta');
        self::assertNull($this->auth->attempt($inactiveEmail, self::PASSWORD, $this->ip, $this->t0), 'utente disattivato');

        foreach ([$this->email, $unknown, $inactiveEmail] as $email) {
            self::assertSame(1, $this->failuresOf($email), 'Un solo errore registrato per ' . $email);
        }
        self::assertGreaterThan(0, $activeId);
    }

    private function createUser(bool $active): int
    {
        $id = $this->users->insert($this->email, 'Utente di prova', password_hash(self::PASSWORD, PASSWORD_DEFAULT), UserRole::Operatore);
        if (!$active) {
            $this->users->setActive($id, false);
        }

        return $id;
    }

    private function failuresOf(string $email): int
    {
        $statement = $this->database->pdo()->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ?');
        $statement->execute([strtolower($email)]);

        return (int) $statement->fetchColumn();
    }
}
