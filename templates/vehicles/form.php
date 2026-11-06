<?php
/**
 * Form condiviso da "Nuovo veicolo" e "Modifica veicolo".
 *
 * @var string                $title
 * @var string                $action
 * @var string                $path
 * @var array<string, string> $trailerTypeOptions tipi di rimorchio (id => nome)
 * @var array<string, string> $carrierOptions     trasportatori (id => nome)
 * @var string                $csrfToken
 * @var callable              $partial
 */
?>
<div class="card card--form">
    <h1><?= e($title) ?></h1>

    <form method="post" action="<?= e($action) ?>" class="form" novalidate>
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

        <div class="form__row">
            <?= $partial('field', ['name' => 'plate', 'label' => 'Targa', 'maxlength' => 15]) ?>
            <?= $partial('field', ['name' => 'description', 'label' => 'Descrizione', 'maxlength' => 150]) ?>
        </div>
        <div class="form__row">
            <?= $partial('select', [
                'name' => 'trailer_type_id',
                'label' => 'Tipo di rimorchio',
                'options' => $trailerTypeOptions,
                'blank' => 'Non assegnato',
            ]) ?>
            <?= $partial('select', [
                'name' => 'carrier_id',
                'label' => 'Trasportatore (proprietario)',
                'options' => $carrierOptions,
                'blank' => 'Non assegnato',
            ]) ?>
        </div>
        <div class="form__row">
            <?= $partial('field', ['name' => 'max_weight_kg', 'label' => 'Portata massima (kg)', 'maxlength' => 11, 'inputmode' => 'decimal', 'comma' => true]) ?>
            <?= $partial('field', ['name' => 'max_pallets', 'label' => 'Bancali al massimo', 'maxlength' => 4, 'inputmode' => 'numeric']) ?>
        </div>

        <div class="form__actions">
            <button type="submit" class="button">Salva</button>
            <a href="<?= e($path) ?>">Annulla</a>
        </div>
    </form>
</div>
