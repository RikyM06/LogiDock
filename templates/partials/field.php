<?php
/**
 * Un campo di testo del form, con etichetta e messaggio di errore.
 *
 * @var string                $name       nome del campo (anche chiave in $values e $errors)
 * @var string                $label
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var int|null              $maxlength  (facoltativo)
 * @var bool|null             $required   (facoltativo, default sì) campo obbligatorio: asterisco rosso
 *                                        accanto all'etichetta e attributo required (letto dai lettori di schermo)
 * @var string|null           $inputmode  (facoltativo, es. "decimal" o "numeric")
 * @var bool|null             $comma      (facoltativo) mostra il punto decimale come virgola
 * @var string|null           $hint       (facoltativo) testo di aiuto sotto il campo
 * @var string|null           $type       (facoltativo) "text" (default), "date" oppure "password"
 * @var string|null           $autocomplete (facoltativo) es. "new-password" o "current-password", per i
 *                                        gestori di password del browser
 */
$value = $values[$name] ?? '';
if (!empty($comma)) {
    $value = str_replace('.', ',', $value);
}
$hasError = isset($errors[$name]);
?>
<div class="form__field">
    <label for="<?= e($name) ?>"><?= e($label) ?><?php if ($required ?? true) : ?> <span class="required-mark" aria-hidden="true">*</span><?php endif; ?></label>
    <input type="<?= e($type ?? 'text') ?>" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>"
           <?= isset($maxlength) ? 'maxlength="' . (int) $maxlength . '"' : '' ?>
           <?= ($required ?? true) ? 'required' : '' ?>
           <?= isset($inputmode) ? 'inputmode="' . e($inputmode) . '"' : '' ?>
           <?= isset($autocomplete) ? 'autocomplete="' . e($autocomplete) . '"' : '' ?>
           <?= $hasError ? 'class="has-error" aria-invalid="true" aria-describedby="' . e($name) . '-error"' : '' ?>>
    <?php if (isset($hint)) : ?>
        <span class="form__hint"><?= e($hint) ?></span>
    <?php endif; ?>
    <?php if ($hasError) : ?>
        <span class="form__error" id="<?= e($name) ?>-error"><?= e($errors[$name]) ?></span>
    <?php endif; ?>
</div>
