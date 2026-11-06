<?php
/**
 * Celle "Stato" e "Azioni" di una riga di anagrafica: badge attivo/disattivato e, per gli admin,
 * i pulsanti Modifica e Disattiva/Riattiva.
 *
 * @var object $item       il record (serve id e isActive)
 * @var string $itemName   come chiamarlo nella conferma ("Disattivare «...»?")
 * @var string $path
 * @var bool   $canManage
 * @var string $csrfToken
 */
?>
<td>
    <span class="badge <?= $item->isActive ? 'badge--ok' : 'badge--off' ?>">
        <?= $item->isActive ? 'Attivo' : 'Disattivato' ?>
    </span>
</td>
<?php if ($canManage) : ?>
    <td class="table__actions">
        <a class="button button--small button--secondary" href="<?= e($path) ?>/<?= e($item->id) ?>/edit">Modifica</a>

        <?php if ($item->isActive) : ?>
            <form method="post" action="<?= e($path) ?>/<?= e($item->id) ?>/deactivate"
                  data-confirm="Disattivare «<?= e($itemName) ?>»?">
                <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                <button type="submit" class="button button--small button--danger">Disattiva</button>
            </form>
        <?php else : ?>
            <form method="post" action="<?= e($path) ?>/<?= e($item->id) ?>/activate">
                <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                <button type="submit" class="button button--small button--secondary">Riattiva</button>
            </form>
        <?php endif; ?>
    </td>
<?php endif; ?>
