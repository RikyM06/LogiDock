<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Model\Supplier;

/**
 * @extends MasterDataRepository<Supplier>
 */
final class SupplierRepository extends MasterDataRepository
{
    protected function table(): string
    {
        return 'suppliers';
    }

    protected function columns(): array
    {
        return ['name', 'tax_code', 'address', 'contact_email', 'phone'];
    }

    protected function searchColumns(): array
    {
        return ['t.name', 't.tax_code', 't.address'];
    }

    protected function hydrate(array $row): Supplier
    {
        return Supplier::fromRow($row);
    }
}
