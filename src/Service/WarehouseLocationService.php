<?php

declare(strict_types=1);

namespace LogiDock\Service;

use LogiDock\Core\Validator;
use LogiDock\Model\WarehouseLocation;

/**
 * @extends MasterDataService<WarehouseLocation>
 */
final class WarehouseLocationService extends MasterDataService
{
    protected function validate(array $input, ?object $current): array
    {
        $v = new Validator($input);

        $values = [
            'code' => $v->requiredCode('code', 'Codice', 30),
            'zone' => $v->optionalString('zone', 'Zona', 50),
            'description' => $v->optionalString('description', 'Descrizione', 255),
            // Vuota = vale la capacità predefinita (impostazione location_default_capacity).
            'capacity' => $v->optionalInt('capacity', 'Capacità (bancali)', 1, 65535),
        ];

        $v->assertValid();

        return $values;
    }

    protected function uniqueField(): string
    {
        return 'code';
    }

    protected function duplicateMessage(): string
    {
        return 'Esiste già una posizione con questo codice.';
    }
}
