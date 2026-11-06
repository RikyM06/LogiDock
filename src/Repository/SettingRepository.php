<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;

/**
 * Solo SQL sulla tabella settings (nome => valore). Quali impostazioni esistono e cosa valgono
 * di default lo decide SettingService.
 */
final class SettingRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @return array<string, string> nome => valore salvato
     */
    public function all(): array
    {
        $rows = $this->database->pdo()->query('SELECT name, value FROM settings');
        $settings = [];

        foreach ($rows ?: [] as $row) {
            $settings[(string) $row['name']] = (string) $row['value'];
        }

        return $settings;
    }

    /**
     * Inserisce l'impostazione o, se c'è già, ne aggiorna il valore.
     */
    public function save(string $name, string $value, int $userId): void
    {
        $this->database->pdo()
            ->prepare(
                'INSERT INTO settings (name, value, updated_by) VALUES (?, ?, ?) AS new_row '
                . 'ON DUPLICATE KEY UPDATE value = new_row.value, updated_by = new_row.updated_by'
            )
            ->execute([$name, $value, $userId]);
    }
}
