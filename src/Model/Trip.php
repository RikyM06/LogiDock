<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Un viaggio confermato: un giro, un mezzo, un giorno, con i totali del carico.
 * Il compenso del trasportatore è la fotografia di quanto valeva alla conferma (stringa decimale).
 */
final readonly class Trip
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PLANNED = 'planned';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /** Stato => nome da mostrare. */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Bozza',
        self::STATUS_PLANNED => 'Pianificato',
        self::STATUS_IN_TRANSIT => 'In viaggio',
        self::STATUS_COMPLETED => 'Concluso',
        self::STATUS_CANCELLED => 'Annullato',
    ];

    public function __construct(
        public int $id,
        public string $code,
        public string $roundName,
        public string $vehiclePlate,
        public string $vehicleDescription,
        public ?string $trailerTypeName,
        public ?string $carrierName,
        public ?string $carrierFee,
        public string $plannedDate,
        public string $status,
        public int $palletCount,
        public string $totalWeightKg,
        public int $roundId,
        public int $vehicleId,
        public int $lockedCount,
        public ?string $carrierTaxCode = null
    ) {
    }

    /** Può partire solo un viaggio pianificato (e con almeno un bancale). */
    public function canDepart(): bool
    {
        return $this->status === self::STATUS_PLANNED && $this->palletCount > 0;
    }

    /** Si può annullare solo finché non è partito. */
    public function isCancellable(): bool
    {
        return $this->status === self::STATUS_PLANNED;
    }

    /** Le scelte a mano (spostare i bancali, cambiare il mezzo) valgono solo finché non è partito. */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_PLANNED;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['round_name'],
            (string) $row['vehicle_plate'],
            (string) $row['vehicle_description'],
            $row['trailer_type_name'] === null ? null : (string) $row['trailer_type_name'],
            $row['carrier_name'] === null ? null : (string) $row['carrier_name'],
            $row['carrier_fee'] === null ? null : (string) $row['carrier_fee'],
            (string) $row['planned_date'],
            (string) $row['status'],
            (int) $row['pallet_count'],
            (string) $row['total_weight_kg'],
            (int) $row['round_id'],
            (int) $row['vehicle_id'],
            (int) $row['locked_count'],
            isset($row['carrier_tax_code']) ? (string) $row['carrier_tax_code'] : null
        );
    }
}
