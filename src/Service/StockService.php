<?php

declare(strict_types=1);

namespace LogiDock\Service;

use DateTimeImmutable;
use LogiDock\Model\StockPallet;
use LogiDock\Model\StockProductSummary;
use LogiDock\Repository\StockRepository;

/**
 * Giacenze di magazzino e avviso per i bancali fermi da troppo.
 *
 * Ogni metodo riceve la stessa AgingRule (soglia + data di oggi), creata una volta per richiesta
 * con agingRule(): così la lista, i totali e l'avviso usano esattamente la stessa data.
 */
final class StockService
{
    /** Bancali mostrati al massimo nella lista, come le altre liste (200 righe). */
    public const LIST_LIMIT = 200;

    public function __construct(
        private readonly StockRepository $stock,
        private readonly SettingService $settings
    ) {
    }

    /**
     * La regola dell'avviso con la soglia delle impostazioni.
     *
     * @param DateTimeImmutable|null $today la data di oggi; si passa solo nei test
     */
    public function agingRule(?DateTimeImmutable $today = null): AgingRule
    {
        return new AgingRule(
            $this->settings->getInt(SettingService::AGING_THRESHOLD_DAYS),
            $today ?? new DateTimeImmutable('today')
        );
    }

    /**
     * @return list<StockProductSummary>
     */
    public function summaryByProduct(AgingRule $rule): array
    {
        return $this->stock->summaryByProduct($rule->receivedBefore());
    }

    /**
     * @param bool $onlyAging true = solo i bancali fermi oltre la soglia
     * @return list<StockPallet>
     */
    public function pallets(AgingRule $rule, ?int $productId, ?int $locationId, bool $onlyAging): array
    {
        return $this->stock->pallets(
            $productId,
            $locationId,
            $onlyAging ? $rule->receivedBefore() : null,
            self::LIST_LIMIT
        );
    }

    public function agingCount(AgingRule $rule): int
    {
        return $this->stock->countReceivedBefore($rule->receivedBefore());
    }

    /**
     * I bancali fermi da più tempo, per l'avviso nella home.
     *
     * @return list<StockPallet>
     */
    public function oldestAging(AgingRule $rule, int $limit): array
    {
        return $this->stock->pallets(null, null, $rule->receivedBefore(), $limit);
    }
}
