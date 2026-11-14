<?php
/**
 * @var list<\LogiDock\Model\Order> $items
 * @var string                      $search
 * @var string                      $status        stato filtrato ("" = tutti)
 * @var array<string, string>       $statusLabels  stato => nome
 */
$filtered = $search !== '' || $status !== '';
?>
<?= $partial('breadcrumb', ['crumbs' => [['Richieste']]]) ?>
<div class="page-header">
    <h1>Richieste</h1>
    <a class="button" href="/orders/create">Nuova richiesta</a>
</div>

<form method="get" action="/orders" class="toolbar" role="search">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Cerca per codice o cliente" aria-label="Cerca" maxlength="100">
    <select name="status" aria-label="Stato">
        <option value="">Tutti gli stati</option>
        <?php foreach ($statusLabels as $value => $label) : ?>
            <option value="<?= e($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="button button--secondary">Cerca</button>
    <?php if ($filtered) : ?>
        <a href="/orders">Azzera</a>
    <?php endif; ?>
</form>

<?php if ($items === []) : ?>
    <p class="card"><?= $filtered ? 'Nessuna richiesta corrisponde alla ricerca.' : 'Non ci sono ancora richieste.' ?></p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Codice</th>
                    <th>Cliente</th>
                    <th>Giro</th>
                    <th>Consegna</th>
                    <th class="table__number">Prodotti</th>
                    <th class="table__number">Valore (€)</th>
                    <th>Stato</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr<?= $item->status === \LogiDock\Model\Order::STATUS_CANCELLED ? ' class="is-inactive"' : '' ?>>
                        <td class="nowrap"><a href="/orders/<?= e($item->id) ?>"><strong><?= e($item->code) ?></strong></a></td>
                        <td><?= e($item->customerName) ?></td>
                        <td><?= $item->roundName === null ? '<span class="muted">Non assegnato</span>' : e($item->roundName) ?></td>
                        <td class="nowrap"><?= e(date('d/m/Y', strtotime($item->deliveryDate))) ?></td>
                        <td class="table__number"><?= e($item->lineCount) ?></td>
                        <td class="table__number"><?= e(str_replace('.', ',', $item->totalValue)) ?></td>
                        <td><span class="badge badge--<?= e($item->status) ?>"><?= e($item->statusLabel()) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">Si vedono le ultime 200 richieste, dalla consegna più lontana alla più vicina.</p>
<?php endif; ?>
