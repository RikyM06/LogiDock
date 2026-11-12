<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;
use LogiDock\Core\Thousandths;

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

    /**
     * Registra l'uscita di un bancale su un viaggio. La quantità è quella uscita, positiva: nello
     * storico si scrive con il segno meno (quantity_delta negativo = uscita).
     *
     * @param int|null $fromLocationId la posizione da cui è uscito (null se non ne aveva una)
     * @param string   $quantity       quantità uscita, positiva
     * @param string   $occurredAt     "AAAA-MM-GG HH:MM:SS" (UTC)
     */
    public function insertOutbound(
        int $palletId,
        ?int $fromLocationId,
        string $quantity,
        int $tripId,
        int $userId,
        string $occurredAt,
        ?string $notes
    ): void {
        $delta = Thousandths::toDecimal(-Thousandths::fromDecimal($quantity));

        $this->database->pdo()
            ->prepare(
                "INSERT INTO pallet_movements (pallet_id, type, quantity_delta, from_location_id, trip_id, user_id, notes, occurred_at)
                 VALUES (?, 'outbound', ?, ?, ?, ?, ?, ?)"
            )
            ->execute([$palletId, $delta, $fromLocationId, $tripId, $userId, $notes, $occurredAt]);
    }
}
