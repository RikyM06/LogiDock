<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * I dati dell'azienda che vanno nell'intestazione e nel piè di pagina dei documenti. Arrivano dalle
 * impostazioni (l'admin li cambia da /settings): nel codice non c'è scritto niente.
 */
final readonly class CompanyData
{
    public function __construct(
        public string $name,
        public string $address,
        public string $vat,
        public string $footer
    ) {
    }
}
