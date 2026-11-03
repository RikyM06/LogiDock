<?php

declare(strict_types=1);

namespace LogiDock\Core;

use LogicException;

/**
 * Container minimale: registri una factory per classe e ottieni sempre la stessa
 * istanza. Niente autowiring: le dipendenze si leggono nel codice di Application.
 */
final class Container
{
    /** @var array<class-string, callable(self): object> */
    private array $factories = [];

    /** @var array<class-string, object> */
    private array $instances = [];

    /**
     * @template T of object
     * @param class-string<T>       $id
     * @param callable(self): T     $factory
     */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (!isset($this->instances[$id])) {
            if (!isset($this->factories[$id])) {
                throw new LogicException('Nessun servizio registrato per ' . $id);
            }

            $instance = ($this->factories[$id])($this);
            if (!$instance instanceof $id) {
                throw new LogicException('La factory di ' . $id . ' ha restituito un oggetto del tipo sbagliato.');
            }

            $this->instances[$id] = $instance;
        }

        /** @var T */
        return $this->instances[$id];
    }
}
