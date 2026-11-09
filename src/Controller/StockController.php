<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\Request;
use LogiDock\Core\Response;
use LogiDock\Core\View;
use LogiDock\Model\WarehouseLocation;
use LogiDock\Service\StockService;
use LogiDock\Service\WarehouseLocationService;

/**
 * Giacenze di magazzino: riepilogo per prodotto e lista dei bancali con i filtri.
 * La vede qualunque utente collegato (è il lavoro di ogni giorno del magazzino).
 */
final class StockController
{
    public function __construct(
        private readonly View $view,
        private readonly StockService $stock,
        private readonly WarehouseLocationService $locations
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $productId = $this->positiveId($request->query('product'));
        $locationId = $this->positiveId($request->query('location'));
        $onlyAging = $request->query('aging') === '1';

        // Una sola regola (soglia + data di oggi) per tutta la pagina.
        $rule = $this->stock->agingRule();
        $summary = $this->stock->summaryByProduct($rule);

        // Nel filtro compaiono solo i prodotti che sono in magazzino.
        $productOptions = [];
        foreach ($summary as $row) {
            $productOptions[(string) $row->productId] = $row->productCode . ' - ' . $row->productDescription;
        }
        $locationOptions = [];
        foreach ($this->locations->activeOnes() as $location) {
            if ($location instanceof WarehouseLocation) {
                $locationOptions[(string) $location->id] = $location->code;
            }
        }

        return Response::html($this->view->render('stock/index', [
            'title' => 'Giacenze',
            'wide' => true,
            'rule' => $rule,
            'summary' => $summary,
            'pallets' => $this->stock->pallets($rule, $productId, $locationId, $onlyAging),
            'agingCount' => $this->stock->agingCount($rule),
            'listLimit' => StockService::LIST_LIMIT,
            'productOptions' => $productOptions,
            'locationOptions' => $locationOptions,
            'filters' => ['product' => $productId, 'location' => $locationId, 'aging' => $onlyAging],
        ]));
    }

    /**
     * Un id dalla query string: solo cifre e maggiore di zero, altrimenti nessun filtro.
     */
    private function positiveId(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }
}
