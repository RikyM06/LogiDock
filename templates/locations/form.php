<?php
/**
 * Form condiviso da "Nuova posizione" e "Modifica posizione".
 *
 * @var string   $title
 * @var string   $action
 * @var string   $path
 * @var int      $defaultCapacity capacità predefinita, dalle impostazioni
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
            <?= $partial('field', ['name' => 'zone', 'label' => 'Zona', 'maxlength' => 50, 'required' => false]) ?>
        </div>
        <?= $partial('field', ['name' => 'description', 'label' => 'Descrizione', 'maxlength' => 255, 'required' => false]) ?>
        <?= $partial('field', [
            'name' => 'capacity',
            'label' => 'Capacità (bancali)',
            'maxlength' => 5,
            'inputmode' => 'numeric',
            'required' => false,
            'hint' => 'Se la lasci vuota vale la capacità predefinita (' . $defaultCapacity . ' bancali).',
        ]) ?>

        <div class="form__actions">
            <button type="submit" class="button">Salva</button>
            <a href="<?= e($path) ?>">Annulla</a>
        </div>
    </form>
</div>
