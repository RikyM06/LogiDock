<?php
/**
 * Un menu a tendina del form.
 *
 * @var string                $name
 * @var string                $label
 * @var array<string, string> $options   valore => testo da mostrare
 * @var string                $blank     testo della voce vuota ("Nessuno", "Scegli...")
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var bool|null             $required  (facoltativo, default no)
 */
$selected = $values[$name] ?? '';
$hasError = isset($errors[$name]);
?>
<div class="form__field">
    <label for="<?= e($name) ?>"><?= e($label) ?><?php if ($required ?? false) : ?> <span class="required-mark" aria-hidden="true">*</span><?php endif; ?></label>
    <select id="<?= e($name) ?>" name="<?= e($name) ?>" <?= ($required ?? false) ? 'required' : '' ?>
            <?= $hasError ? 'class="has-error" aria-invalid="true" aria-describedby="' . e($name) . '-error"' : '' ?>>
        <option value=""><?= e($blank) ?></option>
        <?php foreach ($options as $value => $text) : ?>
            <option value="<?= e((string) $value) ?>"<?= $selected === (string) $value ? ' selected' : '' ?>><?= e($text) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($hasError) : ?>
        <span class="form__error" id="<?= e($name) ?>-error"><?= e($errors[$name]) ?></span>
    <?php endif; ?>
</div>
