<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\Session;
use LogiDock\Core\View;
use LogiDock\Model\TrailerType;
use LogiDock\Service\AuthService;
use LogiDock\Service\TrailerTypeService;

final class TrailerTypeController extends MasterDataController
{
    public function __construct(
        View $view,
        Session $session,
        AuthService $auth,
        private readonly TrailerTypeService $trailerTypes
    ) {
        parent::__construct($view, $session, $auth);
    }

    protected function service(): TrailerTypeService
    {
        return $this->trailerTypes;
    }

    protected function path(): string
    {
        return '/trailer-types';
    }

    protected function labels(): array
    {
        return ['singular' => 'Tipo di rimorchio', 'plural' => 'Tipi di rimorchio', 'ending' => 'o'];
    }

    protected function fields(): array
    {
        return ['name', 'length_mm', 'width_mm', 'inner_height_mm', 'max_weight_kg'];
    }

    protected function valuesOf(object $entity): array
    {
        assert($entity instanceof TrailerType);

        return [
            'name' => $entity->name,
            'length_mm' => (string) $entity->lengthMm,
            'width_mm' => (string) $entity->widthMm,
            'inner_height_mm' => (string) $entity->innerHeightMm,
            'max_weight_kg' => $entity->maxWeightKg,
        ];
    }

    protected function nameOf(object $entity): string
    {
        assert($entity instanceof TrailerType);

        return $entity->name;
    }
}
