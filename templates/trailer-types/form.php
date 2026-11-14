<?php
/**
 * Form condiviso da "Nuovo tipo di rimorchio" e "Modifica tipo di rimorchio".
 *
 * @var string   $title
 * @var string   $action
 * @var string   $path
 * @var string   $csrfToken
 * @var callable $partial
 */
?>
<?= $partial('breadcrumb', ['crumbs' => [['Anagrafiche', '/master-data'], [$labels['plural'], $path], [$title]]]) ?>
<div class="card card--form">
    <h1><?= e($title) ?></h1>

    <form method="post" action="<?= e($action) ?>" class="form" novalidate>
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

        <?= $partial('field', ['name' => 'name', 'label' => 'Nome', 'maxlength' => 100, 'hint' => 'Per esempio "Semirimorchio telonato 13,6 m".']) ?>
        <div class="form__row">
            <?= $partial('field', ['name' => 'length_mm', 'label' => 'Lunghezza del pianale (mm)', 'maxlength' => 5, 'inputmode' => 'numeric']) ?>
            <?= $partial('field', ['name' => 'width_mm', 'label' => 'Larghezza del pianale (mm)', 'maxlength' => 4, 'inputmode' => 'numeric']) ?>
        </div>
        <div class="form__row">
            <?= $partial('field', ['name' => 'inner_height_mm', 'label' => 'Altezza interna (mm)', 'maxlength' => 4, 'inputmode' => 'numeric']) ?>
            <?= $partial('field', ['name' => 'max_weight_kg', 'label' => 'Portata massima (kg)', 'maxlength' => 11, 'inputmode' => 'decimal', 'comma' => true]) ?>
        </div>

        <div class="form__actions">
            <button type="submit" class="button">Salva</button>
            <a href="<?= e($path) ?>">Annulla</a>
        </div>
    </form>
</div>
