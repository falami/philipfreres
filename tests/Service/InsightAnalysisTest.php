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
        self::assertSame([], $a['lots']);
        self::assertSame([], $a['phases']);
        self::assertSame([], $a['evidence']);
        self::assertSame([], $a['warnings']);
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
            'renewal' => '2030-09-30',
            'responseUrl' => 'javascript:alert(1)',
        ]]], 'followed' => ['metadata' => ['hasQuestionsAnswers' => true]]]);
        self::assertTrue($a['percent']);
        self::assertSame('Prix', $a['criteria'][0]['title']);
        self::assertSame('1.600.000,00 € HT', $a['ceiling']);
        self::assertTrue($a['noMinimum']);
        self::assertContains('Calendrier des reconductions à confirmer', array_column($a['warnings'], 'title'));
        self::assertContains('Échéance estimée par Intescia', array_column($a['warnings'], 'title'));
        self::assertContains('Plafond extrait du texte à confirmer', array_column($a['warnings'], 'title'));
        self::assertContains('Documents signalés, contenu à analyser', array_column($a['warnings'], 'title'));
        self::assertTrue($a['docs']);
        self::assertTrue($a['qa']);
        self::assertNull($a['responseUrl']);
    }

    public function testPartialWeightsAreNotPresentedAsPercentages(): void
    {
        $a = InsightAnalysis::build(['enrichment' => ['detail' => ['contract' => ['awardCriteria' => [['text' => 'Prix', 'weight' => 50]]]]]]);
        self::assertFalse($a['percent']);
        self::assertContains('Notation à compléter', array_column($a['warnings'], 'title'));
    }

    public function testInvalidWeightsCannotProduceAValidSplitDespiteSummingToOneHundred(): void
    {
        $a = InsightAnalysis::build(['enrichment' => ['detail' => ['contract' => ['awardCriteria' => [
            ['text' => 'Prix', 'weight' => 110], ['text' => 'Technique', 'weight' => -10],
        ]]]]]);
        self::assertFalse($a['percent']);
        self::assertContains('Notation à compléter', array_column($a['warnings'], 'title'));
    }

    public function testEstimatedRenewalRequiresAnActualValidDate(): void
    {
        foreach ([null, '', '2030-02-31', 'not-a-date'] as $date) {
            $a = InsightAnalysis::build(['enrichment' => ['detail' => ['contract' => [
                'renewalOrigin' => 'DATA_SCIENCE', 'renewal' => $date,
            ]]]]);
            self::assertFalse($a['renewalEstimated']);
            self::assertNotContains('Échéance estimée par Intescia', array_column($a['warnings'], 'title'));
        }
        foreach (['jasse', 'cen'] as $name) {
            $a = InsightAnalysis::build($this->fixture($name));
            self::assertFalse($a['renewalEstimated']);
            self::assertNotContains('Échéance estimée par Intescia', array_column($a['warnings'], 'title'));
        }
        self::assertTrue(InsightAnalysis::build($this->fixture('acm'))['renewalEstimated']);
    }

    public function testCiradPreservesLotsAndProvenanceWithoutSummingCeilings(): void
    {
        $payload = $this->fixture('cirad');
        // Both representations exist in the actual export: do not count the same lots twice.
        $payload['enrichment']['lot_pages'] = [['content' => $payload['enrichment']['lots']]];
        $a = InsightAnalysis::build($payload);
        self::assertCount(2, $a['lots']);
        self::assertNull($a['ceiling']);
        self::assertSame('200 000,00 € HT', $a['lots'][0]['ceiling']);
        self::assertSame('120 000,00 € HT', $a['lots'][1]['ceiling']);
        self::assertSame('2026-10-29 11:00:00', $a['lots'][0]['deadline']);
        self::assertSame('2026-12-01', $a['lots'][0]['start']);
        self::assertSame('12 mois', $a['lots'][0]['duration']);
        self::assertSame('1 an', $a['extensionLabels'][0]['duration']);
        self::assertSame('/enrichment/lots/0', $a['lots'][0]['source']);
        self::assertStringContainsString("\nMontant HT maximum", $a['lots'][0]['description']);
        self::assertStringNotContainsString('&amp;', $a['lots'][0]['description']);
        self::assertStringNotContainsString('<br', $a['lots'][0]['description']);
        $amountWarnings = array_values(array_filter($a['warnings'], static fn ($w) => $w['title'] === 'Plafond extrait du texte à confirmer'));
        self::assertCount(1, $amountWarnings);
        self::assertStringContainsString('/enrichment/lots/0/translations/0/description', $amountWarnings[0]['source']);
        self::assertStringContainsString('/enrichment/lots/1/translations/0/description', $amountWarnings[0]['source']);
        self::assertContains('Reconduction et démarrage du lot à rapprocher', array_column($a['warnings'], 'title'));
        self::assertSame([], $a['phases']);
        // A cached page alone also supplies the same lots, with its actual source pointer.
        unset($payload['enrichment']['lots']);
        $fallback = InsightAnalysis::build($payload);
        self::assertCount(2, $fallback['lots']);
        self::assertSame('/enrichment/lot_pages/0/content/0', $fallback['lots'][0]['source']);
    }

    public function testAcmHighlightsPriceWeightsAndAmbiguousMaximumPeriod(): void
    {
        $a = InsightAnalysis::build($this->fixture('acm'));
        self::assertTrue($a['percent']);
        self::assertSame([50.0, 40.0, 10.0], array_column($a['criteria'], 'weight'));
        self::assertSame('/enrichment/detail/contract/awardCriteria/2', $a['criteria'][0]['source']);
        self::assertSame('1.600.000,00 € HT', $a['ceiling']);
        self::assertTrue($a['noMinimum']);
        self::assertSame('12 mois', $a['durationLabel']);
        self::assertSame('2027-09-30', $a['extensionLabels'][0]['start']);
        self::assertContains('Période du plafond à clarifier', array_column($a['warnings'], 'title'));
        self::assertContains('Calendrier des reconductions à confirmer', array_column($a['warnings'], 'title'));
        self::assertFalse($a['hasAward']);
        self::assertNull($a['responses']);
        self::assertSame([], $a['lots']);
        $ceilingEvidence = array_values(array_filter($a['evidence'], static fn ($e) => $e['label'] === 'Plafond annoncé dans le descriptif'));
        self::assertSame('text', $ceilingEvidence[0]['kind']);
        self::assertSame('/enrichment/detail/contract/translations/0/description', $ceilingEvidence[0]['source']);
    }

    public function testJasseKeepsLinearScopeWithoutInventingLotsOrVolumes(): void
    {
        $a = InsightAnalysis::build($this->fixture('jasse'));
        self::assertSame([], $a['lots']);
        self::assertSame('4 mois', $a['durationLabel']);
        self::assertCount(2, $a['phases']);
        self::assertStringContainsString('40 ml', $a['phases'][0]['description']);
        self::assertStringContainsString('35 ml', $a['phases'][1]['description']);
        self::assertStringContainsString('Lac de la Mourre', $a['phases'][0]['title']);
        self::assertStringContainsString('RD189', $a['phases'][1]['title']);
        self::assertNull($a['ceiling']);
        self::assertStringContainsString('Ne pas convertir les linéaires', $a['specific'][1]);
    }

    public function testCenSeparatesFirmPhasesAndConditionalTrancheFromMultilineText(): void
    {
        $a = InsightAnalysis::build($this->fixture('cen'));
        self::assertSame([], $a['lots']);
        self::assertCount(4, $a['phases']);
        self::assertSame('Tranche ferme · Phase n°1', $a['phases'][0]['title']);
        self::assertSame('Tranche ferme · Phase n°3', $a['phases'][2]['title']);
        self::assertStringContainsString('Espèces exotiques végétales envahissantes', $a['phases'][2]['description']);
        self::assertSame('Tranche conditionnelle (descriptif)', $a['phases'][3]['title']);
        self::assertStringContainsString('secteur B2', $a['phases'][3]['description']);
        self::assertStringNotContainsString('Tranche conditionnelle', $a['phases'][2]['description']);
        self::assertSame('/enrichment/detail/contract/translations/0/description', $a['phases'][3]['source']);
        self::assertNull($a['durationLabel']);
        self::assertNull($a['ceiling']);
    }

    public function testEstimatedAmountDoesNotBecomeMaximumOrMissingDataBecomeFalse(): void
    {
        $a = InsightAnalysis::build(['enrichment' => ['detail' => ['contract' => [
            'translations' => [['language' => 'FR', 'description' => 'Estimation : 50 000 euros HT. Les prestations sont à préciser.']],
            'estimatedAmount' => ['singleValue' => 50000, 'currencyCode' => 'EUR'],
            'estimatedAmountExcludingTax' => true,
            'initialDuration' => ['duration' => 2, 'durationType' => 'YEAR'],
            'timeFrame' => ['start' => '2027-01-01'],
            'extensions' => [['startDate' => '2029-01-01', 'duration' => ['duration' => 1, 'durationType' => 'YEAR']]],
        ]]]]);
        self::assertNull($a['ceiling']);
        self::assertSame('2 ans', $a['durationLabel']);
        self::assertNotContains('Calendrier des reconductions à confirmer', array_column($a['warnings'], 'title'));
        self::assertSame('Non renseigné — à vérifier', $a['requirements'][0]['value']);
        self::assertContains('50 000,00 EUR HT', array_column($a['evidence'], 'value'));
    }

    public function testMaximumTaxAndDatesAreNotTakenFromUnrelatedFields(): void
    {
        foreach ([
            'Maximum : 50 000 EUR TTC. Les prix unitaires sont HT.' => '50 000 € TTC',
            'Montant HT maximum du marché : 50 000 euros' => '50 000 € HT',
            'Maximum : 50 000 €. Les prix unitaires sont HT.' => '50 000 € · régime fiscal à confirmer',
            'Estimation : 50 000 € HT' => null,
        ] as $description => $expected) {
            $payload = ['enrichment' => ['detail' => ['contract' => [
                'translations' => [['language' => 'FR', 'description' => $description]],
                'response' => '2026-11-02T11:00:00Z',
            ]]], 'followed' => ['dealOpportunity' => ['deadline' => '2026-11-10T11:00:00Z']]];
            $a = InsightAnalysis::build($payload);
            self::assertSame($expected, $a['ceiling']);
            self::assertContains('02/11/2026 à 12:00 · heure de Paris', array_column($a['evidence'], 'value'));
            $payload['followed']['dealOpportunity']['response'] = null;
            self::assertNotContains('Date limite annoncée', array_column(InsightAnalysis::build($payload)['evidence'], 'label'));
        }
    }

    private function fixture(string $name): array
    {
        // Facts-only excerpts: no people, contact details, tokens or full imported payloads.
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/Intescia/analysis-tenders.json'), true, 512, JSON_THROW_ON_ERROR)[$name];
    }
}
