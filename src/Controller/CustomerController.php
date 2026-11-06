<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\Session;
use LogiDock\Core\View;
use LogiDock\Model\Customer;
use LogiDock\Model\Round;
use LogiDock\Service\AuthService;
use LogiDock\Service\CustomerService;
use LogiDock\Service\RoundService;

final class CustomerController extends MasterDataController
{
    public function __construct(
        View $view,
        Session $session,
        AuthService $auth,
        private readonly CustomerService $customers,
        private readonly RoundService $rounds
    ) {
        parent::__construct($view, $session, $auth);
    }

    protected function service(): CustomerService
    {
        return $this->customers;
    }

    protected function path(): string
    {
        return '/customers';
    }

    protected function labels(): array
    {
        return ['singular' => 'Cliente', 'plural' => 'Clienti', 'ending' => 'o'];
    }

    protected function fields(): array
    {
        return ['name', 'tax_code', 'address', 'contact_email', 'phone', 'round_id'];
    }

    protected function valuesOf(object $entity): array
    {
        assert($entity instanceof Customer);

        return [
            'name' => $entity->name,
            'tax_code' => (string) $entity->taxCode,
            'address' => (string) $entity->address,
            'contact_email' => (string) $entity->contactEmail,
            'phone' => (string) $entity->phone,
            'round_id' => (string) $entity->roundId,
        ];
    }

    protected function nameOf(object $entity): string
    {
        assert($entity instanceof Customer);

        return $entity->name;
    }

    protected function formExtras(?object $current): array
    {
        return [
            'roundOptions' => $this->options(
                $this->rounds,
                $current instanceof Customer ? $current->roundId : null,
                static fn (object $round): string => $round instanceof Round ? $round->name : ''
            ),
        ];
    }
}
