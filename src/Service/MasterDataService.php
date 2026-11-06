<?php

declare(strict_types=1);

namespace LogiDock\Service;

use LogiDock\Core\ValidationException;
use LogiDock\Repository\DuplicateEntryException;
use LogiDock\Repository\MasterDataRepository;

/**
 * Parte comune dei service delle anagrafiche. Le sottoclassi scrivono solo le regole della
 * loro entità in validate(); creare, modificare, cercare e (dis)attivare è uguale per tutte.
 *
 * Non si cancella mai: un'anagrafica ha record collegati, quindi si disattiva.
 *
 * @template T of object
 */
abstract class MasterDataService
{
    /**
     * @param MasterDataRepository<T> $repository
     */
    public function __construct(protected readonly MasterDataRepository $repository)
    {
    }

    /**
     * Controlla e normalizza l'input. Restituisce colonna => valore, pronti per il repository.
     *
     * @param array<string, mixed> $input   dati grezzi del form
     * @param T|null               $current record che si sta modificando, null se nuovo
     * @return array<string, string|int|null>
     * @throws ValidationException
     */
    abstract protected function validate(array $input, ?object $current): array;

    /**
     * Campo con vincolo UNIQUE nel database (es. 'code'), o null se non ce ne sono.
     * Un duplicato diventa un errore su quel campo del form.
     */
    protected function uniqueField(): ?string
    {
        return null;
    }

    protected function duplicateMessage(): string
    {
        return 'Esiste già un elemento con questo valore.';
    }

    /**
     * @return list<T>
     */
    public function search(string $term = ''): array
    {
        return $this->repository->search(trim($term));
    }

    /**
     * @return T|null
     */
    public function find(int $id): ?object
    {
        return $this->repository->findById($id);
    }

    /**
     * Gli elementi attivi: servono per riempire i menu a tendina di altri form.
     *
     * @return list<T>
     */
    public function activeOnes(): array
    {
        return array_values(array_filter(
            $this->repository->search(''),
            static fn (object $item): bool => $item->isActive
        ));
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function create(array $input): int
    {
        $values = $this->validate($input, null);

        try {
            return $this->repository->insert($values);
        } catch (DuplicateEntryException) {
            // Lo decide il database: vale anche se due utenti salvano insieme lo stesso valore.
            throw $this->duplicateError();
        }
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function update(int $id, array $input): void
    {
        $values = $this->validate($input, $this->repository->findById($id));

        try {
            $this->repository->update($id, $values);
        } catch (DuplicateEntryException) {
            throw $this->duplicateError();
        }
    }

    public function deactivate(int $id): void
    {
        $this->repository->setActive($id, false);
    }

    public function activate(int $id): void
    {
        $this->repository->setActive($id, true);
    }

    /**
     * Id ammessi per un riferimento (es. il giro di un cliente): gli elementi attivi, più quello
     * già assegnato al record che si sta modificando. Così un valore disattivato dopo l'assegnazione
     * non sparisce in silenzio dal form, ma non si può assegnare a un record nuovo.
     *
     * @param list<object> $activeItems
     * @return list<int>
     */
    protected function allowedIds(array $activeItems, ?int $alreadyAssigned): array
    {
        $ids = array_map(static fn (object $item): int => $item->id, $activeItems);

        if ($alreadyAssigned !== null) {
            $ids[] = $alreadyAssigned;
        }

        return $ids;
    }

    private function duplicateError(): ValidationException
    {
        return new ValidationException([($this->uniqueField() ?? 'name') => $this->duplicateMessage()]);
    }
}
