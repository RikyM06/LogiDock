<?php
/**
 * Form condiviso da "Nuovo tipo di bancale" e "Modifica tipo di bancale".
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

        <div class="form__row">
            <?= $partial('field', ['name' => 'code', 'label' => 'Codice', 'maxlength' => 30]) ?>
            <?= $partial('field', ['name' => 'name', 'label' => 'Nome', 'maxlength' => 100]) ?>
        </div>
        <div class="form__row">
            <?= $partial('field', ['name' => 'length_mm', 'label' => 'Lunghezza (mm)', 'maxlength' => 4, 'inputmode' => 'numeric']) ?>
            <?= $partial('field', ['name' => 'width_mm', 'label' => 'Larghezza (mm)', 'maxlength' => 4, 'inputmode' => 'numeric']) ?>
        </div>
        <?= $partial('field', [
            'name' => 'max_height_mm',
            'label' => 'Altezza massima di carico (mm)',
            'maxlength' => 4,
            'inputmode' => 'numeric',
            'required' => false,
            'hint' => 'Lasciala vuota per usare l\'altezza massima delle impostazioni.',
        ]) ?>

        <div class="form__actions">
            <button type="submit" class="button">Salva</button>
            <a href="<?= e($path) ?>">Annulla</a>
        </div>
    </form>
</div>
