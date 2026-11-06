<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\Session;
use LogiDock\Core\View;
use LogiDock\Model\WarehouseLocation;
use LogiDock\Service\AuthService;
use LogiDock\Service\SettingService;
use LogiDock\Service\WarehouseLocationService;

final class WarehouseLocationController extends MasterDataController
{
    public function __construct(
        View $view,
        Session $session,
        AuthService $auth,
        private readonly WarehouseLocationService $locations,
        private readonly SettingService $settings
    ) {
        parent::__construct($view, $session, $auth);
    }

    protected function service(): WarehouseLocationService
    {
        return $this->locations;
    }

    protected function path(): string
    {
        return '/locations';
    }

    protected function labels(): array
    {
        return ['singular' => 'Posizione', 'plural' => 'Posizioni di magazzino', 'ending' => 'a'];
    }

    protected function fields(): array
    {
        return ['code', 'zone', 'description', 'capacity'];
    }

    protected function valuesOf(object $entity): array
    {
        assert($entity instanceof WarehouseLocation);

        return [
            'code' => $entity->code,
            'zone' => (string) $entity->zone,
            'description' => (string) $entity->description,
            'capacity' => (string) $entity->capacity,
        ];
    }

    protected function nameOf(object $entity): string
    {
        assert($entity instanceof WarehouseLocation);

        return $entity->code;
    }

    protected function formExtras(?object $current): array
    {
        return ['defaultCapacity' => $this->settings->getInt(SettingService::LOCATION_DEFAULT_CAPACITY)];
    }
}
