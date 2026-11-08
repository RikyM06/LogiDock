<?php
/**
 * Form condiviso da "Nuovo utente" e "Modifica utente".
 *
 * @var string                $title
 * @var string                $action
 * @var string                $path
 * @var bool                  $isNew       nuovo utente (si chiedono e-mail e password)
 * @var bool                  $isSelf      l'admin sta modificando sé stesso (non può togliersi il ruolo)
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var array<string, string> $roleOptions
 * @var string                $csrfToken
 * @var callable              $partial
 */
?>
<div class="card card--form">
    <h1><?= e($title) ?></h1>

    <form method="post" action="<?= e($action) ?>" class="form" novalidate>
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

        <?php if ($isNew) : ?>
            <?= $partial('field', ['name' => 'email', 'label' => 'E-mail', 'maxlength' => 255, 'autocomplete' => 'off',
                'hint' => 'È il nome con cui la persona entra. Si salva in minuscolo e non si potrà cambiare.']) ?>
        <?php else : ?>
            <div class="form__field">
                <span>E-mail</span>
                <span class="form__static"><?= e($values['email']) ?></span>
                <span class="form__hint">L'e-mail è il nome con cui si entra: non si cambia.</span>
            </div>
        <?php endif; ?>

        <?= $partial('field', ['name' => 'name', 'label' => 'Nome e cognome', 'maxlength' => 120]) ?>
        <?= $partial('select', [
            'name' => 'role',
            'label' => 'Ruolo',
            'options' => $roleOptions,
            'blank' => 'Scegli...',
            'required' => true,
        ]) ?>
        <?php if ($isSelf) : ?>
            <p class="muted">Stai modificando il tuo utente: non puoi toglierti il ruolo di admin da solo.</p>
        <?php endif; ?>

        <?php if ($isNew) : ?>
            <div class="form__row">
                <?= $partial('field', ['name' => 'password', 'label' => 'Password iniziale', 'type' => 'password',
                    'autocomplete' => 'new-password', 'hint' => 'Almeno 12 caratteri. Comunicala alla persona di persona, non per e-mail.']) ?>
                <?= $partial('field', ['name' => 'password_confirmation', 'label' => 'Ripeti la password', 'type' => 'password',
                    'autocomplete' => 'new-password']) ?>
            </div>
        <?php endif; ?>

        <div class="form__actions">
            <button type="submit" class="button">Salva</button>
            <a href="<?= e($path) ?>">Annulla</a>
        </div>
    </form>
</div>
