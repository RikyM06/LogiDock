<?php

declare(strict_types=1);

namespace LogiDock\Model\History;

use LogiDock\Model\Trip;

/**
 * Una riga della scheda Viaggi dello storico. `billedTotal` c'è solo per i viaggi partiti (somma delle
 * righe di DDT). `fee` e `compensationPaidAt` sono null per chi non è admin: il service li svuota.
 */
final readonly class TripHistoryRow
{
    public function __construct(
        public int $id,
        public string $code,
        public string $plannedDate,
        public string $roundName,
        public string $vehiclePlate,
        public ?string $carrierName,
        public string $status,
        public int $palletCount,
        public int $customerCount,
        public ?string $billedTotal,
        public ?string $fee,
        public ?string $compensationPaidAt
    ) {
    }

    public function statusLabel(): string
    {
        return Trip::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Stessa riga senza i dati del compenso (per l'operatore). */
    public function withoutCompensation(): self
    {
        return new self(
            $this->id,
            $this->code,
            $this->plannedDate,
            $this->roundName,
            $this->vehiclePlate,
            $this->carrierName,
            $this->status,
            $this->palletCount,
            $this->customerCount,
            $this->billedTotal,
            null,
            null
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['planned_date'],
            (string) $row['round_name'],
            (string) $row['vehicle_plate'],
            $row['carrier_name'] === null ? null : (string) $row['carrier_name'],
            (string) $row['status'],
            (int) $row['pallet_count'],
            (int) $row['customer_count'],
            $row['billed_total'] === null ? null : (string) $row['billed_total'],
            $row['carrier_fee'] === null ? null : (string) $row['carrier_fee'],
            $row['compensation_paid_at'] === null ? null : (string) $row['compensation_paid_at']
        );
    }
}
