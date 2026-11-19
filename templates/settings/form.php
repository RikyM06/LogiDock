<?php
/**
 * Impostazioni: un riquadro per gruppo; con JavaScript diventano schede (WAI-ARIA Tabs), senza restano uno sotto l'altro.
 *
 * @var list<array{id: string, label: string, definitions: list<\LogiDock\Model\SettingDefinition>}> $groups
 * @var array<string, string>                                                                         $values   nome => valore
 * @var array<string, string>                                                                         $errors   nome => messaggio
 * @var string                                                                                        $csrfToken
 * @var callable                                                                                      $partial
 */
// Una scheda con almeno un errore si segnala (testo per gli screen reader + puntino rosso) e si apre da sola.
$hasError = static function (array $group) use ($errors): bool {
    foreach ($group['definitions'] as $definition) {
        if (isset($errors[$definition->name])) {
            return true;
        }
    }

    return false;
};
?>
<?= $partial('breadcrumb', ['crumbs' => [['Impostazioni']]]) ?>
<div class="page-header">
    <h1>Impostazioni</h1>
</div>
<p class="lead">Questi valori valgono per tutta l'applicazione e si possono cambiare in ogni momento.</p>

<form method="post" action="/settings" class="form" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

    <div data-tabs>
        <?php /* Nascosto finché non parte il JavaScript: senza, i riquadri qui sotto restano tutti visibili. */ ?>
        <div class="tabs" role="tablist" aria-label="Gruppi di impostazioni" hidden>
            <?php foreach ($groups as $index => $group) : ?>
                <button type="button" class="tabs__link<?= $hasError($group) ? ' has-error' : '' ?>" role="tab"
                        id="tab-<?= e($group['id']) ?>" aria-controls="panel-<?= e($group['id']) ?>"
                        aria-selected="<?= $index === 0 ? 'true' : 'false' ?>" tabindex="<?= $index === 0 ? '0' : '-1' ?>">
                    <?= e($group['label']) ?>
                    <?php if ($hasError($group)) : ?>
                        <span class="visually-hidden">(contiene errori)</span><span class="tabs__dot" aria-hidden="true"></span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($groups as $group) : ?>
            <div class="card card--form" id="panel-<?= e($group['id']) ?>" data-tab-panel>
                <h2><?= e($group['label']) ?></h2>
                <?php foreach ($group['definitions'] as $definition) : ?>
                    <?php
                    $isInt = $definition->type === \LogiDock\Model\SettingDefinition::TYPE_INT;
                    echo $partial('field', [
                        'name' => $definition->name,
                        'label' => $definition->label,
                        'maxlength' => $isInt ? 5 : $definition->max,
                        'required' => $isInt,
                        'inputmode' => $isInt ? 'numeric' : null,
                        'hint' => $definition->hint,
                    ]);
                    ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="form__actions">
        <button type="submit" class="button">Salva impostazioni</button>
        <a href="/">Annulla</a>
    </div>
</form>
