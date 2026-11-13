<?php
/**
 * Dettaglio di una richiesta.
 *
 * @var \LogiDock\Model\Order            $order
 * @var list<\LogiDock\Model\OrderLine>  $lines
 * @var string|null                      $amountDue  importo da incassare (solo richiesta spedita non pagata)
 * @var string                           $csrfToken
 * @var \LogiDock\Model\User|null        $currentUser
 */
$it = static fn (string $decimal): string => str_replace('.', ',', $decimal);
$short = count(array_filter($lines, static fn ($line): bool => $line->stockShort));
?>
<div class="page-header">
    <h1>Richiesta <?= e($order->code) ?></h1>
    <div class="page-header__actions">
        <?php if ($order->isEditable()) : ?>
            <a class="button" href="/orders/<?= e($order->id) ?>/edit">Modifica</a>
            <form method="post" action="/orders/<?= e($order->id) ?>/cancel"
                  data-confirm="Annullare la richiesta <?= e($order->code) ?>? Non si potrà più modificare.">
                <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                <button type="submit" class="button button--danger">Annulla richiesta</button>
            </form>
        <?php endif; ?>
        <a class="button button--secondary" href="/orders">Tutte le richieste</a>
    </div>
</div>

<div class="card">
    <dl class="details">
        <div><dt>Cliente</dt><dd><?= e($order->customerName) ?></dd></div>
        <div><dt>Giro</dt><dd><?= $order->roundName === null ? '<span class="muted">Non assegnato</span>' : e($order->roundName) ?></dd></div>
        <div><dt>Data della richiesta</dt><dd><?= e(date('d/m/Y', strtotime($order->requestedDate))) ?></dd></div>
        <div><dt>Consegna desiderata</dt><dd><?= e(date('d/m/Y', strtotime($order->deliveryDate))) ?></dd></div>
        <div><dt>Stato</dt><dd><span class="badge badge--<?= e($order->status) ?>"><?= e($order->statusLabel()) ?></span></dd></div>
        <div><dt>Valore</dt><dd><?= e($it($order->totalValue)) ?> €</dd></div>
        <?php if ($order->status === \LogiDock\Model\Order::STATUS_SHIPPED) : ?>
            <div>
                <dt>Pagamento</dt>
                <dd>
                    <?php if ($order->isPaid()) : ?>
                        Pagata il <?= e((new \DateTimeImmutable((string) $order->paidAt, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d/m/Y')) ?>
                        (<?= e($it((string) $order->paidAmount)) ?> €)
                        <?php if ($order->paidByName !== null) : ?>
                            <span class="muted">registrato da <?= e($order->paidByName) ?></span>
                        <?php endif; ?>
                        <?php if ($currentUser?->isAdmin()) : ?>
                            <form method="post" class="inline-form" action="/orders/<?= e($order->id) ?>/unpay"
                                  data-confirm="Annullare il pagamento della richiesta <?= e($order->code) ?>?">
                                <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                <button type="submit" class="button button--secondary button--small">Annulla pagamento</button>
                            </form>
                        <?php endif; ?>
                    <?php else : ?>
                        Da incassare<?= $amountDue !== null ? ': ' . e($it($amountDue)) . ' €' : '' ?>
                        <form method="post" class="inline-form" action="/orders/<?= e($order->id) ?>/pay"
                              data-confirm="Segnare pagata la richiesta <?= e($order->code) ?><?= $amountDue !== null ? ' (' . e($it($amountDue)) . ' €)' : '' ?>?">
                            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                            <button type="submit" class="button button--secondary button--small">Segna pagata</button>
                        </form>
                    <?php endif; ?>
                </dd>
            </div>
        <?php endif; ?>
    </dl>
    <?php if ($order->notes !== null) : ?>
        <p class="muted"><?= e($order->notes) ?></p>
    <?php endif; ?>
</div>

<?php if ($short > 0 && $order->isEditable()) : ?>
    <p class="alert alert--warning" role="status">
        <?= $short === 1 ? 'Per 1 prodotto' : 'Per ' . e($short) . ' prodotti' ?> in magazzino adesso c'è meno di quanto richiesto.
        È solo un'indicazione: cosa va a ogni cliente lo deciderà la pianificazione dei viaggi.
    </p>
<?php endif; ?>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Prodotto</th>
                <th class="table__number">Quantità</th>
                <th class="table__number">Prezzo (€)</th>
                <th class="table__number">Valore (€)</th>
                <th class="table__number">In magazzino</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $line) : ?>
                <tr<?= $line->stockShort && $order->isEditable() ? ' class="is-warning"' : '' ?>>
                    <td><strong><?= e($line->productCode) ?></strong> <span class="muted"><?= e($line->productDescription) ?></span></td>
                    <td class="table__number nowrap"><?= e($it($line->quantity)) ?> <?= e($line->unitOfMeasure) ?></td>
                    <td class="table__number"><?= e($it($line->unitPrice)) ?></td>
                    <td class="table__number"><?= e($it($line->lineValue)) ?></td>
                    <td class="table__number nowrap"><?= e($it($line->stockQuantity)) ?> <?= e($line->unitOfMeasure) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3">Totale</th>
                <th class="table__number"><?= e($it($order->totalValue)) ?></th>
                <th></th>
            </tr>
        </tfoot>
    </table>
</div>
