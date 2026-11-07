<?php

declare(strict_types=1);

namespace LogiDock\Service;

use DateTimeImmutable;
use LogiDock\Core\Database;
use LogiDock\Core\ValidationException;
use LogiDock\Core\Validator;
use LogiDock\Model\Order;
use LogiDock\Model\OrderLine;
use LogiDock\Model\Product;
use LogiDock\Repository\NumberSequenceRepository;
use LogiDock\Repository\OrderRepository;

/**
 * Richieste dei clienti: un cliente, una data di richiesta, una data di consegna e più righe
 * (prodotto, quantità nell'unità di misura del prodotto, prezzo di vendita per unità).
 *
 * Si registrano, si modificano e si annullano finché sono "aperte". Dopo le prenderà in carico la
 * pianificazione dei viaggi. Non si cancellano mai: fanno parte dello storico.
 */
final class OrderService
{
    use LineInputHelpers;

    /** Righe al massimo in una richiesta. */
    private const MAX_LINES = 200;

    public function __construct(
        private readonly Database $database,
        private readonly OrderRepository $orders,
        private readonly NumberSequenceRepository $sequences,
        private readonly CustomerService $customers,
        private readonly ProductService $products
    ) {
    }

    /**
     * @param string|null $status uno degli stati di Order, o null per tutti
     * @return list<Order>
     */
    public function search(string $term = '', ?string $status = null): array
    {
        if ($status !== null && !isset(Order::STATUS_LABELS[$status])) {
            $status = null;
        }

        return $this->orders->search(trim($term), $status);
    }

    public function find(int $id): ?Order
    {
        return $this->orders->findById($id);
    }

    /**
     * @return list<OrderLine>
     */
    public function linesOf(int $orderId): array
    {
        return $this->orders->linesOf($orderId);
    }

    /**
     * Registra una richiesta e restituisce il suo id.
     *
     * @param array<string, mixed>   $input dati grezzi del form: testata e "lines"
     * @param DateTimeImmutable|null $now   l'ora corrente; si passa solo nei test
     * @throws ValidationException
     */
    public function create(array $input, int $userId, ?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable('now');
        [$header, $lines] = $this->validate($input, null, $now->format('Y-m-d'));

        return $this->database->transaction(function () use ($header, $lines, $userId, $now): int {
            $year = (int) $now->format('Y');
            $code = sprintf('ORD-%d-%06d', $year, $this->sequences->next('order', $year));

            $orderId = $this->orders->insert(
                $code,
                $header['customer_id'],
                $header['requested_date'],
                $header['delivery_date'],
                $header['notes'],
                $userId
            );
            $this->orders->replaceLines($orderId, $lines);

            return $orderId;
        });
    }

    /**
     * Modifica testata e righe di una richiesta aperta. Le righe vengono sostituite tutte.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     * @throws OrderNotEditableException se la richiesta non è più aperta
     */
    public function update(int $id, array $input, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable('now');
        $current = $this->editableOrFail($id);
        [$header, $lines] = $this->validate($input, $current, $now->format('Y-m-d'));

        $this->database->transaction(function () use ($id, $header, $lines): void {
            // Si ricontrolla subito prima di scrivere: riduce (senza chiuderla del tutto, non c'è un lock
            // sulla riga) la finestra in cui qualcuno la pianifica mentre un altro la modifica.
            $this->editableOrFail($id);

            $this->orders->updateHeader(
                $id,
                $header['customer_id'],
                $header['requested_date'],
                $header['delivery_date'],
                $header['notes']
            );
            $this->orders->replaceLines($id, $lines);
        });
    }

    /**
     * @throws OrderNotEditableException se la richiesta non è più aperta
     */
    public function cancel(int $id): void
    {
        $this->editableOrFail($id);
        $this->orders->setStatus($id, Order::STATUS_CANCELLED);
    }

    /**
     * @return array{
     *     0: array{customer_id: int, requested_date: string, delivery_date: string, notes: ?string},
     *     1: list<array{product_id: int, quantity: string, unit_price: string}>
     * }
     * @throws ValidationException
     */
    private function validate(array $input, ?Order $current, string $today): array
    {
        $v = new Validator($input);

        // Ammessi i clienti attivi, più quello già sulla richiesta (anche se nel frattempo disattivato).
        $customerIds = $this->idsOf($this->customers->activeOnes());
        if ($current !== null) {
            $customerIds[] = $current->customerId;
        }

        $header = [
            'customer_id' => $v->requiredReference('customer_id', 'Cliente', $customerIds),
            'requested_date' => $v->requiredDate('requested_date', 'Data della richiesta'),
            'delivery_date' => $v->requiredDate('delivery_date', 'Data di consegna'),
            'notes' => $v->optionalString('notes', 'Note', 500),
        ];

        if ($this->isDate($header['requested_date']) && $header['requested_date'] > $today) {
            $v->fail('requested_date', 'La data della richiesta non può essere nel futuro.');
        }
        if ($this->isDate($header['requested_date']) && $this->isDate($header['delivery_date'])
            && $header['delivery_date'] < $header['requested_date']) {
            $v->fail('delivery_date', 'La consegna non può essere prima della richiesta.');
        }

        $lines = $this->validateLines($input['lines'] ?? null, $current, $v);

        $v->assertValid();

        return [$header, $lines];
    }

    /**
     * Controlla le righe. Quelle completamente vuote si ignorano; gli errori vanno nel validatore
     * della testata con la chiave "lines.<n>.<campo>", così il form li mostra sotto la riga giusta.
     *
     * @return list<array{product_id: int, quantity: string, unit_price: string}>
     */
    private function validateLines(mixed $rawLines, ?Order $current, Validator $header): array
    {
        if (!is_array($rawLines)) {
            $rawLines = [];
        }

        if (count($rawLines) > self::MAX_LINES) {
            $header->fail('lines', 'Una richiesta può avere al massimo ' . self::MAX_LINES . ' righe.');

            return [];
        }

        // Ammessi i prodotti attivi, più quelli già presenti nella richiesta che si sta modificando.
        $productIds = $this->idsOf(array_filter(
            $this->products->search(),
            static fn (Product $product): bool => $product->isActive
        ));
        if ($current !== null) {
            foreach ($this->orders->linesOf($current->id) as $line) {
                $productIds[] = $line->productId;
            }
        }

        $valid = [];
        $seenProducts = [];
        foreach ($rawLines as $index => $rawLine) {
            if (!is_array($rawLine) || $this->isBlank($rawLine)) {
                continue;
            }

            $line = new Validator($rawLine);
            $values = [
                'product_id' => $line->requiredReference('product_id', 'Prodotto', $productIds),
                // DECIMAL(12,3): 9 cifre intere. DECIMAL(12,4): 8 cifre intere.
                'quantity' => $line->requiredDecimal('quantity', 'Quantità', 9, 3, false),
                'unit_price' => $line->requiredDecimal('unit_price', 'Prezzo di vendita (€)', 8, 4),
            ];

            // Un prodotto una volta sola per richiesta: le quantità si scrivono su un'unica riga.
            if ($values['product_id'] !== 0 && isset($seenProducts[$values['product_id']])) {
                $line->fail('product_id', 'Questo prodotto è già in un\'altra riga: somma le quantità lì.');
            }

            try {
                $line->assertValid();
                $valid[] = $values;
                $seenProducts[$values['product_id']] = true;
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $message) {
                    $header->fail('lines.' . $index . '.' . $field, $message);
                }
            }
        }

        if ($valid === [] && !$this->hasLineErrors($header)) {
            $header->fail('lines', 'Inserisci almeno un prodotto.');
        }

        return $valid;
    }

    /**
     * @throws OrderNotEditableException
     */
    private function editableOrFail(int $id): Order
    {
        $order = $this->orders->findById($id);

        if ($order === null || !$order->isEditable()) {
            throw new OrderNotEditableException(
                'Si possono modificare o annullare solo le richieste aperte'
                . ($order !== null ? ' (questa è «' . $order->statusLabel() . '»).' : '.')
            );
        }

        return $order;
    }
}
