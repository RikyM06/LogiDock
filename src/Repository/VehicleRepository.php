<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Model\Vehicle;

/**
 * @extends MasterDataRepository<Vehicle>
 */
final class VehicleRepository extends MasterDataRepository
{
    protected function table(): string
    {
        return 'vehicles';
    }

    protected function columns(): array
    {
        return ['plate', 'description', 'max_weight_kg', 'max_pallets', 'trailer_type_id', 'carrier_id'];
    }

    protected function searchColumns(): array
    {
        return ['t.plate', 't.description', 'tt.name', 'c.name'];
    }

    protected function extraSelect(): string
    {
        return ', tt.name AS trailer_type_name, c.name AS carrier_name';
    }

    protected function joins(): string
    {
        return 'LEFT JOIN trailer_types tt ON tt.id = t.trailer_type_id LEFT JOIN carriers c ON c.id = t.carrier_id';
    }

    protected function orderBy(): string
    {
        return 't.is_active DESC, t.plate ASC';
    }

    protected function hydrate(array $row): Vehicle
    {
        return Vehicle::fromRow($row);
    }
}
