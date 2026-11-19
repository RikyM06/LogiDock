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
            'tax_code' => $v->requiredTaxCode('tax_code', 'Partita IVA / codice fiscale'),
            'address' => $v->requiredString('address', 'Indirizzo di consegna', 255),
            'contact_email' => $v->optionalEmail('contact_email', 'E-mail'),
            'phone' => $v->optionalPhone('phone', 'Telefono'),
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
