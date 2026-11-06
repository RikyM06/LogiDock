<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\Session;
use LogiDock\Core\View;
use LogiDock\Model\Round;
use LogiDock\Service\AuthService;
use LogiDock\Service\RoundService;

final class RoundController extends MasterDataController
{
    public function __construct(
        View $view,
        Session $session,
        AuthService $auth,
        private readonly RoundService $rounds
    ) {
        parent::__construct($view, $session, $auth);
    }

    protected function service(): RoundService
    {
        return $this->rounds;
    }

    protected function path(): string
    {
        return '/rounds';
    }

    protected function labels(): array
    {
        return ['singular' => 'Giro', 'plural' => 'Giri', 'ending' => 'o'];
    }

    protected function fields(): array
    {
        return ['name', 'description'];
    }

    protected function valuesOf(object $entity): array
    {
        assert($entity instanceof Round);

        return ['name' => $entity->name, 'description' => (string) $entity->description];
    }

    protected function nameOf(object $entity): string
    {
        assert($entity instanceof Round);

        return $entity->name;
    }
}
