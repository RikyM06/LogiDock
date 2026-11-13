<?php
/**
 * Scheda Incassi: una riga per richiesta con almeno un viaggio partito. I totali valgono per TUTTE le
 * richieste che rispettano i filtri, anche oltre le righe mostrate. "Parziale" = spedita solo in parte:
 * non si può ancora pagare, quindi niente pulsante.
 *
 * @var \LogiDock\Model\History\PaymentReport $report
 * @var string                                $csrfToken
 * @var string                                $currentUrl
 */
$stateBadges = ['paid' => 'ok', 'unpaid' => 'aging', 'partial' => 'off'];
?>
<div class="card">
    <dl class="details">
        <div><dt>Incassato</dt><dd><?= e($it($report->collectedTotal)) ?> €</dd></div>
        <div><dt>Da incassare</dt><dd><?= e($it($report->dueTotal)) ?> €</dd></div>
        <div><dt>Spedito, non ancora incassabile</dt><dd><?= e($it($report->partialTotal)) ?> €</dd></div>
    </dl>
</div>

<?php if ($report->rows === []) : ?>
    <p class="card card--spaced"><?= $filtered ? 'Nessuna richiesta corrisponde ai filtri.' : 'Non ci sono ancora richieste spedite.' ?></p>
<?php else : ?>
    <div class="table-wrap card--spaced">
        <table class="table">
            <thead>
                <tr>
                    <th>Richiesta</th>
                    <th>Cliente</th>
                    <th>Consegna</th>
                    <th>Pagamento</th>
                    <th class="table__number">Importo</th>
                    <th>Pagata il</th>
                    <th><span class="visually-hidden">Azioni</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report->rows as $row) : ?>
                    <tr>
                        <td class="nowrap"><a href="/orders/<?= e($row->orderId) ?>"><strong><?= e($row->orderCode) ?></strong></a></td>
                        <td><?= e($row->customerName) ?></td>
                        <td class="nowrap"><?= e($date($row->deliveryDate)) ?></td>
                        <td><span class="badge badge--<?= e($stateBadges[$row->state] ?? 'off') ?>"><?= e($row->stateLabel()) ?></span></td>
                        <td class="table__number nowrap"><?= e($it($row->amount)) ?> €</td>
                        <td class="nowrap"><?= $row->paidAt === null ? '<span class="muted">-</span>' : e($local($row->paidAt, 'd/m/Y')) ?></td>
                        <td class="nowrap">
                            <?php if ($row->canBePaid()) : ?>
                                <form method="post" class="inline-form" action="/orders/<?= e($row->orderId) ?>/pay"
                                      data-confirm="Segnare pagata la richiesta <?= e($row->orderCode) ?> (<?= e($it($row->amount)) ?> €)?">
                                    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="return" value="<?= e($currentUrl) ?>">
                                    <button type="submit" class="button button--secondary button--small">Segna pagata</button>
                                </form>
                            <?php elseif ($row->state === 'paid' && $isAdmin) : ?>
                                <form method="post" class="inline-form" action="/orders/<?= e($row->orderId) ?>/unpay"
                                      data-confirm="Annullare il pagamento della richiesta <?= e($row->orderCode) ?>?">
                                    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="return" value="<?= e($currentUrl) ?>">
                                    <button type="submit" class="button button--secondary button--small">Annulla pagamento</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($report->rows) >= $listLimit) : ?>
        <p class="muted">Mostrate le prime <?= e($listLimit) ?> richieste (i totali sopra comprendono tutte): restringi il periodo per vedere le altre.</p>
    <?php endif; ?>
<?php endif; ?>
