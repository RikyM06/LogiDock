<?php

declare(strict_types=1);

namespace LogiDock\Service;

use LogiDock\Core\Validator;
use LogiDock\Model\Supplier;

/**
 * Nella scheda del fornitore tutti i campi sono obbligatori (richiesta del titolare).
 * Nel database le colonne restano facoltative perché i fornitori registrati prima di questa regola
 * possono non averle: alla prima modifica il form chiede di completarle.
 *
 * @extends MasterDataService<Supplier>
 */
final class SupplierService extends MasterDataService
{
    protected function validate(array $input, ?object $current): array
    {
        $v = new Validator($input);

        $values = [
            'name' => $v->requiredString('name', 'Ragione sociale', 150),
            'tax_code' => $v->requiredString('tax_code', 'Partita IVA / codice fiscale', 30),
            'address' => $v->requiredString('address', 'Indirizzo', 255),
            'contact_email' => $v->requiredEmail('contact_email', 'E-mail'),
            'phone' => $v->requiredString('phone', 'Telefono', 30),
        ];

        $v->assertValid();

        return $values;
    }
}
