<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\Session;
use LogiDock\Core\View;
use LogiDock\Model\Carrier;
use LogiDock\Service\AuthService;
use LogiDock\Service\CarrierService;

/**
 * I trasportatori hanno il compenso a viaggio: tutte le loro rotte sono riservate agli admin.
 */
final class CarrierController extends MasterDataController
{
    public function __construct(
        View $view,
        Session $session,
        AuthService $auth,
        private readonly CarrierService $carriers
    ) {
        parent::__construct($view, $session, $auth);
    }

    protected function service(): CarrierService
    {
        return $this->carriers;
    }

    protected function path(): string
    {
        return '/carriers';
    }

    protected function labels(): array
    {
        return ['singular' => 'Trasportatore', 'plural' => 'Trasportatori', 'ending' => 'e'];
    }

    protected function fields(): array
    {
        return ['name', 'tax_code', 'phone', 'trip_fee'];
    }

    protected function valuesOf(object $entity): array
    {
        assert($entity instanceof Carrier);

        return [
            'name' => $entity->name,
            'tax_code' => (string) $entity->taxCode,
            'phone' => (string) $entity->phone,
            'trip_fee' => $entity->tripFee,
        ];
    }

    protected function nameOf(object $entity): string
    {
        assert($entity instanceof Carrier);

        return $entity->name;
    }
}
