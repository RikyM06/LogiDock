<?php

declare(strict_types=1);

namespace LogiDock\Tests\Support;

use LogiDock\Model\PlanDemand;
use LogiDock\Model\PlanPallet;
use LogiDock\Model\PlanVehicle;

/**
 * Bancali, mezzi e richieste finti per i test del pianificatore: tutto in memoria, niente database.
 * Le misure sono quelle vere: EPAL 1 = 1200 x 800 mm, EPAL 3 = 1200 x 1000 mm, altezza 750 mm,
 * rimorchio da 7,2 m (7200 x 2450 x 2600 mm) e semirimorchio da 13,6 m (13600 x 2450 x 2700 mm).
 */
trait PlanFixtures
{
    private const EPAL1 = 2;
    private const EPAL3 = 1;

    private int $nextPalletId = 1;

    private function pallet(
        int $productId = 1,
        string $quantity = '120.000',
        string $weightKg = '500.000',
        int $typeId = self::EPAL1,
        int $heightMm = 750,
        ?int $id = null
    ): PlanPallet {
        $id ??= $this->nextPalletId++;

        return new PlanPallet(
            $id,
            sprintf('PAL-TEST-%06d', $id),
            $productId,
            'PROD-' . $productId,
            'Prodotto ' . $productId,
            'CT',
            $quantity,
            $weightKg,
            $typeId,
            $typeId === self::EPAL1 ? 'EPAL 1' : 'EPAL 3',
            1200,
            $typeId === self::EPAL1 ? 800 : 1000,
            $heightMm,
            false,
            '2026-11-01 08:00:00',
            null
        );
    }

    /**
     * @return list<PlanPallet>
     */
    private function pallets(int $count, int $typeId = self::EPAL1, string $weightKg = '500.000', int $productId = 1): array
    {
        $pallets = [];
        for ($i = 0; $i < $count; $i++) {
            $pallets[] = $this->pallet($productId, '120.000', $weightKg, $typeId);
        }

        return $pallets;
    }

    private function smallTruck(int $id = 1, int $maxPallets = 99, string $maxWeightKg = '6500.00'): PlanVehicle
    {
        return new PlanVehicle(
            $id,
            'AA00' . $id . 'AA',
            'Camion ' . $id,
            $maxPallets,
            $maxWeightKg,
            'Rimorchio 7,2 m',
            7200,
            2450,
            2600,
            '6500.00',
            null,
            null,
            null
        );
    }

    private function bigTruck(int $id = 2, int $maxPallets = 99): PlanVehicle
    {
        return new PlanVehicle(
            $id,
            'BB00' . $id . 'BB',
            'Bilico ' . $id,
            $maxPallets,
            '24000.00',
            'Semirimorchio 13,6 m',
            13600,
            2450,
            2700,
            '24000.00',
            7,
            'Trasporti di prova',
            '180.00'
        );
    }

    private function demand(
        int $lineId,
        string $quantity,
        int $productId = 1,
        ?int $roundId = 10,
        string $customerName = 'Cliente A',
        string $plannedQuantity = '0.000',
        ?int $orderId = null,
        ?int $customerId = null
    ): PlanDemand {
        $roundNames = [10 => 'Giro Nord', 20 => 'Giro Sud'];

        return new PlanDemand(
            $orderId ?? $lineId,
            sprintf('ORD-TEST-%06d', $orderId ?? $lineId),
            $lineId,
            // Stesso nome = stesso cliente, se il test non dice altro.
            $customerId ?? crc32($customerName),
            $customerName,
            $roundId,
            $roundId === null ? null : ($roundNames[$roundId] ?? 'Giro ' . $roundId),
            $productId,
            'PROD-' . $productId,
            'Prodotto ' . $productId,
            'CT',
            $quantity,
            $plannedQuantity
        );
    }
}
