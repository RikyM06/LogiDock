<?php
/**
 * @var string      $csrfToken
 * @var string      $email
 * @var string|null $error
 */
?>
<div class="card card--narrow">
    <h1>Accesso</h1>

    <?php if ($error !== null) : ?>
        <p class="alert alert--error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/login" class="form">
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

        <label class="form__field">
            <span>Email</span>
            <input type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
        </label>

        <label class="form__field">
            <span>Password</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>

        <button type="submit" class="button">Accedi</button>
    </form>
</div>
