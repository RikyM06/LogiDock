<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;
use LogiDock\Enum\UserRole;
use LogiDock\Model\User;
use PDOException;

/**
 * Solo SQL: nessuna regola di business qui (quella sta in AuthService e UserService).
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

    /**
     * Gli utenti attivi per primi, poi per nome. Con $search filtra per nome o e-mail.
     *
     * @return list<User>
     */
    public function search(string $search = ''): array
    {
        $sql = 'SELECT * FROM users';
        $params = [];

        if ($search !== '') {
            // % e _ scritti dall'utente valgono come caratteri normali, non come jolly del LIKE.
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $sql .= ' WHERE name LIKE ? OR email LIKE ?';
            $params = [$like, $like];
        }

        $statement = $this->database->pdo()->prepare($sql . ' ORDER BY is_active DESC, name ASC');
        $statement->execute($params);

        return array_map(User::fromRow(...), $statement->fetchAll());
    }

    public function updateProfile(int $id, string $name, UserRole $role): void
    {
        $this->database->pdo()
            ->prepare('UPDATE users SET name = ?, role = ? WHERE id = ?')
            ->execute([$name, $role->value, $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->database->pdo()
            ->prepare('UPDATE users SET is_active = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);
    }

    /**
     * Gli id degli admin attivi, BLOCCANDO quelle righe fino alla fine della transazione (FOR UPDATE).
     * Va chiamata dentro una transazione: così due admin che si tolgono il ruolo a vicenda nello stesso
     * istante non possono lasciare l'applicazione senza nessun admin (il secondo aspetta il primo).
     *
     * @return list<int>
     */
    public function lockActiveAdminIds(): array
    {
        $statement = $this->database->pdo()->prepare(
            "SELECT id FROM users WHERE role = 'admin' AND is_active = 1 FOR UPDATE"
        );
        $statement->execute();

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
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
