<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Descrive un'impostazione: come si chiama, come si mostra, che valori accetta e quale valore
 * vale finché l'admin non ne salva uno diverso. Il valore corrente sta nel database (tabella settings).
 */
final readonly class SettingDefinition
{
    public const TYPE_INT = 'int';
    public const TYPE_TEXT = 'text';

    /**
     * @param string $type    TYPE_INT oppure TYPE_TEXT
     * @param int    $min     per i numeri il minimo ammesso, per il testo non conta
     * @param int    $max     per i numeri il massimo ammesso, per il testo la lunghezza massima
     */
    public function __construct(
        public string $name,
        public string $group,
        public string $label,
        public string $type,
        public string $default,
        public int $min,
        public int $max,
        public ?string $hint = null
    ) {
    }
}
