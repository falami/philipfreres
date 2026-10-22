<?php

declare(strict_types=1);
namespace App\Tests\Service\Intescia;

use App\Service\Intescia\{InsightPreparation, InsightException};
use PHPUnit\Framework\TestCase;

final class InsightPreparationTest extends TestCase
{
    public function testCentsRoundingAndZeroSales(): void
    {
        $a = InsightPreparation::validate(['decision' => 'go', 'costs' => [['label' => 'Transport', 'quantity' => '2.5', 'cost' => '10,01', 'price' => '12']]], []);
        self::assertSame(['cost' => 25.03, 'price' => 30, 'margin' => 4.97, 'rate' => 497 / 3000 * 100], InsightPreparation::totals($a['costs']));
        self::assertNull(InsightPreparation::totals([])['rate']);
        self::assertSame(0.15, InsightPreparation::totals([['quantity' => '0.145', 'cost' => '1', 'price' => '1']])['cost']);
    }
    public function testInvalidNestedFieldsAndDatesAreRejected(): void
    {
        foreach ([['target_date' => '2027-02-31'], ['costs' => [['label' => 'Test', 'quantity' => '-1', 'cost' => '2', 'price' => '3']]], ['notes' => ['injection']], ['documents' => [['title' => 'Doc', 'url' => 'javascript:alert(1)', 'status' => 'received']]], ['owner' => '99'], ['owner' => '1abc'], ['tasks' => 'invalid']] as $invalid) {
            try { InsightPreparation::validate(['decision' => 'go'] + $invalid, [1 => ['id' => 1]]); self::fail('Invalid input accepted'); }
            catch (InsightException $e) { self::assertNotSame('', $e->getMessage()); }
        }
    }
    public function testImportDiffIgnoresFirstPreparationButIdentifiesChangedSource(): void
    {
        $before = InsightPreparation::snapshot([], ['deadline' => '2027-02-01']);
        $after = InsightPreparation::snapshot([], ['deadline' => '2027-02-03']);
        self::assertSame([], InsightPreparation::changes([], $after));
        self::assertSame(['Date limite (UTC)'], InsightPreparation::changes($before, $after));
    }
}
