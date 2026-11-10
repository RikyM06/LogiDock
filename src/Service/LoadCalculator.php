<?php

declare(strict_types=1);

namespace LogiDock\Service;

use InvalidArgumentException;
use LogiDock\Core\Thousandths;
use LogiDock\Model\LoadUsage;
use LogiDock\Model\PlanPallet;
use LogiDock\Model\PlanVehicle;

/**
 * Dice se un gruppo di bancali ci sta su un mezzo e quanto lo riempie. Solo calcoli: niente database.
 *
 * Il rimorchio è un rettangolo caricato a file, una dietro l'altra:
 * - i bancali dello stesso tipo si impilano (mai tipi diversi uno sull'altro) fino al numero di
 *   livelli delle impostazioni, e comunque senza superare l'altezza interna del rimorchio;
 * - ogni pila occupa un posto a terra; i posti si mettono in file larghe quanto il rimorchio;
 * - ogni fila consuma un pezzo della lunghezza del pianale. Tra le due rotazioni del bancale
 *   si sceglie quella che consuma meno lunghezza;
 * - ogni tipo di bancale ha le sue file.
 *
 * È una stima prudente: un carico vero, incastrato a mano, può starci anche meglio. Non controlla
 * il peso sopra un singolo bancale impilato (domanda ancora aperta con l'azienda).
 *
 * I tre limiti del mezzo: lunghezza del pianale, portata (la più bassa tra veicolo e rimorchio)
 * e numero massimo di bancali del veicolo.
 */
final class LoadCalculator
{
    public function __construct(private readonly int $maxStackLevels)
    {
        if ($maxStackLevels < 1) {
            throw new InvalidArgumentException('I livelli di impilamento devono essere almeno 1');
        }
    }

    /**
     * @param list<PlanPallet> $pallets
     */
    public function usage(PlanVehicle $vehicle, array $pallets): LoadUsage
    {
        $byType = [];
        $grams = 0;

        foreach ($pallets as $pallet) {
            $byType[$pallet->palletTypeId][] = $pallet;
            $grams += Thousandths::fromDecimal($pallet->weightKg);
        }

        $problem = null;
        $usedLengthMm = 0;

        foreach ($byType as $sameType) {
            // Stesso tipo = stesse misure: basta guardare il primo.
            $lengthMm = $this->lengthNeeded($vehicle, $sameType[0], count($sameType));

            if ($lengthMm === null) {
                $problem ??= 'Un bancale ' . $sameType[0]->palletTypeName . ' è troppo alto o troppo largo per il rimorchio';
                continue;
            }

            $usedLengthMm += $lengthMm;
        }

        $maxGrams = min(
            Thousandths::fromDecimal($vehicle->maxWeightKg),
            Thousandths::fromDecimal($vehicle->trailerMaxWeightKg)
        );

        $problem ??= match (true) {
            count($pallets) > $vehicle->maxPallets => 'Più bancali di quanti ne porta il mezzo',
            $usedLengthMm > $vehicle->lengthMm => 'Non c\'è abbastanza pianale',
            $grams > $maxGrams => 'Il carico pesa più della portata',
            default => null,
        };

        return new LoadUsage(
            count($pallets),
            $vehicle->maxPallets,
            $usedLengthMm,
            $vehicle->lengthMm,
            Thousandths::toDecimal($grams),
            Thousandths::toDecimal($maxGrams),
            $problem
        );
    }

    /**
     * Millimetri di pianale che servono a $count bancali dello stesso tipo; null se quel tipo
     * non entra proprio nel rimorchio (più alto del vano o più largo del pianale).
     */
    private function lengthNeeded(PlanVehicle $vehicle, PlanPallet $sample, int $count): ?int
    {
        // Quanti bancali uno sull'altro: il limite delle impostazioni, ma mai oltre il soffitto.
        $levels = min($this->maxStackLevels, intdiv($vehicle->innerHeightMm, $sample->heightMm));

        if ($levels < 1) {
            return null;
        }

        // Posti a terra = pile. intdiv(a + b - 1, b) è la divisione arrotondata per eccesso.
        $positions = intdiv($count + $levels - 1, $levels);
        $best = null;

        // Le due rotazioni: [lato messo di traverso, lato lungo il pianale].
        $rotations = [
            [$sample->widthMm, $sample->lengthMm],
            [$sample->lengthMm, $sample->widthMm],
        ];

        foreach ($rotations as [$acrossMm, $alongMm]) {
            $perRow = intdiv($vehicle->widthMm, $acrossMm);

            if ($perRow < 1) {
                continue;
            }

            $rows = intdiv($positions + $perRow - 1, $perRow);
            $lengthMm = $rows * $alongMm;
            $best = $best === null ? $lengthMm : min($best, $lengthMm);
        }

        return $best;
    }
}
