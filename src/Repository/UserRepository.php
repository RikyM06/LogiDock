<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;
use LogiDock\Model\User;

/**
 * Solo SQL: nessuna regola di business qui (quella sta in AuthService).
 */
final class UserRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function findById(int $id): ?User
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    public function findByEmail(string $email): ?User
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM users WHERE email = ?');
        $statement->execute([$email]);
        $row = $statement->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    public function touchLastLogin(int $id): void
    {
        $this->database->pdo()
            ->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$id]);
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->database->pdo()
            ->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$hash, $id]);
    }
}
