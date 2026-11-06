<?php

declare(strict_types=1);

namespace LogiDock\Service;

use LogiDock\Core\Validator;
use LogiDock\Model\Supplier;

/**
 * @extends MasterDataService<Supplier>
 */
final class SupplierService extends MasterDataService
{
    protected function validate(array $input, ?object $current): array
    {
        $v = new Validator($input);

        $values = [
            'name' => $v->requiredString('name', 'Ragione sociale', 150),
            'tax_code' => $v->optionalString('tax_code', 'Partita IVA / codice fiscale', 30),
            'address' => $v->optionalString('address', 'Indirizzo', 255),
            'contact_email' => $v->optionalEmail('contact_email', 'E-mail'),
            'phone' => $v->optionalString('phone', 'Telefono', 30),
        ];

        $v->assertValid();

        return $values;
    }
}
