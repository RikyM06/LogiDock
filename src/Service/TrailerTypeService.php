<?php

declare(strict_types=1);

namespace LogiDock\Service;

use LogiDock\Core\Validator;
use LogiDock\Model\TrailerType;

/**
 * @extends MasterDataService<TrailerType>
 */
final class TrailerTypeService extends MasterDataService
{
    protected function validate(array $input, ?object $current): array
    {
        $v = new Validator($input);

        $values = [
            'name' => $v->requiredString('name', 'Nome', 100),
            'length_mm' => $v->requiredInt('length_mm', 'Lunghezza (mm)', 100, 30000),
            'width_mm' => $v->requiredInt('width_mm', 'Larghezza (mm)', 100, 5000),
            'inner_height_mm' => $v->requiredInt('inner_height_mm', 'Altezza interna (mm)', 100, 5000),
            'max_weight_kg' => $v->requiredDecimal('max_weight_kg', 'Portata massima (kg)', 8, 2, false),
        ];

        $v->assertValid();

        return $values;
    }

    protected function uniqueField(): string
    {
        return 'name';
    }

    protected function duplicateMessage(): string
    {
        return 'Esiste già un tipo di rimorchio con questo nome.';
    }
}
