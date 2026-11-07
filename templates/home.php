<?php
/**
 * @var string $appName
 */
?>
<h1>Benvenuto in <?= e($appName) ?></h1>
<p class="lead">Le funzionalità arrivano una alla volta. Quelle già pronte sono qui sotto.</p>

<div class="card">
    <h2>Moduli</h2>
    <ul class="list">
        <li><a href="/inbound">Arrivi</a>: documento del fornitore e bancali, con prezzo, peso e posizione</li>
        <li><a href="/products">Prodotti</a></li>
        <li><a href="/master-data">Anagrafiche</a>: fornitori, clienti, giri, veicoli, rimorchi, posizioni, tipi di bancale</li>
        <li><a href="/orders">Richieste</a>: cosa chiede ogni cliente, con quantità, prezzi e data di consegna</li>
        <li>Pianificazione viaggi <span class="muted">(in arrivo)</span></li>
        <li>Bolle e DDT <span class="muted">(in arrivo)</span></li>
    </ul>
</div>
