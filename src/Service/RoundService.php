<?php

declare(strict_types=1);

namespace LogiDock\Service;

use LogiDock\Core\Validator;
use LogiDock\Model\Round;

/**
 * @extends MasterDataService<Round>
 */
final class RoundService extends MasterDataService
{
    protected function validate(array $input, ?object $current): array
    {
        $v = new Validator($input);

        $values = [
            'name' => $v->requiredString('name', 'Nome del giro', 100),
            'description' => $v->optionalString('description', 'Descrizione', 255),
        ];

        $v->assertValid();

        return $values;
    }

    protected function uniqueField(): string
    {
        return 'name';
    }

    protected function duplicateMessage(): string
    {
        return 'Esiste già un giro con questo nome.';
    }
}
