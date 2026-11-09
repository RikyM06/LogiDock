<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\Request;
use LogiDock\Core\Response;
use LogiDock\Core\View;
use LogiDock\Service\StockService;

final class HomeController
{
    /** Bancali fermi mostrati nell'avviso della home: gli altri si vedono nella pagina Giacenze. */
    private const AGING_PREVIEW = 5;

    public function __construct(
        private readonly View $view,
        private readonly StockService $stock
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $rule = $this->stock->agingRule();

        return Response::html($this->view->render('home', [
            'title' => 'Home',
            'rule' => $rule,
            'agingCount' => $this->stock->agingCount($rule),
            'agingPallets' => $this->stock->oldestAging($rule, self::AGING_PREVIEW),
        ]));
    }

    /**
     * Indice delle anagrafiche (fornitori, clienti, veicoli...).
     *
     * @param array<string, string> $params
     */
    public function masterData(Request $request, array $params): Response
    {
        return Response::html($this->view->render('master-data', ['title' => 'Anagrafiche']));
    }
}
