<?php

declare(strict_types=1);
namespace App\Tests\Service\Intescia;

use App\Service\Intescia\InsightPresentation;
use PHPUnit\Framework\TestCase;

final class InsightPresentationTest extends TestCase
{
    public function testLinksAndLocationsStayGroundedInExecutionData(): void
    {
        $view = InsightPresentation::build([
            'followed' => ['dealOpportunity' => ['executionCities' => ['Montpellier', 'Montpellier', 'Montferrier-sur-Lez'], 'principal' => ['city' => 'Paris']]],
            'enrichment' => ['detail' => ['contract' => ['completeObject' => '<p>Entretien des espaces verts</p>', 'reference' => 'AO-42', 'website' => 'https://example.org/consultation', '_links' => ['self' => ['href' => 'https://v1.api.intescia.dev/contracts/42']]]]],
            'unsafe' => 'javascript:alert(1)',
        ]);
        self::assertCount(2, $view['locations']);
        self::assertSame('Montpellier', $view['locations'][0]['name']);
        self::assertCount(1, $view['links']);
        self::assertSame('https://example.org/consultation', $view['links'][0]['url']);
        self::assertSame('Entretien des espaces verts', $view['description']);
        foreach (['javascript:alert(1)', 'data:text/html,hello', 'https://user:secret@example.org', '//example.org'] as $url) { self::assertFalse(InsightPresentation::safeUrl($url)); }
        self::assertTrue(InsightPresentation::safeUrl('https://example.org/file.pdf?a=1&b=2'));
        self::assertSame([], InsightPresentation::build([])['locations']);
    }
}
