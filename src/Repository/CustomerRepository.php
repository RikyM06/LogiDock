<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Model\Customer;

/**
 * @extends MasterDataRepository<Customer>
 */
final class CustomerRepository extends MasterDataRepository
{
    protected function table(): string
    {
        return 'customers';
    }

    protected function columns(): array
    {
        return ['name', 'tax_code', 'address', 'contact_email', 'phone', 'round_id'];
    }

    protected function searchColumns(): array
    {
        return ['t.name', 't.tax_code', 't.address', 'r.name'];
    }

    protected function extraSelect(): string
    {
        return ', r.name AS round_name';
    }

    protected function joins(): string
    {
        return 'LEFT JOIN rounds r ON r.id = t.round_id';
    }

    protected function hydrate(array $row): Customer
    {
        return Customer::fromRow($row);
    }
}
