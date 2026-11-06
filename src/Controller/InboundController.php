<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\HttpException;
use LogiDock\Core\Request;
use LogiDock\Core\Response;
use LogiDock\Core\Session;
use LogiDock\Core\ValidationException;
use LogiDock\Core\View;
use LogiDock\Model\PalletType;
use LogiDock\Model\Product;
use LogiDock\Model\WarehouseLocation;
use LogiDock\Service\AuthService;
use LogiDock\Service\InboundService;
use LogiDock\Service\PalletTypeService;
use LogiDock\Service\ProductService;
use LogiDock\Service\SupplierService;
use LogiDock\Service\WarehouseLocationService;

/**
 * Arrivi di merce. Qualunque utente collegato può registrarli (è il lavoro di ogni giorno del
 * magazzino), non solo gli admin. Controller sottile: la logica sta in InboundService.
 */
final class InboundController
{
    /** Righe vuote mostrate in un form nuovo. */
    private const EMPTY_LINES = 3;

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly AuthService $auth,
        private readonly InboundService $inbound,
        private readonly SupplierService $suppliers,
        private readonly ProductService $products,
        private readonly PalletTypeService $palletTypes,
        private readonly WarehouseLocationService $locations
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        return Response::html($this->view->render('inbound/index', [
            'title' => 'Arrivi',
            'items' => $this->inbound->search($search),
            'search' => $search,
            'canManage' => true,
            'path' => '/inbound',
            'labels' => ['singular' => 'Arrivo', 'plural' => 'Arrivi', 'ending' => 'o'],
        ]));
    }

    /**
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $values = [
            'supplier_id' => '',
            'number' => '',
            'document_date' => '',
            'received_date' => date('Y-m-d'),
            'notes' => '',
        ];

        return $this->renderForm($values, $this->emptyLines());
    }

    /**
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        $user = $this->auth->user() ?? throw new HttpException(403, 'Utente non collegato');

        try {
            $id = $this->inbound->receive($request->allInput(), $user->id);
        } catch (ValidationException $e) {
            return $this->renderForm($this->headerValues($request), $this->lineValues($request), $e->errors(), 422);
        }

        $pallets = $this->inbound->palletsOf($id);
        $this->session->flash('success', 'Arrivo registrato: ' . count($pallets) . ' bancali.');

        $mismatches = count(array_filter($pallets, static fn ($pallet): bool => $pallet->weightMismatch));
        if ($mismatches > 0) {
            $this->session->flash(
                'warning',
                $mismatches . ' bancali hanno un peso segnato diverso da quello calcolato oltre la tolleranza: controllali.'
            );
        }

        return Response::redirect('/inbound/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        $document = ctype_digit($id) ? $this->inbound->find((int) $id) : null;
        if ($document === null) {
            throw new HttpException(404, 'Arrivo non trovato: ' . $id);
        }

        return Response::html($this->view->render('inbound/show', [
            'title' => 'Arrivo ' . $document->number,
            'document' => $document,
            'pallets' => $this->inbound->palletsOf($document->id),
        ]));
    }

    /**
     * @return array<string, string>
     */
    private function headerValues(Request $request): array
    {
        $values = [];
        foreach (['supplier_id', 'number', 'document_date', 'received_date', 'notes'] as $field) {
            $input = $request->input($field, '');
            $values[$field] = is_scalar($input) ? (string) $input : '';
        }

        return $values;
    }

    /**
     * Le righe come le ha scritte l'utente, con gli stessi indici (servono per mostrare gli errori
     * sotto la riga giusta). Se non ce n'è nessuna se ne mostrano di vuote.
     *
     * @return array<int|string, array<string, string>>
     */
    private function lineValues(Request $request): array
    {
        $raw = $request->input('lines', []);
        $lines = [];

        if (is_array($raw)) {
            foreach ($raw as $index => $rawLine) {
                if (!is_array($rawLine)) {
                    continue;
                }
                $line = $this->emptyLine();
                foreach (array_keys($line) as $field) {
                    $value = $rawLine[$field] ?? '';
                    $line[$field] = is_scalar($value) ? (string) $value : '';
                }
                $lines[$index] = $line;
            }
        }

        return $lines === [] ? $this->emptyLines() : $lines;
    }

    /**
     * @return array<string, string>
     */
    private function emptyLine(): array
    {
        return [
            'product_id' => '',
            'pallet_type_id' => '',
            'quantity' => '',
            'unit_price' => '',
            'recorded_weight_kg' => '',
            'lot_number' => '',
            'expiry_date' => '',
            'location_id' => '',
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function emptyLines(): array
    {
        return array_fill(0, self::EMPTY_LINES, $this->emptyLine());
    }

    /**
     * @param array<string, string>                    $values
     * @param array<int|string, array<string, string>> $lines
     * @param array<string, string>                    $errors errori di testata e di riga ("lines.<n>.<campo>")
     */
    private function renderForm(array $values, array $lines, array $errors = [], int $status = 200): Response
    {
        // Separa gli errori delle righe (lines.<n>.<campo>) da quelli della testata.
        $headerErrors = [];
        $lineErrors = [];
        foreach ($errors as $field => $message) {
            if (preg_match('/^lines\.([^.]+)\.(.+)$/', $field, $matches) === 1) {
                $lineErrors[$matches[1]][$matches[2]] = $message;
            } else {
                $headerErrors[$field] = $message;
            }
        }

        $supplierOptions = [];
        foreach ($this->suppliers->activeOnes() as $supplier) {
            $supplierOptions[(string) $supplier->id] = $supplier->name;
        }
        $productOptions = [];
        foreach ($this->products->search() as $product) {
            if ($product instanceof Product && $product->isActive) {
                $productOptions[(string) $product->id] = $product->code . ' - ' . $product->description;
            }
        }
        $palletTypeOptions = [];
        foreach ($this->palletTypes->activeOnes() as $type) {
            if ($type instanceof PalletType) {
                $palletTypeOptions[(string) $type->id] = $type->name . ' (' . $type->lengthMm . ' × ' . $type->widthMm . ' mm)';
            }
        }
        $locationOptions = [];
        foreach ($this->locations->activeOnes() as $location) {
            if ($location instanceof WarehouseLocation) {
                $locationOptions[(string) $location->id] = $location->code . ($location->zone !== null ? ' (' . $location->zone . ')' : '');
            }
        }

        return Response::html($this->view->render('inbound/form', [
            'title' => 'Nuovo arrivo',
            'action' => '/inbound',
            'path' => '/inbound',
            'values' => $values,
            'errors' => $headerErrors,
            'lines' => $lines,
            'lineErrors' => $lineErrors,
            'supplierOptions' => $supplierOptions,
            'productOptions' => $productOptions,
            'palletTypeOptions' => $palletTypeOptions,
            'locationOptions' => $locationOptions,
            'emptyLine' => $this->emptyLine(),
        ]), $status);
    }
}
