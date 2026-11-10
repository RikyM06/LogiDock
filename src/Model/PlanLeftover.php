<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Un bancale che servirebbe a una richiesta ma che il piano lascia in magazzino, con il motivo.
 */
final readonly class PlanLeftover
{
    public const REASON_NO_VEHICLE = 'no_vehicle';
    public const REASON_DOES_NOT_FIT = 'does_not_fit';

    /** Motivo => frase da mostrare. */
    public const REASON_LABELS = [
        self::REASON_NO_VEHICLE => 'Non ci sono altri mezzi liberi in questo giorno',
        self::REASON_DOES_NOT_FIT => 'Non entra in nessuno dei mezzi liberi',
    ];

    public function __construct(
        public PlanAssignment $assignment,
        public string $reason
    ) {
    }

    public function reasonLabel(): string
    {
        return self::REASON_LABELS[$this->reason] ?? $this->reason;
    }
}
