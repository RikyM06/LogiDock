<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\Session;
use LogiDock\Core\View;
use LogiDock\Model\Carrier;
use LogiDock\Model\TrailerType;
use LogiDock\Model\Vehicle;
use LogiDock\Service\AuthService;
use LogiDock\Service\CarrierService;
use LogiDock\Service\TrailerTypeService;
use LogiDock\Service\VehicleService;

final class VehicleController extends MasterDataController
{
    public function __construct(
        View $view,
        Session $session,
        AuthService $auth,
        private readonly VehicleService $vehicles,
        private readonly TrailerTypeService $trailerTypes,
        private readonly CarrierService $carriers
    ) {
        parent::__construct($view, $session, $auth);
    }

    protected function service(): VehicleService
    {
        return $this->vehicles;
    }

    protected function path(): string
    {
        return '/vehicles';
    }

    protected function labels(): array
    {
        return ['singular' => 'Veicolo', 'plural' => 'Veicoli', 'ending' => 'o'];
    }

    protected function fields(): array
    {
        return ['plate', 'description', 'max_weight_kg', 'max_pallets', 'trailer_type_id', 'carrier_id'];
    }

    protected function valuesOf(object $entity): array
    {
        assert($entity instanceof Vehicle);

        return [
            'plate' => $entity->plate,
            'description' => $entity->description,
            'max_weight_kg' => $entity->maxWeightKg,
            'max_pallets' => (string) $entity->maxPallets,
            'trailer_type_id' => (string) $entity->trailerTypeId,
            'carrier_id' => (string) $entity->carrierId,
        ];
    }

    protected function nameOf(object $entity): string
    {
        assert($entity instanceof Vehicle);

        return $entity->plate;
    }

    protected function formExtras(?object $current): array
    {
        $vehicle = $current instanceof Vehicle ? $current : null;

        return [
            'trailerTypeOptions' => $this->options(
                $this->trailerTypes,
                $vehicle?->trailerTypeId,
                static fn (object $type): string => $type instanceof TrailerType ? $type->name : ''
            ),
            'carrierOptions' => $this->options(
                $this->carriers,
                $vehicle?->carrierId,
                static fn (object $carrier): string => $carrier instanceof Carrier ? $carrier->name : ''
            ),
        ];
    }
}
