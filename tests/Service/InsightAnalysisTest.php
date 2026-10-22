<?php

declare(strict_types=1);
namespace App\Tests\Service;

use App\Service\Intescia\InsightAnalysis;
use PHPUnit\Framework\TestCase;

final class InsightAnalysisTest extends TestCase
{
    public function testMissingDataIsNotAnAbsentRequirement(): void
    {
        $a = InsightAnalysis::build([]);
        self::assertFalse($a['percent']);
        self::assertNull($a['ceiling']);
        self::assertFalse($a['hasAward']);
        self::assertNull($a['responses']);
        self::assertSame('Non renseigné — à vérifier', $a['requirements'][0]['value']);
    }

    public function testWeightsAndCalendarAndTextualCeiling(): void
    {
        $a = InsightAnalysis::build(['enrichment' => ['detail' => ['hasDocuments' => true, 'contract' => [
            'awardCriteria' => [
                ['text' => 'Environnement', 'weight' => 10, 'order' => 2],
                ['text' => 'Technique', 'weight' => 40, 'order' => 1],
                ['text' => 'Prix', 'type' => 'PRICE', 'weight' => 50, 'order' => 0],
            ],
            'translations' => [['language' => 'FR', 'description' => 'Sans Minimum/Maxi: 1.600.000,00 euro(s) HT sur la durée totale du marché']],
            'timeFrame' => ['start' => '2027-03-01'],
            'initialDuration' => ['duration' => 12, 'durationType' => 'MONTH'],
            'extensions' => [['startDate' => '2027-09-30']],
            'renewalOrigin' => 'DATA_SCIENCE',
            'responseUrl' => 'javascript:alert(1)',
        ]]], 'followed' => ['metadata' => ['hasQuestionsAnswers' => true]]]);
        self::assertTrue($a['percent']);
        self::assertSame('Prix', $a['criteria'][0]['title']);
        self::assertSame('1.600.000,00 € HT', $a['ceiling']);
        self::assertTrue($a['noMinimum']);
        self::assertCount(2, $a['warnings']);
        self::assertTrue($a['docs']);
        self::assertTrue($a['qa']);
        self::assertNull($a['responseUrl']);
    }

    public function testPartialWeightsAreNotPresentedAsPercentages(): void
    {
        $a = InsightAnalysis::build(['enrichment' => ['detail' => ['contract' => ['awardCriteria' => [['text' => 'Prix', 'weight' => 50]]]]]]);
        self::assertFalse($a['percent']);
    }
}
