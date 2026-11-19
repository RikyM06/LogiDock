<?php

declare(strict_types=1);

namespace LogiDock\Service;

use DateTimeImmutable;
use DateTimeZone;
use LogiDock\Core\Logger;
use LogiDock\Repository\LoginAttemptRepository;

/**
 * Il blocco temporaneo del login. Si contano i tentativi FALLITI negli ultimi `login_lockout_minutes` minuti:
 * - per la stessa e-mail (in minuscolo, anche se non esiste: non si scopre chi è registrato);
 * - per lo stesso indirizzo IP, con un limite IP_FACTOR volte più alto (chi prova molte e-mail dallo stesso PC).
 * Quando uno dei due limiti è raggiunto il login è rifiutato finché il tentativo più vecchio che conta non esce
 * dalla finestra: il blocco finisce da solo. Un accesso riuscito azzera gli errori di quell'e-mail.
 * Soglia e minuti sono impostazioni dell'admin (SettingService), non numeri scritti nel codice.
 */
final class LoginThrottle
{
    /** Il limite per indirizzo IP è questo multiplo della soglia per e-mail. */
    public const IP_FACTOR = 4;

    public function __construct(
        private readonly LoginAttemptRepository $attempts,
        private readonly SettingService $settings,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param DateTimeImmutable|null $now l'ora corrente; si passa solo nei test
     * @throws TooManyLoginAttemptsException se il login è bloccato
     */
    public function assertAllowed(string $email, string $ip, ?DateTimeImmutable $now = null): void
    {
        // Si lavora in UTC fin dall'inizio: "meno 15 minuti" sull'ora italiana sbaglierebbe nei giorni del cambio dell'ora.
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
        $key = self::normalize($email);
        $max = max(1, $this->settings->getInt(SettingService::LOGIN_MAX_ATTEMPTS));
        $minutes = max(1, $this->settings->getInt(SettingService::LOGIN_LOCKOUT_MINUTES));
        $since = self::utc($now->modify('-' . $minutes . ' minutes'));

        $retryAfter = max(
            $this->secondsLeft($this->attempts->failureTimesForEmail($key, $since), $max, $minutes, $now),
            $this->secondsLeft($this->attempts->failureTimesForIp($ip, $since), $max * self::IP_FACTOR, $minutes, $now)
        );

        if ($retryAfter > 0) {
            $this->logger->warning('Login bloccato', ['email' => $key, 'ip' => $ip, 'seconds_left' => $retryAfter]);

            throw new TooManyLoginAttemptsException($retryAfter);
        }
    }

    public function recordFailure(string $email, string $ip, ?DateTimeImmutable $now = null): void
    {
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
        $minutes = max(1, $this->settings->getInt(SettingService::LOGIN_LOCKOUT_MINUTES));

        // I tentativi più vecchi della finestra non servono più: la tabella resta piccola.
        $this->attempts->purgeUntil(self::utc($now->modify('-' . $minutes . ' minutes')));
        $this->attempts->record(self::normalize($email), $ip, self::utc($now));
    }

    public function recordSuccess(string $email): void
    {
        $this->attempts->clearEmail(self::normalize($email));
    }

    /**
     * Secondi che mancano alla fine del blocco: 0 se i fallimenti sono sotto il limite. Per tornare sotto il
     * limite deve uscire dalla finestra il fallimento in posizione (totale - limite): da lì in poi si può riprovare.
     *
     * @param list<string> $times orari UTC dei fallimenti nella finestra, dal più vecchio
     */
    private function secondsLeft(array $times, int $limit, int $windowMinutes, DateTimeImmutable $now): int
    {
        $count = count($times);
        if ($count < $limit) {
            return 0;
        }

        $expires = (new DateTimeImmutable($times[$count - $limit], new DateTimeZone('UTC')))
            ->modify('+' . $windowMinutes . ' minutes');

        return max(1, $expires->getTimestamp() - $now->getTimestamp());
    }

    private static function normalize(string $email): string
    {
        return mb_substr(mb_strtolower(trim($email)), 0, 255);
    }

    private static function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
