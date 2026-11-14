<?php
/**
 * Un admin imposta una nuova password a un altro utente.
 *
 * @var string                $title
 * @var \LogiDock\Model\User  $user
 * @var string                $csrfToken
 * @var callable              $partial
 */
?>
<?= $partial('breadcrumb', ['crumbs' => [['Utenti', '/users'], [$title]]]) ?>
<div class="card card--form">
    <h1><?= e($title) ?></h1>
    <p class="muted"><?= e($user->email) ?>. Dopo il salvataggio le sue sessioni aperte si chiudono: dovrà entrare con la nuova password.</p>

    <form method="post" action="/users/<?= e($user->id) ?>/password" class="form" novalidate>
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

        <div class="form__row">
            <?= $partial('field', ['name' => 'password', 'label' => 'Nuova password', 'type' => 'password',
                'autocomplete' => 'new-password', 'hint' => 'Almeno 12 caratteri.']) ?>
            <?= $partial('field', ['name' => 'password_confirmation', 'label' => 'Ripeti la password', 'type' => 'password',
                'autocomplete' => 'new-password']) ?>
        </div>

        <div class="form__actions">
            <button type="submit" class="button">Imposta la password</button>
            <a href="/users">Annulla</a>
        </div>
    </form>
</div>
