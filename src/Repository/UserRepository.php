<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;
use LogiDock\Enum\UserRole;
use LogiDock\Model\User;
use PDOException;

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

    /**
     * @param string $passwordHash hash già calcolato con password_hash(): qui non arriva mai la password
     * @throws DuplicateEntryException se esiste già un utente con questa email
     */
    public function insert(string $email, string $name, string $passwordHash, UserRole $role): int
    {
        try {
            $this->database->pdo()
                ->prepare('INSERT INTO users (email, name, password_hash, role) VALUES (?, ?, ?, ?)')
                ->execute([$email, $name, $passwordHash, $role->value]);
        } catch (PDOException $e) {
            // 1062 = "Duplicate entry" di MySQL.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new DuplicateEntryException('Email già registrata', 0, $e);
            }

            throw $e;
        }

        return (int) $this->database->pdo()->lastInsertId();
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
