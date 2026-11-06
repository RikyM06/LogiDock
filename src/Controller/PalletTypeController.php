<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\Session;
use LogiDock\Core\View;
use LogiDock\Model\PalletType;
use LogiDock\Service\AuthService;
use LogiDock\Service\PalletTypeService;

final class PalletTypeController extends MasterDataController
{
    public function __construct(
        View $view,
        Session $session,
        AuthService $auth,
        private readonly PalletTypeService $palletTypes
    ) {
        parent::__construct($view, $session, $auth);
    }

    protected function service(): PalletTypeService
    {
        return $this->palletTypes;
    }

    protected function path(): string
    {
        return '/pallet-types';
    }

    protected function labels(): array
    {
        return ['singular' => 'Tipo di bancale', 'plural' => 'Tipi di bancale', 'ending' => 'o'];
    }

    protected function fields(): array
    {
        return ['code', 'name', 'length_mm', 'width_mm', 'max_height_mm'];
    }

    protected function valuesOf(object $entity): array
    {
        assert($entity instanceof PalletType);

        return [
            'code' => $entity->code,
            'name' => $entity->name,
            'length_mm' => (string) $entity->lengthMm,
            'width_mm' => (string) $entity->widthMm,
            'max_height_mm' => (string) $entity->maxHeightMm,
        ];
    }

    protected function nameOf(object $entity): string
    {
        assert($entity instanceof PalletType);

        return $entity->code;
    }
}
