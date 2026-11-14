<?php
/**
 * Form condiviso da "Nuovo cliente" e "Modifica cliente".
 *
 * @var string                $title
 * @var string                $action
 * @var string                $path
 * @var array<string, string> $roundOptions giri tra cui scegliere (id => nome)
 * @var string                $csrfToken
 * @var callable              $partial
 */
?>
<?= $partial('breadcrumb', ['crumbs' => [['Anagrafiche', '/master-data'], [$labels['plural'], $path], [$title]]]) ?>
<div class="card card--form">
    <h1><?= e($title) ?></h1>

    <form method="post" action="<?= e($action) ?>" class="form" novalidate>
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

        <?= $partial('field', ['name' => 'name', 'label' => 'Ragione sociale', 'maxlength' => 150]) ?>
        <?= $partial('select', [
            'name' => 'round_id',
            'label' => 'Giro di consegna',
            'options' => $roundOptions,
            'blank' => 'Non assegnato',
        ]) ?>
        <?= $partial('field', ['name' => 'tax_code', 'label' => 'Partita IVA / codice fiscale', 'maxlength' => 30, 'required' => false]) ?>
        <?= $partial('field', ['name' => 'address', 'label' => 'Indirizzo di consegna', 'maxlength' => 255, 'required' => false]) ?>
        <div class="form__row">
            <?= $partial('field', ['name' => 'contact_email', 'label' => 'E-mail', 'maxlength' => 255, 'required' => false]) ?>
            <?= $partial('field', ['name' => 'phone', 'label' => 'Telefono', 'maxlength' => 30, 'required' => false]) ?>
        </div>

        <div class="form__actions">
            <button type="submit" class="button">Salva</button>
            <a href="<?= e($path) ?>">Annulla</a>
        </div>
    </form>
</div>
