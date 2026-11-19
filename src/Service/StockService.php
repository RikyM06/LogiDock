<?php

declare(strict_types=1);

namespace LogiDock\Service;

use DateTimeImmutable;
use LogiDock\Core\Page;
use LogiDock\Core\Pagination;
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
     * Una pagina dell'elenco dei bancali, dal più vecchio. $requestedPage arriva grezzo dall'indirizzo.
     *
     * @param bool $onlyAging true = solo i bancali fermi oltre la soglia
     * @return Page<StockPallet>
     */
    public function palletsPage(
        AgingRule $rule,
        ?int $productId,
        ?int $locationId,
        bool $onlyAging,
        mixed $requestedPage,
        int $perPage
    ): Page {
        $before = $onlyAging ? $rule->receivedBefore() : null;
        $pagination = new Pagination($this->stock->countPallets($productId, $locationId, $before), $perPage, $requestedPage);

        return new Page(
            $this->stock->pallets($productId, $locationId, $before, $pagination->perPage, $pagination->offset()),
            $pagination
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
