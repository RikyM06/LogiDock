<?php

declare(strict_types=1);

namespace LogiDock\Service;

use LogiDock\Core\Validator;
use LogiDock\Model\Customer;
use LogiDock\Repository\CustomerRepository;

/**
 * @extends MasterDataService<Customer>
 */
final class CustomerService extends MasterDataService
{
    public function __construct(CustomerRepository $repository, private readonly RoundService $rounds)
    {
        parent::__construct($repository);
    }

    protected function validate(array $input, ?object $current): array
    {
        $v = new Validator($input);
        $currentRoundId = $current instanceof Customer ? $current->roundId : null;

        $values = [
            'name' => $v->requiredString('name', 'Ragione sociale', 150),
            'tax_code' => $v->optionalString('tax_code', 'Partita IVA / codice fiscale', 30),
            'address' => $v->optionalString('address', 'Indirizzo', 255),
            'contact_email' => $v->optionalEmail('contact_email', 'E-mail'),
            'phone' => $v->optionalString('phone', 'Telefono', 30),
            'round_id' => $v->optionalReference(
                'round_id',
                'Giro',
                $this->allowedIds($this->rounds->activeOnes(), $currentRoundId)
            ),
        ];

        $v->assertValid();

        return $values;
    }
}
