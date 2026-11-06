<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Model\Round;

/**
 * @extends MasterDataRepository<Round>
 */
final class RoundRepository extends MasterDataRepository
{
    protected function table(): string
    {
        return 'rounds';
    }

    protected function columns(): array
    {
        return ['name', 'description'];
    }

    protected function searchColumns(): array
    {
        return ['t.name', 't.description'];
    }

    protected function hydrate(array $row): Round
    {
        return Round::fromRow($row);
    }
}
