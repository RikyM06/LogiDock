<?php
/**
 * @var list<\LogiDock\Model\User> $items
 * @var string                     $search
 * @var \LogiDock\Model\User       $me       l'admin collegato (non può disattivare sé stesso)
 * @var string                     $path
 * @var string                     $csrfToken
 * @var callable                   $partial
 */
$lastLogin = static function (?string $utc): string {
    if ($utc === null) {
        return 'mai';
    }
    // Nel database le date sono in UTC: si mostrano nel fuso dell'applicazione.
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->format('d/m/Y H:i');
};
?>
<?= $partial('list_header') ?>

<?php if ($items === []) : ?>
    <p class="card"><?= $search !== '' ? 'Nessun utente corrisponde alla ricerca.' : 'Non ci sono utenti.' ?></p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>E-mail</th>
                    <th>Ruolo</th>
                    <th>Ultimo accesso</th>
                    <th>Stato</th>
                    <th><span class="visually-hidden">Azioni</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) : ?>
                    <?php $isMe = $item->id === $me->id; ?>
                    <tr<?= $item->isActive ? '' : ' class="is-inactive"' ?>>
                        <td><strong><?= e($item->name) ?></strong><?= $isMe ? ' <span class="badge badge--open">tu</span>' : '' ?></td>
                        <td><?= e($item->email) ?></td>
                        <td><?= $item->isAdmin() ? 'Admin' : 'Operatore' ?></td>
                        <td class="nowrap"><?= e($lastLogin($item->lastLoginAt)) ?></td>
                        <td>
                            <span class="badge <?= $item->isActive ? 'badge--ok' : 'badge--off' ?>">
                                <?= $item->isActive ? 'Attivo' : 'Disattivato' ?>
                            </span>
                        </td>
                        <td class="table__actions">
                            <a class="button button--small button--secondary" href="<?= e($path) ?>/<?= e($item->id) ?>/edit">Modifica</a>
                            <?php if (!$isMe) : ?>
                                <a class="button button--small button--secondary" href="<?= e($path) ?>/<?= e($item->id) ?>/password">Password</a>
                                <?php if ($item->isActive) : ?>
                                    <form method="post" action="<?= e($path) ?>/<?= e($item->id) ?>/deactivate"
                                          data-confirm="Disattivare <?= e($item->email) ?>? Non potrà più entrare.">
                                        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                        <button type="submit" class="button button--small button--danger">Disattiva</button>
                                    </form>
                                <?php else : ?>
                                    <form method="post" action="<?= e($path) ?>/<?= e($item->id) ?>/activate">
                                        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                        <button type="submit" class="button button--small button--secondary">Riattiva</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">Gli utenti non si cancellano: sono collegati ad arrivi, richieste e movimenti. Si disattivano.
        La tua password si cambia da «La mia password», in alto.</p>
<?php endif; ?>
