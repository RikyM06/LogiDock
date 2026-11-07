<?php
/**
 * Form di una richiesta (nuova o da modificare): testata (cliente e date) e una riga per prodotto.
 *
 * @var string                                     $title
 * @var string                                     $action
 * @var string                                     $cancelUrl
 * @var array<string, string>                      $values
 * @var array<string, string>                      $errors      errori della testata (e "lines" se mancano le righe)
 * @var array<int|string, array<string, string>>   $lines
 * @var array<string, array<string, string>>       $lineErrors  indice riga => campo => messaggio
 * @var array<string, string>                      $customerOptions
 * @var array<string, string>                      $emptyLine
 * @var string                                     $csrfToken
 * @var callable                                   $partial
 */
$nextIndex = $lines === [] ? 0 : max(array_map('intval', array_keys($lines))) + 1;
?>
<div class="page-header">
    <h1><?= e($title) ?></h1>
</div>

<form method="post" action="<?= e($action) ?>" class="form" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

    <div class="card">
        <h2>Cliente e date</h2>
        <?= $partial('select', [
            'name' => 'customer_id',
            'label' => 'Cliente',
            'options' => $customerOptions,
            'blank' => 'Scegli...',
            'required' => true,
        ]) ?>
        <div class="form__row">
            <?= $partial('field', ['name' => 'requested_date', 'label' => 'Data della richiesta', 'type' => 'date']) ?>
            <?= $partial('field', ['name' => 'delivery_date', 'label' => 'Consegna desiderata', 'type' => 'date']) ?>
        </div>
        <?= $partial('field', ['name' => 'notes', 'label' => 'Note', 'maxlength' => 500, 'required' => false]) ?>
    </div>

    <div class="card">
        <h2>Prodotti</h2>
        <p class="muted">Una riga per prodotto, con la quantità nella sua unità di misura e il prezzo di vendita per unità.
            Le righe lasciate vuote vengono ignorate.</p>

        <?php if (isset($errors['lines'])) : ?>
            <p class="alert alert--error" role="alert"><?= e($errors['lines']) ?></p>
        <?php endif; ?>

        <div class="table-wrap">
            <table class="table table--lines">
                <thead>
                    <tr>
                        <th>Prodotto</th>
                        <th>Quantità</th>
                        <th>Prezzo di vendita (€)</th>
                        <th><span class="visually-hidden">Togli</span></th>
                    </tr>
                </thead>
                <tbody data-lines data-next-index="<?= e($nextIndex) ?>">
                    <?php foreach ($lines as $index => $line) : ?>
                        <?= $partial('order_line', [
                            'index' => $index,
                            'line' => $line,
                            'rowErrors' => $lineErrors[(string) $index] ?? [],
                        ]) ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p><button type="button" class="button button--secondary" data-add-line>Aggiungi un prodotto</button></p>

        <!-- Modello di riga: il JavaScript lo copia sostituendo __INDEX__ con il numero della nuova riga. -->
        <template id="line-template">
            <?= $partial('order_line', ['index' => '__INDEX__', 'line' => $emptyLine, 'rowErrors' => []]) ?>
        </template>
    </div>

    <div class="form__actions">
        <button type="submit" class="button">Salva richiesta</button>
        <a href="<?= e($cancelUrl) ?>">Annulla</a>
    </div>
</form>
