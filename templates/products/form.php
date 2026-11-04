<?php
/**
 * Form condiviso da "Nuovo prodotto" e "Modifica prodotto".
 *
 * @var string                $title
 * @var string                $action  dove inviare il form
 * @var array<string, string> $values  valori dei campi
 * @var array<string, string> $errors  campo => messaggio
 * @var array<string, string> $units   sigla => nome delle unità di misura
 * @var string                $csrfToken
 */
?>
<div class="card card--form">
    <h1><?= e($title) ?></h1>

    <form method="post" action="<?= e($action) ?>" class="form" novalidate>
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

        <div class="form__field">
            <label for="code">Codice</label>
            <input type="text" id="code" name="code" value="<?= e($values['code']) ?>" maxlength="50" required
                   <?= isset($errors['code']) ? 'class="has-error" aria-invalid="true" aria-describedby="code-error"' : '' ?>>
            <?php if (isset($errors['code'])) : ?>
                <span class="form__error" id="code-error"><?= e($errors['code']) ?></span>
            <?php endif; ?>
        </div>

        <div class="form__field">
            <label for="description">Descrizione</label>
            <input type="text" id="description" name="description" value="<?= e($values['description']) ?>"
                   maxlength="255" required
                   <?= isset($errors['description']) ? 'class="has-error" aria-invalid="true" aria-describedby="description-error"' : '' ?>>
            <?php if (isset($errors['description'])) : ?>
                <span class="form__error" id="description-error"><?= e($errors['description']) ?></span>
            <?php endif; ?>
        </div>

        <div class="form__row">
            <div class="form__field">
                <label for="unit_of_measure">Unità di misura</label>
                <select id="unit_of_measure" name="unit_of_measure" required
                        <?= isset($errors['unit_of_measure']) ? 'class="has-error" aria-invalid="true" aria-describedby="unit-error"' : '' ?>>
                    <option value="">Scegli...</option>
                    <?php foreach ($units as $abbreviation => $name) : ?>
                        <option value="<?= e($abbreviation) ?>"<?= $values['unit_of_measure'] === $abbreviation ? ' selected' : '' ?>>
                            <?= e($name) ?> (<?= e($abbreviation) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['unit_of_measure'])) : ?>
                    <span class="form__error" id="unit-error"><?= e($errors['unit_of_measure']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form__field">
                <label for="unit_weight_kg">Peso unitario (kg)</label>
                <input type="text" id="unit_weight_kg" name="unit_weight_kg" inputmode="decimal"
                       value="<?= e(str_replace('.', ',', $values['unit_weight_kg'])) ?>" required
                       <?= isset($errors['unit_weight_kg']) ? 'class="has-error" aria-invalid="true" aria-describedby="weight-error"' : '' ?>>
                <?php if (isset($errors['unit_weight_kg'])) : ?>
                    <span class="form__error" id="weight-error"><?= e($errors['unit_weight_kg']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="form__actions">
            <button type="submit" class="button">Salva</button>
            <a href="/products">Annulla</a>
        </div>
    </form>
</div>
