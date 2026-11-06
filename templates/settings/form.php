<?php
/**
 * @var array<string, list<\LogiDock\Model\SettingDefinition>> $groups   gruppo => impostazioni
 * @var array<string, string>                                 $values   nome => valore
 * @var array<string, string>                                 $errors   nome => messaggio
 * @var string                                                $csrfToken
 * @var callable                                              $partial
 */
?>
<div class="page-header">
    <h1>Impostazioni</h1>
</div>
<p class="lead">Questi valori valgono per tutta l'applicazione e si possono cambiare in ogni momento.</p>

<form method="post" action="/settings" class="form" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

    <?php foreach ($groups as $group => $definitions) : ?>
        <div class="card card--form">
            <h2><?= e($group) ?></h2>
            <?php foreach ($definitions as $definition) : ?>
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

    <div class="form__actions">
        <button type="submit" class="button">Salva impostazioni</button>
        <a href="/">Annulla</a>
    </div>
</form>
