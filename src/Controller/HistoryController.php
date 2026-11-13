<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\HistoryUrl;
use LogiDock\Core\HttpException;
use LogiDock\Core\Request;
use LogiDock\Core\Response;
use LogiDock\Core\View;
use LogiDock\Service\AuthService;
use LogiDock\Service\CustomerService;
use LogiDock\Service\HistoryService;
use LogiDock\Service\RoundService;
use LogiDock\Service\SupplierService;

/**
 * Storico: viaggi, merce per fornitore, incassi e documenti emessi, in quattro schede (link, senza JS).
 * Il controller legge solo la query string; filtri e regole stanno in HistoryService.
 */
final class HistoryController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthService $auth,
        private readonly HistoryService $history,
        private readonly RoundService $rounds,
        private readonly SupplierService $suppliers,
        private readonly CustomerService $customers
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $user = $this->auth->user() ?? throw new HttpException(403, 'Utente non collegato');
        $isAdmin = $user->isAdmin();
        $tab = HistoryUrl::tab($request->query('scheda'));
        $filters = $this->history->normalizeFilters($this->queryStrings($request), $isAdmin);

        // Alla vista arrivano solo i dati della scheda scelta e le opzioni dei suoi filtri.
        $data = match ($tab) {
            'fornitori' => [
                'suppliers' => $this->history->suppliers($filters),
                'supplierOptions' => $this->options($this->suppliers->search()),
            ],
            'incassi' => [
                'report' => $this->history->payments($filters),
                'customerOptions' => $this->options($this->customers->search()),
            ],
            'documenti' => [
                'documents' => $this->history->documents($filters, $isAdmin),
                'customerOptions' => $this->options($this->customers->search()),
            ],
            default => [
                'trips' => $this->history->trips($filters, $isAdmin),
                'roundOptions' => $this->options($this->rounds->search()),
            ],
        };

        return Response::html($this->view->render('history/index', [
            'title' => 'Storico',
            'wide' => true,
            'tab' => $tab,
            'tabs' => HistoryUrl::TABS,
            'filters' => $filters,
            'currentUrl' => HistoryUrl::build($tab, $filters),
            'listLimit' => HistoryService::LIST_LIMIT,
        ] + $data));
    }

    /**
     * @param list<object{id: int, name: string}> $items
     * @return array<string, string> id => nome
     */
    private function options(array $items): array
    {
        $options = [];
        foreach ($items as $item) {
            $options[(string) $item->id] = $item->name;
        }

        return $options;
    }

    /**
     * Solo i parametri testuali: un array (?dal[]=x) non deve arrivare ai filtri.
     *
     * @return array<string, string>
     */
    private function queryStrings(Request $request): array
    {
        $values = [];
        foreach (['dal', 'al', 'giro', 'stato', 'fornitore', 'cliente', 'pagamento', 'tipo'] as $name) {
            $value = $request->query($name);
            if (is_string($value)) {
                $values[$name] = $value;
            }
        }

        return $values;
    }
}
