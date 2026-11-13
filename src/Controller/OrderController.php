<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\HttpException;
use LogiDock\Core\Request;
use LogiDock\Core\Response;
use LogiDock\Core\ReturnUrl;
use LogiDock\Core\Session;
use LogiDock\Core\ValidationException;
use LogiDock\Core\View;
use LogiDock\Model\Customer;
use LogiDock\Model\Order;
use LogiDock\Model\Product;
use LogiDock\Service\AuthService;
use LogiDock\Service\CustomerService;
use LogiDock\Service\OrderNotEditableException;
use LogiDock\Service\OrderService;
use LogiDock\Service\PaymentNotAllowedException;
use LogiDock\Service\PaymentService;
use LogiDock\Service\ProductService;

/**
 * Richieste dei clienti. Le registra qualunque utente collegato (è il lavoro di ogni giorno),
 * non solo gli admin. Controller sottile: le regole stanno in OrderService.
 */
final class OrderController
{
    /** Righe vuote mostrate in un form nuovo. */
    private const EMPTY_LINES = 3;

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly AuthService $auth,
        private readonly OrderService $orders,
        private readonly CustomerService $customers,
        private readonly ProductService $products,
        private readonly PaymentService $payments
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $status = (string) $request->query('status', '');
        $status = isset(Order::STATUS_LABELS[$status]) ? $status : '';

        return Response::html($this->view->render('orders/index', [
            'title' => 'Richieste',
            'items' => $this->orders->search($search, $status === '' ? null : $status),
            'search' => $search,
            'status' => $status,
            'statusLabels' => Order::STATUS_LABELS,
        ]));
    }

    /**
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $values = ['customer_id' => '', 'requested_date' => date('Y-m-d'), 'delivery_date' => '', 'notes' => ''];

        return $this->renderForm('Nuova richiesta', '/orders', $values, $this->emptyLines(), null);
    }

    /**
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        $user = $this->auth->user() ?? throw new HttpException(403, 'Utente non collegato');

        try {
            $id = $this->orders->create($request->allInput(), $user->id);
        } catch (ValidationException $e) {
            return $this->renderForm(
                'Nuova richiesta',
                '/orders',
                $this->headerValues($request),
                $this->lineValues($request),
                null,
                $e->errors(),
                422
            );
        }

        $order = $this->orders->find($id);
        $this->session->flash('success', 'Richiesta ' . ($order?->code ?? '') . ' registrata.');

        return Response::redirect('/orders/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $order = $this->findOrFail($params);

        return Response::html($this->view->render('orders/show', [
            'title' => 'Richiesta ' . $order->code,
            'order' => $order,
            'lines' => $this->orders->linesOf($order->id),
            // L'importo da incassare serve solo a una richiesta spedita e non ancora pagata.
            'amountDue' => $order->status === Order::STATUS_SHIPPED && !$order->isPaid()
                ? $this->payments->amountDue($order->id)
                : null,
        ]));
    }

    /**
     * Segna la richiesta come pagata dal cliente (importo = somma dei DDT). Qualunque utente collegato.
     *
     * @param array<string, string> $params
     */
    public function pay(Request $request, array $params): Response
    {
        $user = $this->auth->user() ?? throw new HttpException(403, 'Utente non collegato');
        $order = $this->findOrFail($params);
        $back = ReturnUrl::pick($request->input('return', ''), '/orders/' . $order->id);

        try {
            $amount = $this->payments->markOrderPaid($order->id, $user->id);
        } catch (PaymentNotAllowedException $e) {
            return $this->refuse($order, $e->getMessage(), $back);
        }

        $this->session->flash(
            'success',
            'Richiesta ' . $order->code . ' segnata come pagata: ' . str_replace('.', ',', $amount) . ' €.'
        );

        return Response::redirect($back);
    }

    /**
     * Annulla il pagamento di una richiesta (errore di registrazione). Solo admin (agli altri: 404).
     *
     * @param array<string, string> $params
     */
    public function unpay(Request $request, array $params): Response
    {
        $user = $this->auth->user();
        if ($user === null || !$user->isAdmin()) {
            throw new HttpException(404, 'Pagina non trovata');
        }
        $order = $this->findOrFail($params);
        $back = ReturnUrl::pick($request->input('return', ''), '/orders/' . $order->id);

        try {
            $this->payments->undoOrderPayment($order->id, $user->id);
        } catch (PaymentNotAllowedException $e) {
            return $this->refuse($order, $e->getMessage(), $back);
        }

        $this->session->flash('success', 'Pagamento della richiesta ' . $order->code . ' annullato.');

        return Response::redirect($back);
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $order = $this->findOrFail($params);
        if (!$order->isEditable()) {
            return $this->refuse($order, 'Si possono modificare solo le richieste aperte.');
        }

        $lines = [];
        foreach ($this->orders->linesOf($order->id) as $line) {
            $lines[] = ['product_id' => (string) $line->productId, 'quantity' => $line->quantity, 'unit_price' => $line->unitPrice];
        }

        return $this->renderForm(
            'Modifica richiesta ' . $order->code,
            '/orders/' . $order->id,
            [
                'customer_id' => (string) $order->customerId,
                'requested_date' => $order->requestedDate,
                'delivery_date' => $order->deliveryDate,
                'notes' => (string) $order->notes,
            ],
            $lines,
            $order
        );
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $order = $this->findOrFail($params);

        try {
            $this->orders->update($order->id, $request->allInput());
        } catch (OrderNotEditableException $e) {
            return $this->refuse($order, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->renderForm(
                'Modifica richiesta ' . $order->code,
                '/orders/' . $order->id,
                $this->headerValues($request),
                $this->lineValues($request),
                $order,
                $e->errors(),
                422
            );
        }

        $this->session->flash('success', 'Richiesta ' . $order->code . ' aggiornata.');

        return Response::redirect('/orders/' . $order->id);
    }

    /**
     * @param array<string, string> $params
     */
    public function cancel(Request $request, array $params): Response
    {
        $order = $this->findOrFail($params);

        try {
            $this->orders->cancel($order->id);
        } catch (OrderNotEditableException $e) {
            return $this->refuse($order, $e->getMessage());
        }

        $this->session->flash('success', 'Richiesta ' . $order->code . ' annullata.');

        return Response::redirect('/orders/' . $order->id);
    }

    private function refuse(Order $order, string $message, ?string $url = null): Response
    {
        $this->session->flash('error', $message);

        return Response::redirect($url ?? '/orders/' . $order->id);
    }

    /**
     * @param array<string, string> $params
     */
    private function findOrFail(array $params): Order
    {
        $id = $params['id'] ?? '';
        $order = ctype_digit($id) ? $this->orders->find((int) $id) : null;
        if ($order === null) {
            throw new HttpException(404, 'Richiesta non trovata: ' . $id);
        }

        return $order;
    }

    /**
     * @return array<string, string>
     */
    private function headerValues(Request $request): array
    {
        $values = [];
        foreach (['customer_id', 'requested_date', 'delivery_date', 'notes'] as $field) {
            $input = $request->input($field, '');
            $values[$field] = is_scalar($input) ? (string) $input : '';
        }

        return $values;
    }

    /**
     * Le righe come le ha scritte l'utente, con gli stessi indici (per gli errori sotto la riga giusta).
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
        return ['product_id' => '', 'quantity' => '', 'unit_price' => ''];
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
    private function renderForm(
        string $title,
        string $action,
        array $values,
        array $lines,
        ?Order $current,
        array $errors = [],
        int $status = 200
    ): Response {
        $headerErrors = [];
        $lineErrors = [];
        foreach ($errors as $field => $message) {
            if (preg_match('/^lines\.([^.]+)\.(.+)$/', $field, $matches) === 1) {
                $lineErrors[$matches[1]][$matches[2]] = $message;
            } else {
                $headerErrors[$field] = $message;
            }
        }

        // Clienti attivi (con il loro giro), più quello già sulla richiesta anche se disattivato.
        $customerOptions = [];
        foreach ($this->customers->activeOnes() as $customer) {
            if ($customer instanceof Customer) {
                $customerOptions[(string) $customer->id] = $customer->name
                    . ($customer->roundName !== null ? ' (' . $customer->roundName . ')' : ' (giro non assegnato)');
            }
        }
        if ($current !== null && !isset($customerOptions[(string) $current->customerId])) {
            $customerOptions[(string) $current->customerId] = $current->customerName . ' (disattivato)';
        }

        // Prodotti attivi, più quelli già nella richiesta.
        $currentProductIds = [];
        if ($current !== null) {
            foreach ($this->orders->linesOf($current->id) as $line) {
                $currentProductIds[$line->productId] = true;
            }
        }
        $productOptions = [];
        foreach ($this->products->search() as $product) {
            if ($product instanceof Product && ($product->isActive || isset($currentProductIds[$product->id]))) {
                $productOptions[(string) $product->id] = $product->code . ' - ' . $product->description
                    . ' (' . $product->unitOfMeasure . ')' . ($product->isActive ? '' : ' (disattivato)');
            }
        }

        return Response::html($this->view->render('orders/form', [
            'title' => $title,
            'action' => $action,
            'path' => '/orders',
            'cancelUrl' => $current !== null ? '/orders/' . $current->id : '/orders',
            'values' => $values,
            'errors' => $headerErrors,
            'lines' => $lines,
            'lineErrors' => $lineErrors,
            'customerOptions' => $customerOptions,
            'productOptions' => $productOptions,
            'emptyLine' => $this->emptyLine(),
        ]), $status);
    }
}
