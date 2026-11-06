<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Model\WarehouseLocation;

/**
 * @extends MasterDataRepository<WarehouseLocation>
 */
final class WarehouseLocationRepository extends MasterDataRepository
{
    protected function table(): string
    {
        return 'warehouse_locations';
    }

    protected function columns(): array
    {
        return ['code', 'zone', 'description', 'capacity'];
    }

    protected function searchColumns(): array
    {
        return ['t.code', 't.zone', 't.description'];
    }

    protected function orderBy(): string
    {
        return 't.is_active DESC, t.code ASC';
    }

    protected function hydrate(array $row): WarehouseLocation
    {
        return WarehouseLocation::fromRow($row);
    }
}
