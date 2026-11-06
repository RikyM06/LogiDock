<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;

/**
 * Solo INSERT sullo storico dei movimenti. La tabella è append-only: niente UPDATE né DELETE,
 * né qui né in nessun altro punto (due trigger e i permessi dell'utente MySQL lo impediscono).
 */
final class PalletMovementRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Registra l'ingresso di un bancale in magazzino.
     *
     * @param string $quantity    quantità entrata (positiva)
     * @param string $occurredAt  quando è successo, formato "AAAA-MM-GG HH:MM:SS" (UTC)
     */
    public function insertInbound(
        int $palletId,
        int $locationId,
        string $quantity,
        int $userId,
        string $occurredAt,
        ?string $notes
    ): void {
        $this->database->pdo()
            ->prepare(
                "INSERT INTO pallet_movements (pallet_id, type, quantity_delta, to_location_id, user_id, notes, occurred_at)
                 VALUES (?, 'inbound', ?, ?, ?, ?, ?)"
            )
            ->execute([$palletId, $quantity, $locationId, $userId, $notes, $occurredAt]);
    }
}
