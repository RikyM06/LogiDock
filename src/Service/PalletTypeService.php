<?php

declare(strict_types=1);

namespace LogiDock\Service;

use LogiDock\Core\Validator;
use LogiDock\Model\PalletType;

/**
 * @extends MasterDataService<PalletType>
 */
final class PalletTypeService extends MasterDataService
{
    protected function validate(array $input, ?object $current): array
    {
        $v = new Validator($input);

        $values = [
            'code' => $v->requiredCode('code', 'Codice', 30),
            'name' => $v->requiredString('name', 'Nome', 100),
            'length_mm' => $v->requiredInt('length_mm', 'Lunghezza (mm)', 100, 5000),
            'width_mm' => $v->requiredInt('width_mm', 'Larghezza (mm)', 100, 5000),
            // Vuota = vale l'altezza massima globale (impostazione pallet_max_height_mm).
            'max_height_mm' => $v->optionalInt('max_height_mm', 'Altezza massima (mm)', 100, 5000),
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
        return 'Esiste già un tipo di bancale con questo codice.';
    }
}
