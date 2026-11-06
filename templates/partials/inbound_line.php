<?php
/**
 * Una riga (un bancale) del form di arrivo. Serve sia per le righe già presenti sia per il
 * modello <template> da cui il JavaScript crea quelle nuove (con l'indice "__INDEX__").
 *
 * @var int|string                 $index
 * @var array<string, string>      $line        valori della riga
 * @var array<string, string>      $rowErrors   campo => messaggio, solo per questa riga
 * @var array<string, string>      $productOptions
 * @var array<string, string>      $palletTypeOptions
 * @var array<string, string>      $locationOptions
 */

// Un menu a tendina della riga: il name è lines[<indice>][<campo>].
$select = static function (string $field, string $label, array $options, string $blank) use ($index, $line, $rowErrors): string {
    $html = '<select name="lines[' . e((string) $index) . '][' . $field . ']" aria-label="' . e($label) . '"'
        . (isset($rowErrors[$field]) ? ' class="has-error" aria-invalid="true"' : '') . '>';
    $html .= '<option value="">' . e($blank) . '</option>';
    foreach ($options as $value => $text) {
        $html .= '<option value="' . e((string) $value) . '"' . (($line[$field] ?? '') === (string) $value ? ' selected' : '') . '>'
            . e($text) . '</option>';
    }

    return $html . '</select>';
};

// Un campo di testo della riga.
$input = static function (string $field, string $label, string $type = 'text', string $inputmode = '') use ($index, $line, $rowErrors): string {
    $value = $line[$field] ?? '';
    if ($inputmode === 'decimal') {
        $value = str_replace('.', ',', $value);
    }

    return '<input type="' . $type . '" name="lines[' . e((string) $index) . '][' . $field . ']" value="' . e($value) . '"'
        . ' aria-label="' . e($label) . '"'
        . ($inputmode !== '' ? ' inputmode="' . $inputmode . '"' : '')
        . (isset($rowErrors[$field]) ? ' class="has-error" aria-invalid="true"' : '') . '>';
};

// Messaggio di errore sotto la cella.
$error = static fn (string $field): string => isset($rowErrors[$field])
    ? '<span class="form__error">' . e($rowErrors[$field]) . '</span>'
    : '';
?>
<tr>
    <td><?= $select('product_id', 'Prodotto', $productOptions, 'Scegli...') ?><?= $error('product_id') ?></td>
    <td><?= $select('pallet_type_id', 'Tipo di bancale', $palletTypeOptions, 'Scegli...') ?><?= $error('pallet_type_id') ?></td>
    <td><?= $input('quantity', 'Quantità', 'text', 'decimal') ?><?= $error('quantity') ?></td>
    <td><?= $input('unit_price', 'Prezzo unitario in euro', 'text', 'decimal') ?><?= $error('unit_price') ?></td>
    <td><?= $input('recorded_weight_kg', 'Peso segnato in kg', 'text', 'decimal') ?><?= $error('recorded_weight_kg') ?></td>
    <td><?= $input('lot_number', 'Lotto') ?><?= $error('lot_number') ?></td>
    <td><?= $input('expiry_date', 'Scadenza', 'date') ?><?= $error('expiry_date') ?></td>
    <td><?= $select('location_id', 'Posizione', $locationOptions, 'Scegli...') ?><?= $error('location_id') ?></td>
    <td><button type="button" class="button button--small button--secondary" data-remove-line aria-label="Togli la riga">×</button></td>
</tr>
