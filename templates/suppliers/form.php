<?php
/**
 * Form condiviso da "Nuovo fornitore" e "Modifica fornitore".
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

        <p class="muted">Tutti i campi sono obbligatori.</p>
        <?= $partial('field', ['name' => 'name', 'label' => 'Ragione sociale', 'maxlength' => 150]) ?>
        <?= $partial('field', ['name' => 'tax_code', 'label' => 'Partita IVA / codice fiscale', 'maxlength' => 30]) ?>
        <?= $partial('field', ['name' => 'address', 'label' => 'Indirizzo', 'maxlength' => 255]) ?>
        <div class="form__row">
            <?= $partial('field', ['name' => 'contact_email', 'label' => 'E-mail', 'maxlength' => 255]) ?>
            <?= $partial('field', ['name' => 'phone', 'label' => 'Telefono', 'maxlength' => 30]) ?>
        </div>

        <div class="form__actions">
            <button type="submit" class="button">Salva</button>
            <a href="<?= e($path) ?>">Annulla</a>
        </div>
    </form>
</div>
