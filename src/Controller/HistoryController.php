<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\HistoryUrl;
use LogiDock\Core\HttpException;
use LogiDock\Core\Pagination;
use LogiDock\Core\Request;
use LogiDock\Core\Response;
use LogiDock\Core\View;
use LogiDock\Service\AuthService;
use LogiDock\Service\CustomerService;
use LogiDock\Service\HistoryService;
use LogiDock\Service\ListPager;
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
        private readonly CustomerService $customers,
        private readonly ListPager $pager
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

        $requestedPage = $request->query(Pagination::PARAM);
        $perPage = $this->pager->perPage();

        // Alla vista arrivano solo i dati della scheda scelta (una pagina) e le opzioni dei suoi filtri.
        switch ($tab) {
            case 'fornitori':
                $page = $this->history->suppliers($filters, $requestedPage, $perPage);
                $pagination = $page->pagination;
                $data = [
                    'suppliers' => $page->items,
                    'supplierOptions' => $this->options($this->suppliers->search()),
                ];
                break;
            case 'incassi':
                $report = $this->history->payments($filters, $requestedPage, $perPage);
                $pagination = $report->pagination;
                $data = [
                    'report' => $report,
                    'customerOptions' => $this->options($this->customers->search()),
                ];
                break;
            case 'documenti':
                $page = $this->history->documents($filters, $isAdmin, $requestedPage, $perPage);
                $pagination = $page->pagination;
                $data = [
                    'documents' => $page->items,
                    'customerOptions' => $this->options($this->customers->search()),
                ];
                break;
            default:
                $page = $this->history->trips($filters, $isAdmin, $requestedPage, $perPage);
                $pagination = $page->pagination;
                $data = [
                    'trips' => $page->items,
                    'roundOptions' => $this->options($this->rounds->search()),
                ];
        }

        return Response::html($this->view->render('history/index', [
            'title' => 'Storico',
            'wide' => true,
            'tab' => $tab,
            'tabs' => HistoryUrl::TABS,
            'filters' => $filters,
            // Con la pagina: dopo "Segna pagata" si torna alla stessa pagina.
            'currentUrl' => HistoryUrl::build($tab, $filters, $pagination->page),
            'pagination' => $pagination,
            'query' => HistoryUrl::query($tab, $filters),
        ] + $data));
    }

    /**
     * @param list<object{id: int, name: string}> $items
     * @return array<int, string> id => nome
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
