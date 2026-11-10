<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use InvalidArgumentException;
use LogiDock\Model\PlanVehicle;
use LogiDock\Service\LoadCalculator;
use LogiDock\Tests\Support\PlanFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Il calcolo dello spazio sul mezzo con numeri veri, controllabili con carta e penna.
 * Rimorchio largo 2450 mm: di traverso ci stanno 3 EPAL 1 dal lato corto (800) o 2 dal lato lungo (1200).
 */
final class LoadCalculatorTest extends TestCase
{
    use PlanFixtures;

    public function testAnEmptyLoadFitsAndUsesNothing(): void
    {
        $usage = (new LoadCalculator(1))->usage($this->smallTruck(), []);

        self::assertTrue($usage->fits());
        self::assertSame(0, $usage->palletCount);
        self::assertSame(0, $usage->usedLengthMm);
        self::assertSame('0.000', $usage->weightKg);
    }

    public function testA72MetreTrailerTakes18Epal1OnOneLevel(): void
    {
        $calculator = new LoadCalculator(1);
        $truck = $this->smallTruck(maxWeightKg: '99999.00');
        // Il peso qui non conta: bancali leggeri.
        $fits = $calculator->usage($truck, $this->pallets(18, weightKg: '100.000'));
        $tooMany = $calculator->usage($truck, $this->pallets(19, weightKg: '100.000'));

        // 18 bancali = 6 file da 3 con il lato da 1200 lungo il pianale: 6 x 1200 = 7200 mm, tutto il pianale.
        self::assertTrue($fits->fits());
        self::assertSame(7200, $fits->usedLengthMm);
        self::assertSame(7200, $fits->availableLengthMm);

        self::assertFalse($tooMany->fits());
        self::assertSame('Non c\'è abbastanza pianale', $tooMany->problem);
    }

    public function testA72MetreTrailerTakes14Epal3OnOneLevel(): void
    {
        $calculator = new LoadCalculator(1);
        $truck = $this->smallTruck();

        // EPAL 3 (1200 x 1000): 2 per fila in entrambe le rotazioni; conviene il lato da 1000 lungo il pianale.
        $fits = $calculator->usage($truck, $this->pallets(14, self::EPAL3, '100.000'));

        self::assertTrue($fits->fits());
        self::assertSame(7000, $fits->usedLengthMm);
        self::assertFalse($calculator->usage($truck, $this->pallets(15, self::EPAL3, '100.000'))->fits());
    }

    public function testA136MetreSemitrailerTakes34Epal1OnOneLevel(): void
    {
        $calculator = new LoadCalculator(1);

        // 2 file affiancate di 17 bancali messi per il lungo: 17 x 800 = 13600 mm.
        $fits = $calculator->usage($this->bigTruck(), $this->pallets(34, weightKg: '100.000'));

        self::assertTrue($fits->fits());
        self::assertSame(13600, $fits->usedLengthMm);
        self::assertFalse($calculator->usage($this->bigTruck(), $this->pallets(35, weightKg: '100.000'))->fits());
    }

    public function testItPicksTheRotationThatUsesLessFloor(): void
    {
        // 4 EPAL 1 su un livello: 2 file da 2 per il lungo (2 x 800 = 1600) battono 2 file da 3 di traverso (2 x 1200 = 2400).
        $usage = (new LoadCalculator(1))->usage($this->smallTruck(), $this->pallets(4));

        self::assertSame(1600, $usage->usedLengthMm);
    }

    public function testStackingHalvesTheFloorPositions(): void
    {
        // 5 bancali su 2 livelli = 3 pile = una sola fila da 3 di traverso: 1200 mm.
        $usage = (new LoadCalculator(2))->usage($this->smallTruck(), $this->pallets(5));

        self::assertTrue($usage->fits());
        self::assertSame(1200, $usage->usedLengthMm);
    }

    public function testStackingNeverGoesAboveTheTrailerCeiling(): void
    {
        // Vano alto 1400 mm, bancali da 750: due livelli farebbero 1500, quindi se ne usa uno solo
        // anche se le impostazioni ne ammettono tre.
        $lowTrailer = new PlanVehicle(1, 'AA001AA', 'Furgone basso', 99, '6500.00', 'Basso', 7200, 2450, 1400, '6500.00', null, null, null);

        $stacked = (new LoadCalculator(3))->usage($lowTrailer, $this->pallets(6));
        $oneLevel = (new LoadCalculator(1))->usage($lowTrailer, $this->pallets(6));

        self::assertSame($oneLevel->usedLengthMm, $stacked->usedLengthMm);
        self::assertSame(2400, $stacked->usedLengthMm);
    }

    public function testThreeLevelsAreUsedWhenTheCeilingAllowsIt(): void
    {
        // Vano 2600 mm, bancali da 750: 3 livelli = 2250 mm. 9 bancali = 3 pile = 1 fila.
        $usage = (new LoadCalculator(3))->usage($this->smallTruck(), $this->pallets(9, weightKg: '100.000'));

        self::assertSame(1200, $usage->usedLengthMm);
    }

    public function testAPalletTallerThanTheLoadSpaceNeverFits(): void
    {
        $tall = $this->pallet(heightMm: 2700);

        $usage = (new LoadCalculator(2))->usage($this->smallTruck(), [$tall]);

        self::assertFalse($usage->fits());
        self::assertStringContainsString('troppo alto o troppo largo', (string) $usage->problem);
    }

    public function testAPalletWiderThanTheTrailerNeverFits(): void
    {
        $narrow = new PlanVehicle(1, 'AA001AA', 'Stretto', 99, '6500.00', 'Stretto', 7200, 700, 2600, '6500.00', null, null, null);

        self::assertFalse((new LoadCalculator(1))->usage($narrow, $this->pallets(1))->fits());
    }

    public function testDifferentPalletTypesGetTheirOwnRowsAndAreNeverStackedTogether(): void
    {
        // Un EPAL 1 (fila da 800) più un EPAL 3 (fila da 1000): 1800 mm, anche con 2 livelli ammessi.
        $load = [$this->pallet(typeId: self::EPAL1), $this->pallet(typeId: self::EPAL3)];

        $usage = (new LoadCalculator(2))->usage($this->smallTruck(), $load);

        self::assertSame(1800, $usage->usedLengthMm);
    }

    public function testTheWeightLimitIsTheLowerOfVehicleAndTrailer(): void
    {
        $calculator = new LoadCalculator(2);
        // Veicolo 5000 kg, rimorchio 6500 kg: vale 5000.
        $truck = $this->smallTruck(maxWeightKg: '5000.00');

        $exact = $calculator->usage($truck, $this->pallets(10, weightKg: '500.000'));
        $oneGramMore = $calculator->usage($truck, [...$this->pallets(9, weightKg: '500.000'), $this->pallet(weightKg: '500.001')]);

        self::assertTrue($exact->fits(), 'Il peso uguale alla portata è ammesso');
        self::assertSame('5000.000', $exact->weightKg);
        self::assertSame('5000.000', $exact->maxWeightKg);

        self::assertFalse($oneGramMore->fits());
        self::assertSame('5000.001', $oneGramMore->weightKg);
        self::assertSame('Il carico pesa più della portata', $oneGramMore->problem);
    }

    public function testTheVehiclePalletLimitStillCounts(): void
    {
        $calculator = new LoadCalculator(2);
        $truck = $this->smallTruck(maxPallets: 12);

        self::assertTrue($calculator->usage($truck, $this->pallets(12, weightKg: '100.000'))->fits());

        $tooMany = $calculator->usage($truck, $this->pallets(13, weightKg: '100.000'));
        self::assertFalse($tooMany->fits());
        self::assertSame('Più bancali di quanti ne porta il mezzo', $tooMany->problem);
        self::assertSame(13, $tooMany->palletCount);
        self::assertSame(12, $tooMany->maxPallets);
    }

    public function testAtLeastOneLevelIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LoadCalculator(0);
    }
}
