<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Model\PalletType;

/**
 * @extends MasterDataRepository<PalletType>
 */
final class PalletTypeRepository extends MasterDataRepository
{
    protected function table(): string
    {
        return 'pallet_types';
    }

    protected function columns(): array
    {
        return ['code', 'name', 'length_mm', 'width_mm', 'max_height_mm'];
    }

    protected function searchColumns(): array
    {
        return ['t.code', 't.name'];
    }

    protected function orderBy(): string
    {
        return 't.is_active DESC, t.code ASC';
    }

    protected function hydrate(array $row): PalletType
    {
        return PalletType::fromRow($row);
    }
}
