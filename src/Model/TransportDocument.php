<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Un documento emesso alla partenza di un viaggio (senza il PDF, che si legge a parte: è pesante).
 * Il numero è progressivo per tipo e anno e non si riusa mai.
 */
final readonly class TransportDocument
{
    public const TYPE_LOAD_LIST = 'load_list';
    public const TYPE_DDT = 'ddt';
    public const TYPE_COMPENSATION = 'compensation';

    /** Tipo => nome da mostrare. */
    public const TYPE_LABELS = [
        self::TYPE_LOAD_LIST => 'Documento di carico',
        self::TYPE_DDT => 'DDT',
        self::TYPE_COMPENSATION => 'Compenso del trasportatore',
    ];

    /** Tipo => prefisso del numero, come PAL- e VIA- per bancali e viaggi. */
    private const PREFIXES = [
        self::TYPE_LOAD_LIST => 'CAR',
        self::TYPE_DDT => 'DDT',
        self::TYPE_COMPENSATION => 'COM',
    ];

    public function __construct(
        public int $id,
        public int $tripId,
        public string $tripCode,
        public string $type,
        public int $year,
        public int $number,
        public string $issuedAt,
        public ?int $customerId,
        public ?string $customerName,
        public string $issuedByName
    ) {
    }

    /** Il numero come si stampa: DDT-2026-000001. */
    public static function formatCode(string $type, int $year, int $number): string
    {
        return sprintf('%s-%d-%06d', self::PREFIXES[$type], $year, $number);
    }

    public function code(): string
    {
        return self::formatCode($this->type, $this->year, $this->number);
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    /** Il prospetto del compenso contiene l'importo pagato al trasportatore: lo vede solo l'admin. */
    public function isAdminOnly(): bool
    {
        return $this->type === self::TYPE_COMPENSATION;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['trip_id'],
            (string) $row['trip_code'],
            (string) $row['type'],
            (int) $row['year'],
            (int) $row['number'],
            (string) $row['issued_at'],
            $row['customer_id'] === null ? null : (int) $row['customer_id'],
            $row['customer_name'] === null ? null : (string) $row['customer_name'],
            (string) $row['issued_by_name']
        );
    }
}
