<?php
/**
 * Form condiviso da "Nuovo giro" e "Modifica giro".
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

        <?= $partial('field', ['name' => 'name', 'label' => 'Nome del giro', 'maxlength' => 100]) ?>
        <?= $partial('field', ['name' => 'description', 'label' => 'Descrizione (zone, giorni...)', 'maxlength' => 255, 'required' => false]) ?>

        <div class="form__actions">
            <button type="submit" class="button">Salva</button>
            <a href="<?= e($path) ?>">Annulla</a>
        </div>
    </form>
</div>
