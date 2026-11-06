<?php

declare(strict_types=1);

namespace LogiDock\Service;

use LogiDock\Core\Validator;
use LogiDock\Model\Vehicle;
use LogiDock\Repository\VehicleRepository;

/**
 * @extends MasterDataService<Vehicle>
 */
final class VehicleService extends MasterDataService
{
    public function __construct(
        VehicleRepository $repository,
        private readonly TrailerTypeService $trailerTypes,
        private readonly CarrierService $carriers
    ) {
        parent::__construct($repository);
    }

    protected function validate(array $input, ?object $current): array
    {
        $v = new Validator($input);
        $currentTrailerTypeId = $current instanceof Vehicle ? $current->trailerTypeId : null;
        $currentCarrierId = $current instanceof Vehicle ? $current->carrierId : null;

        $values = [
            'plate' => $v->requiredCode('plate', 'Targa', 15),
            'description' => $v->requiredString('description', 'Descrizione', 150),
            'max_weight_kg' => $v->requiredDecimal('max_weight_kg', 'Portata massima (kg)', 8, 2, false),
            'max_pallets' => $v->requiredInt('max_pallets', 'Bancali al massimo', 1, 1000),
            'trailer_type_id' => $v->optionalReference(
                'trailer_type_id',
                'Tipo di rimorchio',
                $this->allowedIds($this->trailerTypes->activeOnes(), $currentTrailerTypeId)
            ),
            'carrier_id' => $v->optionalReference(
                'carrier_id',
                'Trasportatore',
                $this->allowedIds($this->carriers->activeOnes(), $currentCarrierId)
            ),
        ];

        $v->assertValid();

        return $values;
    }

    protected function uniqueField(): string
    {
        return 'plate';
    }

    protected function duplicateMessage(): string
    {
        return 'Esiste già un veicolo con questa targa.';
    }
}
