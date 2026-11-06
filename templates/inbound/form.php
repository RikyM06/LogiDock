<?php
/**
 * Form di un nuovo arrivo: testata (fornitore e documento) e una riga per ogni bancale.
 *
 * @var string                                     $title
 * @var string                                     $action
 * @var string                                     $path
 * @var array<string, string>                      $values
 * @var array<string, string>                      $errors      errori della testata (e "lines" se mancano le righe)
 * @var array<int|string, array<string, string>>   $lines
 * @var array<string, array<string, string>>       $lineErrors  indice riga => campo => messaggio
 * @var array<string, string>                      $supplierOptions
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
        <h2>Documento del fornitore</h2>
        <div class="form__row">
            <?= $partial('select', [
                'name' => 'supplier_id',
                'label' => 'Fornitore',
                'options' => $supplierOptions,
                'blank' => 'Scegli...',
                'required' => true,
            ]) ?>
            <?= $partial('field', ['name' => 'number', 'label' => 'Numero del documento', 'maxlength' => 50]) ?>
        </div>
        <div class="form__row">
            <?= $partial('field', ['name' => 'document_date', 'label' => 'Data del documento', 'type' => 'date']) ?>
            <?= $partial('field', ['name' => 'received_date', 'label' => 'Data di arrivo', 'type' => 'date']) ?>
        </div>
        <?= $partial('field', ['name' => 'notes', 'label' => 'Note', 'maxlength' => 500, 'required' => false]) ?>
    </div>

    <div class="card">
        <h2>Bancali</h2>
        <p class="muted">Una riga per bancale. Le righe lasciate completamente vuote vengono ignorate.</p>

        <?php if (isset($errors['lines'])) : ?>
            <p class="alert alert--error" role="alert"><?= e($errors['lines']) ?></p>
        <?php endif; ?>

        <div class="table-wrap">
            <table class="table table--lines">
                <thead>
                    <tr>
                        <th>Prodotto</th>
                        <th>Tipo di bancale</th>
                        <th>Quantità</th>
                        <th>Prezzo unitario (€)</th>
                        <th>Peso segnato (kg)</th>
                        <th>Lotto</th>
                        <th>Scadenza</th>
                        <th>Posizione</th>
                        <th><span class="visually-hidden">Togli</span></th>
                    </tr>
                </thead>
                <tbody data-lines data-next-index="<?= e($nextIndex) ?>">
                    <?php foreach ($lines as $index => $line) : ?>
                        <?= $partial('inbound_line', [
                            'index' => $index,
                            'line' => $line,
                            'rowErrors' => $lineErrors[(string) $index] ?? [],
                        ]) ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p><button type="button" class="button button--secondary" data-add-line>Aggiungi un bancale</button></p>

        <!-- Modello di riga: il JavaScript lo copia sostituendo __INDEX__ con il numero della nuova riga. -->
        <template id="line-template">
            <?= $partial('inbound_line', ['index' => '__INDEX__', 'line' => $emptyLine, 'rowErrors' => []]) ?>
        </template>
    </div>

    <div class="form__actions">
        <button type="submit" class="button">Registra arrivo</button>
        <a href="<?= e($path) ?>">Annulla</a>
    </div>
</form>
