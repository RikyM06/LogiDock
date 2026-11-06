<?php
/**
 * Form condiviso da "Nuovo trasportatore" e "Modifica trasportatore".
 *
 * @var string   $title
 * @var string   $action
 * @var string   $path
 * @var string   $csrfToken
 * @var callable $partial
 */
?>
<div class="card card--form">
    <h1><?= e($title) ?></h1>

    <form method="post" action="<?= e($action) ?>" class="form" novalidate>
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

        <?= $partial('field', ['name' => 'name', 'label' => 'Nome o ragione sociale', 'maxlength' => 150]) ?>
        <div class="form__row">
            <?= $partial('field', ['name' => 'tax_code', 'label' => 'Partita IVA / codice fiscale', 'maxlength' => 30, 'required' => false]) ?>
            <?= $partial('field', ['name' => 'phone', 'label' => 'Telefono', 'maxlength' => 30, 'required' => false]) ?>
        </div>
        <?= $partial('field', [
            'name' => 'trip_fee',
            'label' => 'Compenso per viaggio (€)',
            'maxlength' => 11,
            'inputmode' => 'decimal',
            'comma' => true,
            'hint' => 'Importo fisso pagato al trasportatore per ogni viaggio.',
        ]) ?>

        <div class="form__actions">
            <button type="submit" class="button">Salva</button>
            <a href="<?= e($path) ?>">Annulla</a>
        </div>
    </form>
</div>
