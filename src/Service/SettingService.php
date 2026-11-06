<?php

declare(strict_types=1);

namespace LogiDock\Service;

use LogiDock\Core\Database;
use LogiDock\Core\Validator;
use LogiDock\Model\SettingDefinition;
use LogiDock\Repository\SettingRepository;
use LogicException;

/**
 * Impostazioni globali che l'admin può cambiare dall'interfaccia: nessun valore di business
 * è scritto "a mano" nel resto del codice, si chiede sempre a questo service.
 *
 * Le definizioni (nome, limiti, valore di partenza) stanno qui, in un unico elenco: aggiungere
 * un'impostazione vuol dire aggiungere una riga a definitions(). Nel database finisce soltanto
 * il valore salvato dall'admin; finché non c'è, vale il valore di partenza.
 */
final class SettingService
{
    public const PALLET_MAX_HEIGHT_MM = 'pallet_max_height_mm';
    public const AGING_THRESHOLD_DAYS = 'aging_threshold_days';
    public const LOCATION_DEFAULT_CAPACITY = 'location_default_capacity';
    public const COMPANY_NAME = 'company_name';
    public const COMPANY_ADDRESS = 'company_address';
    public const COMPANY_VAT = 'company_vat';
    public const COMPANY_FOOTER = 'company_footer';

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly Database $database
    ) {
    }

    /**
     * @return list<SettingDefinition>
     */
    public function definitions(): array
    {
        $warehouse = 'Magazzino e bancali';
        $company = 'Dati dell\'azienda sui documenti';

        return [
            new SettingDefinition(
                self::PALLET_MAX_HEIGHT_MM,
                $warehouse,
                'Altezza massima di carico del bancale (mm)',
                SettingDefinition::TYPE_INT,
                '750',
                100,
                5000,
                'Vale per i tipi di bancale che non hanno un\'altezza propria.'
            ),
            new SettingDefinition(
                self::AGING_THRESHOLD_DAYS,
                $warehouse,
                'Giorni dopo i quali un bancale fermo viene segnalato',
                SettingDefinition::TYPE_INT,
                '2',
                1,
                365
            ),
            new SettingDefinition(
                self::LOCATION_DEFAULT_CAPACITY,
                $warehouse,
                'Capacità predefinita di una posizione (bancali)',
                SettingDefinition::TYPE_INT,
                '300',
                1,
                65535,
                'Vale per le posizioni che non hanno una capacità propria.'
            ),
            new SettingDefinition(self::COMPANY_NAME, $company, 'Ragione sociale', SettingDefinition::TYPE_TEXT, '', 0, 150),
            new SettingDefinition(self::COMPANY_ADDRESS, $company, 'Indirizzo', SettingDefinition::TYPE_TEXT, '', 0, 255),
            new SettingDefinition(self::COMPANY_VAT, $company, 'Partita IVA', SettingDefinition::TYPE_TEXT, '', 0, 30),
            new SettingDefinition(
                self::COMPANY_FOOTER,
                $company,
                'Testo a piè di pagina dei documenti',
                SettingDefinition::TYPE_TEXT,
                '',
                0,
                500
            ),
        ];
    }

    /**
     * Valore corrente: quello salvato dall'admin oppure, se manca, quello di partenza.
     */
    public function get(string $name): string
    {
        return $this->values()[$name] ?? throw new LogicException('Impostazione sconosciuta: ' . $name);
    }

    public function getInt(string $name): int
    {
        return (int) $this->get($name);
    }

    /**
     * Valori correnti di tutte le impostazioni, nome => valore.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        $saved = $this->settings->all();
        $values = [];

        foreach ($this->definitions() as $definition) {
            $values[$definition->name] = $saved[$definition->name] ?? $definition->default;
        }

        return $values;
    }

    /**
     * Controlla e salva tutte le impostazioni in una sola transazione: o si salvano tutte o nessuna.
     *
     * @param array<string, mixed> $input dati grezzi del form
     * @throws \LogiDock\Core\ValidationException
     */
    public function update(array $input, int $userId): void
    {
        $v = new Validator($input);
        $clean = [];

        foreach ($this->definitions() as $definition) {
            if ($definition->type === SettingDefinition::TYPE_INT) {
                $clean[$definition->name] = (string) $v->requiredInt(
                    $definition->name,
                    $definition->label,
                    $definition->min,
                    $definition->max
                );
            } else {
                $clean[$definition->name] = $v->optionalString($definition->name, $definition->label, $definition->max) ?? '';
            }
        }

        $v->assertValid();

        $this->database->transaction(function () use ($clean, $userId): void {
            foreach ($clean as $name => $value) {
                $this->settings->save($name, $value, $userId);
            }
        });
    }
}
