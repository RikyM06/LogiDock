<?php
/**
 * @var int         $status
 * @var string      $message
 * @var string|null $details  stack trace completo, valorizzato solo con APP_DEBUG=true
 */
?>
<div class="error-page">
    <p class="error-page__code"><?= e($status) ?></p>
    <h1><?= e($message) ?></h1>
    <p><a href="/">Torna alla home</a></p>

    <?php if ($details !== null) : ?>
        <pre class="error-page__details"><?= e($details) ?></pre>
    <?php endif; ?>
</div>
