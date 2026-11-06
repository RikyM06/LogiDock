<?php

declare(strict_types=1);

namespace LogiDock\Service;

use LogiDock\Core\Validator;
use LogiDock\Model\Carrier;

/**
 * @extends MasterDataService<Carrier>
 */
final class CarrierService extends MasterDataService
{
    protected function validate(array $input, ?object $current): array
    {
        $v = new Validator($input);

        $values = [
            'name' => $v->requiredString('name', 'Nome o ragione sociale', 150),
            'tax_code' => $v->optionalString('tax_code', 'Partita IVA / codice fiscale', 30),
            'phone' => $v->optionalString('phone', 'Telefono', 30),
            // DECIMAL(10,2): al massimo 8 cifre intere e 2 decimali.
            'trip_fee' => $v->requiredDecimal('trip_fee', 'Compenso per viaggio (€)', 8, 2),
        ];

        $v->assertValid();

        return $values;
    }
}
