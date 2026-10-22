<?php

declare(strict_types=1);
namespace App\Service\Intescia;

/** Evidence-based preparation brief; never infers contractual requirements from missing data. */
final class InsightAnalysis
{
    public static function build(array $payload): array
    {
        $c = $payload['enrichment']['detail']['contract'] ?? [];
        $criteria = [];
        foreach ($c['awardCriteria'] ?? [] as $row) {
            $text = trim(strip_tags((string) ($row['text'] ?? '')));
            if ($text === '') continue;
            $environment = preg_match('/environ|écolog|ecolog/i', $text);
            $price = ($row['type'] ?? '') === 'PRICE';
            $criteria[] = [
                'title' => $text,
                'weight' => is_numeric($row['weight'] ?? null) ? (float) $row['weight'] : null,
                'order' => $row['order'] ?? 999,
                'action' => $price ? 'Chiffrer chaque prestation à partir de vos coûts réels. Vérifier les quantités et la formule de notation dans les pièces de prix et le règlement de consultation.' : ($environment ? 'Décrire des engagements mesurables : traitement des déchets, limitation des nuisances et organisation des déplacements, selon les prestations demandées.' : 'Construire une réponse point par point : méthode, équipe affectée, moyens disponibles, planning et contrôle qualité. Reprendre les sous-critères du règlement de consultation.'),
                'proof' => $price ? 'Décomposition des coûts, hypothèses de rendement, devis fournisseurs et contrôle de cohérence BPU / DQE / DPGF, selon les pièces demandées.' : ($environment ? 'Filières et justificatifs de valorisation, fiches des matériels, indicateurs de suivi et exemples de bilans.' : 'Références comparables, compétences des intervenants, fiches matériel, procédure de sécurité et exemple de compte rendu.'),
            ];
        }
        usort($criteria, static fn ($a, $b) => $a['order'] <=> $b['order']);
        $sum = array_sum(array_column($criteria, 'weight'));
        $percent = $criteria !== [] && count(array_filter($criteria, static fn ($r) => $r['weight'] === null)) === 0 && abs($sum - 100) < .01;
        $description = InsightNormalizer::translation($c['translations'] ?? [], 'description');
        $description = is_string($description) ? strip_tags($description) : '';
        $warnings = [];
        $initial = $c['initialDuration'] ?? [];
        $start = $c['timeFrame']['start'] ?? null;
        $extensions = $c['extensions'] ?? [];
        usort($extensions, static fn ($a, $b) => strcmp($a['startDate'] ?? '', $b['startDate'] ?? ''));
        if ($start && ($initial['durationType'] ?? '') === 'MONTH' && isset($extensions[0]['startDate'])) {
            try {
                $expected = (new \DateTimeImmutable($start))->modify('+'.(int) $initial['duration'].' months')->format('Y-m-d');
                if ($expected !== $extensions[0]['startDate']) $warnings[] = ['title' => 'Calendrier des reconductions à confirmer', 'text' => 'La première reconduction annoncée ne correspond pas à la fin calculée de la durée initiale. Vérifier les dates dans les pièces contractuelles.', 'source' => 'timeFrame.start · initialDuration · extensions'];
            } catch (\Exception) { /* Raw dates remain visible in source data. */ }
        }
        if (($c['renewalOrigin'] ?? '') === 'DATA_SCIENCE') $warnings[] = ['title' => 'Échéance estimée par Intescia', 'text' => 'La date de renouvellement est issue de DATA_SCIENCE. Elle ne doit pas être utilisée comme une échéance contractuelle confirmée.', 'source' => 'renewal · renewalOrigin'];
        $requirements = [];
        foreach (['mandatorySiteVisit' => 'Visite obligatoire', 'qualifications' => 'Qualifications exigées', 'withVariant' => 'Variantes', 'mandatoryExcelResponse' => 'Réponse Excel obligatoire'] as $key => $label) {
            $value = $c[$key] ?? null;
            $requirements[] = ['label' => $label, 'value' => $value === null ? 'Non renseigné — à vérifier' : (is_bool($value) ? ($value ? 'Oui, selon les données reçues' : 'Non, selon les données reçues') : (is_scalar($value) ? (string) $value : 'Voir les données détaillées')), 'source' => $key];
        }
        $docs = ($payload['enrichment']['detail']['hasDocuments'] ?? $payload['followed']['dealOpportunity']['hasDocuments'] ?? false) === true;
        $qa = ($payload['followed']['metadata']['hasQuestionsAnswers'] ?? false) === true;
        $noMinimum = preg_match('/sans\s+minimum/iu', $description) === 1;
        $ceiling = null;
        if (preg_match('/Maxi(?:mum)?\s*:\s*([\d.,\s]+)\s*(?:euro|€)/iu', $description, $match)) $ceiling = trim($match[1]).' €'.(preg_match('/(?:euro(?:\(s\))?|€)\s*HT/iu', $description) ? ' HT' : ' · régime fiscal à confirmer');
        $object = InsightNormalizer::translation($c['translations'] ?? [], 'object');
        $treeWork = is_string($object) && preg_match('/élag|elag|arbre/iu', $object);
        $specific = $treeWork ? [
            'Préparer une méthode par intervention : taille, élagage, gestion des rémanents et remise en état, selon le CCTP.',
            'Vérifier les accès pour les nacelles et broyeurs, les zones de stockage, le balisage et la présence éventuelle de riverains.',
            'Chiffrer les temps de déplacement et d’installation, les hauteurs d’intervention, les volumes de déchets et les moyens de remplacement.',
            'Rassembler des références d’élagage comparables et les justificatifs de compétence des équipes réellement disponibles.',
            'Décrire la protection des arbres et des abords, la maîtrise du bruit et la filière de valorisation des déchets verts.',
        ] : [
            'Décomposer les prestations en tâches, rendements, moyens humains et matériels.',
            'Identifier les contraintes de site et joindre des références comparables au périmètre demandé.',
        ];
        return compact('specific', 'criteria', 'percent', 'description', 'warnings', 'requirements', 'docs', 'qa', 'noMinimum', 'ceiling', 'initial', 'start', 'extensions') + [
            'framework' => $c['withFrameworkAgreement'] ?? null,
            'renewal' => $c['renewal'] ?? null,
            'renewalEstimated' => ($c['renewalOrigin'] ?? '') === 'DATA_SCIENCE',
            'hasAward' => !empty($c['award']),
            'responses' => $c['totalResponsesReceived'] ?? null,
            'responseUrl' => InsightPresentation::safeUrl($c['responseUrl'] ?? null) ? $c['responseUrl'] : null,
        ];
    }
}
