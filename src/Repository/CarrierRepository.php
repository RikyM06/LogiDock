<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Model\Carrier;

/**
 * @extends MasterDataRepository<Carrier>
 */
final class CarrierRepository extends MasterDataRepository
{
    protected function table(): string
    {
        return 'carriers';
    }

    protected function columns(): array
    {
        return ['name', 'tax_code', 'phone', 'trip_fee'];
    }

    protected function searchColumns(): array
    {
        return ['t.name', 't.tax_code'];
    }

    protected function hydrate(array $row): Carrier
    {
        return Carrier::fromRow($row);
    }
}
