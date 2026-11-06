<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Model\TrailerType;

/**
 * @extends MasterDataRepository<TrailerType>
 */
final class TrailerTypeRepository extends MasterDataRepository
{
    protected function table(): string
    {
        return 'trailer_types';
    }

    protected function columns(): array
    {
        return ['name', 'length_mm', 'width_mm', 'inner_height_mm', 'max_weight_kg'];
    }

    protected function searchColumns(): array
    {
        return ['t.name'];
    }

    protected function hydrate(array $row): TrailerType
    {
        return TrailerType::fromRow($row);
    }
}
