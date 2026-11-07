<?php
/**
 * Una riga (un prodotto) del form di una richiesta. Serve sia per le righe già presenti sia per il
 * modello <template> da cui il JavaScript crea quelle nuove (con l'indice "__INDEX__").
 *
 * @var int|string            $index
 * @var array<string, string> $line       valori della riga
 * @var array<string, string> $rowErrors  campo => messaggio, solo per questa riga
 * @var array<string, string> $productOptions
 */
$name = static fn (string $field): string => 'lines[' . $index . '][' . $field . ']';
$errorClass = static fn (string $field): string => isset($rowErrors[$field]) ? ' class="has-error" aria-invalid="true"' : '';
$error = static fn (string $field): string => isset($rowErrors[$field])
    ? '<span class="form__error">' . e($rowErrors[$field]) . '</span>'
    : '';
$decimal = static fn (string $field): string => str_replace('.', ',', $line[$field] ?? '');
?>
<tr>
    <td>
        <select name="<?= e($name('product_id')) ?>" aria-label="Prodotto"<?= $errorClass('product_id') ?>>
            <option value="">Scegli...</option>
            <?php foreach ($productOptions as $value => $text) : ?>
                <option value="<?= e((string) $value) ?>"<?= ($line['product_id'] ?? '') === (string) $value ? ' selected' : '' ?>><?= e($text) ?></option>
            <?php endforeach; ?>
        </select>
        <?= $error('product_id') ?>
    </td>
    <td>
        <input type="text" name="<?= e($name('quantity')) ?>" value="<?= e($decimal('quantity')) ?>" inputmode="decimal"
               aria-label="Quantità"<?= $errorClass('quantity') ?>>
        <?= $error('quantity') ?>
    </td>
    <td>
        <input type="text" name="<?= e($name('unit_price')) ?>" value="<?= e($decimal('unit_price')) ?>" inputmode="decimal"
               aria-label="Prezzo di vendita in euro"<?= $errorClass('unit_price') ?>>
        <?= $error('unit_price') ?>
    </td>
    <td><button type="button" class="button button--small button--secondary" data-remove-line aria-label="Togli la riga">×</button></td>
</tr>
