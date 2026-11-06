<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\Session;
use LogiDock\Core\View;
use LogiDock\Model\Supplier;
use LogiDock\Service\AuthService;
use LogiDock\Service\SupplierService;

final class SupplierController extends MasterDataController
{
    public function __construct(
        View $view,
        Session $session,
        AuthService $auth,
        private readonly SupplierService $suppliers
    ) {
        parent::__construct($view, $session, $auth);
    }

    protected function service(): SupplierService
    {
        return $this->suppliers;
    }

    protected function path(): string
    {
        return '/suppliers';
    }

    protected function labels(): array
    {
        return ['singular' => 'Fornitore', 'plural' => 'Fornitori', 'ending' => 'o'];
    }

    protected function fields(): array
    {
        return ['name', 'tax_code', 'address', 'contact_email', 'phone'];
    }

    protected function valuesOf(object $entity): array
    {
        assert($entity instanceof Supplier);

        return [
            'name' => $entity->name,
            'tax_code' => (string) $entity->taxCode,
            'address' => (string) $entity->address,
            'contact_email' => (string) $entity->contactEmail,
            'phone' => (string) $entity->phone,
        ];
    }

    protected function nameOf(object $entity): string
    {
        assert($entity instanceof Supplier);

        return $entity->name;
    }
}
