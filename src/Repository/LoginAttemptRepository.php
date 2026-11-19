<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;

/**
 * Solo SQL sui tentativi di accesso falliti (tabella login_attempts). Gli orari sono stringhe
 * "AAAA-MM-GG HH:MM:SS" in UTC; le regole del blocco stanno in LoginThrottle.
 */
final class LoginAttemptRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function record(string $email, string $ip, string $attemptedAtUtc): void
    {
        $this->database->pdo()
            ->prepare('INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, ?)')
            ->execute([$email, $ip, $attemptedAtUtc]);
    }

    /**
     * Gli orari dei fallimenti di quell'e-mail dopo $sinceUtc, dal più vecchio.
     *
     * @return list<string>
     */
    public function failureTimesForEmail(string $email, string $sinceUtc): array
    {
        return $this->times('email', $email, $sinceUtc);
    }

    /**
     * Gli orari dei fallimenti da quell'indirizzo IP dopo $sinceUtc, dal più vecchio.
     *
     * @return list<string>
     */
    public function failureTimesForIp(string $ip, string $sinceUtc): array
    {
        return $this->times('ip', $ip, $sinceUtc);
    }

    /** Dopo un accesso riuscito i vecchi errori di quell'e-mail non contano più. */
    public function clearEmail(string $email): void
    {
        $this->database->pdo()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
    }

    /** Cancella i tentativi fino a quell'orario compreso (non servono più al blocco). */
    public function purgeUntil(string $untilUtc): void
    {
        $this->database->pdo()->prepare('DELETE FROM login_attempts WHERE attempted_at <= ?')->execute([$untilUtc]);
    }

    /**
     * @param 'email'|'ip' $column nome di colonna scritto nel codice, mai input dell'utente
     * @return list<string>
     */
    private function times(string $column, string $value, string $sinceUtc): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT attempted_at FROM login_attempts WHERE ' . $column . ' = ? AND attempted_at > ? ORDER BY attempted_at'
        );
        $statement->execute([$value, $sinceUtc]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }
}
