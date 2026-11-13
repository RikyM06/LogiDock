<?php
/**
 * Scheda Documenti: i documenti emessi. Il prospetto del compenso compare solo per l'admin.
 *
 * @var list<\LogiDock\Model\History\DocumentHistoryRow> $documents
 */
?>
<?php if ($documents === []) : ?>
    <p class="card"><?= $filtered ? 'Nessun documento corrisponde ai filtri.' : 'Non ci sono ancora documenti emessi.' ?></p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Documento</th>
                    <th>Tipo</th>
                    <th>Viaggio</th>
                    <th>Cliente</th>
                    <th>Emesso il</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($documents as $document) : ?>
                    <tr>
                        <td class="nowrap"><a href="/documents/<?= e($document->id) ?>"><strong><?= e($document->code()) ?></strong></a></td>
                        <td><?= e($document->typeLabel()) ?></td>
                        <td class="nowrap"><a href="/trips/<?= e($document->tripId) ?>"><?= e($document->tripCode) ?></a></td>
                        <td><?= $document->customerName === null ? '<span class="muted">-</span>' : e($document->customerName) ?></td>
                        <td class="nowrap"><?= e($local($document->issuedAt, 'd/m/Y H:i')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($documents) >= $listLimit) : ?>
        <p class="muted">Mostrati i primi <?= e($listLimit) ?> documenti: restringi il periodo per vedere gli altri.</p>
    <?php endif; ?>
<?php endif; ?>
