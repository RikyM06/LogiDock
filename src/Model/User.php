<?php

declare(strict_types=1);

namespace LogiDock\Model;

use LogiDock\Enum\UserRole;

final readonly class User
{
    public function __construct(
        public int $id,
        public string $email,
        public string $name,
        public string $passwordHash,
        public UserRole $role,
        public bool $isActive,
        public ?string $lastLoginAt = null
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * @param array<string, mixed> $row riga della tabella users
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['name'],
            (string) $row['password_hash'],
            UserRole::from((string) $row['role']),
            (bool) $row['is_active'],
            isset($row['last_login_at']) ? (string) $row['last_login_at'] : null
        );
    }
}
