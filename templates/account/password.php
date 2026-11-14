<?php
/**
 * «La mia password»: l'utente collegato cambia la propria password.
 *
 * @var string   $csrfToken
 * @var callable $partial
 */
?>
<?= $partial('breadcrumb', ['crumbs' => [['La mia password']]]) ?>
<div class="card card--form">
    <h1>La mia password</h1>
    <p class="muted">Per sicurezza serve la password attuale. Le tue sessioni aperte su altri dispositivi si chiuderanno.</p>

    <form method="post" action="/account/password" class="form" novalidate>
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

        <?= $partial('field', ['name' => 'current_password', 'label' => 'Password attuale', 'type' => 'password',
            'autocomplete' => 'current-password']) ?>
        <div class="form__row">
            <?= $partial('field', ['name' => 'password', 'label' => 'Nuova password', 'type' => 'password',
                'autocomplete' => 'new-password', 'hint' => 'Almeno 12 caratteri, diversa da quella attuale.']) ?>
            <?= $partial('field', ['name' => 'password_confirmation', 'label' => 'Ripeti la nuova password', 'type' => 'password',
                'autocomplete' => 'new-password']) ?>
        </div>

        <div class="form__actions">
            <button type="submit" class="button">Cambia password</button>
            <a href="/">Annulla</a>
        </div>
    </form>
</div>
