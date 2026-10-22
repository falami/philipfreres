<?php

declare(strict_types=1);
namespace App\Service\Intescia;

/** Evidence-based preparation brief; never infers contractual requirements from missing data. */
final class InsightAnalysis
{
    private const CONTRACT = '/enrichment/detail/contract';

    public static function build(array $payload): array
    {
        $c = is_array($payload['enrichment']['detail']['contract'] ?? null) ? $payload['enrichment']['detail']['contract'] : [];
        [$description, $descriptionSource] = self::translated($c, 'description', self::CONTRACT);
        [$object] = self::translated($c, 'object', self::CONTRACT);
        $criteria = [];
        foreach ($c['awardCriteria'] ?? [] as $index => $row) {
            if (!is_array($row)) continue;
            $text = self::text($row['text'] ?? '');
            if ($text === '') continue;
            $environment = preg_match('/environ|écolog|ecolog/iu', $text);
            $price = ($row['type'] ?? '') === 'PRICE';
            $criteria[] = [
                'title' => $text,
                'weight' => is_numeric($row['weight'] ?? null) ? (float) $row['weight'] : null,
                'order' => $row['order'] ?? 999,
                'source' => self::CONTRACT.'/awardCriteria/'.$index,
                'action' => $price ? 'Chiffrer chaque prestation à partir de vos coûts réels. Vérifier les quantités et la formule de notation dans les pièces de prix et le règlement de consultation.' : ($environment ? 'Décrire des engagements mesurables : traitement des déchets, limitation des nuisances et organisation des déplacements, selon les prestations demandées.' : 'Construire une réponse point par point : méthode, équipe affectée, moyens disponibles, planning et contrôle qualité. Reprendre les sous-critères du règlement de consultation.'),
                'proof' => $price ? 'Décomposition des coûts, hypothèses de rendement, devis fournisseurs et contrôle de cohérence BPU / DQE / DPGF, selon les pièces demandées.' : ($environment ? 'Filières et justificatifs de valorisation, fiches des matériels, indicateurs de suivi et exemples de bilans.' : 'Références comparables, compétences des intervenants, fiches matériel, procédure de sécurité et exemple de compte rendu.'),
            ];
        }
        usort($criteria, static fn ($a, $b) => $a['order'] <=> $b['order']);
        $sum = array_sum(array_column($criteria, 'weight'));
        $percent = $criteria !== [] && count(array_filter($criteria, static fn ($r) => $r['weight'] === null || $r['weight'] < 0 || $r['weight'] > 100)) === 0 && abs($sum - 100) < .01;
        $warnings = [];
        $evidence = [];
        $initial = is_array($c['initialDuration'] ?? null) ? $c['initialDuration'] : [];
        $start = self::date($c['timeFrame']['start'] ?? null);
        $durationLabel = self::duration($initial);
        $extensions = array_values(array_filter($c['extensions'] ?? [], 'is_array'));
        usort($extensions, static fn ($a, $b) => strcmp($a['startDate'] ?? '', $b['startDate'] ?? ''));
        $extensionLabels = [];
        foreach ($c['extensions'] ?? [] as $index => $extension) {
            if (!is_array($extension)) continue;
            $extensionLabels[] = ['start' => self::date($extension['startDate'] ?? null), 'duration' => self::duration($extension['duration'] ?? []), 'source' => self::CONTRACT.'/extensions/'.$index];
        }
        usort($extensionLabels, static fn ($a, $b) => strcmp($a['start'] ?? '', $b['start'] ?? ''));
        if (self::calendarMismatch($start, $initial, $extensions)) {
            $warnings[] = ['title' => 'Calendrier des reconductions à confirmer', 'text' => 'La première reconduction annoncée ne correspond pas à la fin calculée de la durée initiale. Vérifier les dates dans les pièces contractuelles.', 'source' => self::CONTRACT.'/timeFrame/start · '.self::CONTRACT.'/initialDuration · '.self::CONTRACT.'/extensions'];
        }
        $renewalEstimated = ($c['renewalOrigin'] ?? '') === 'DATA_SCIENCE' && self::date($c['renewal'] ?? null) !== null;
        if ($renewalEstimated) $warnings[] = ['title' => 'Échéance estimée par Intescia', 'text' => 'La date de renouvellement est issue de DATA_SCIENCE. Elle ne doit pas être utilisée comme une échéance contractuelle confirmée.', 'source' => self::CONTRACT.'/renewal · '.self::CONTRACT.'/renewalOrigin'];
        $requirements = [];
        foreach (['mandatorySiteVisit' => 'Visite obligatoire', 'qualifications' => 'Qualifications exigées', 'withVariant' => 'Variantes', 'mandatoryExcelResponse' => 'Réponse Excel obligatoire'] as $key => $label) {
            $value = $c[$key] ?? null;
            $requirements[] = ['label' => $label, 'value' => $value === null ? 'Non renseigné — à vérifier' : (is_bool($value) ? ($value ? 'Oui, selon les données reçues' : 'Non, selon les données reçues') : (is_scalar($value) ? self::text($value) : 'Voir les données détaillées')), 'source' => self::CONTRACT.'/'.$key];
        }
        $docs = ($payload['enrichment']['detail']['hasDocuments'] ?? $payload['followed']['dealOpportunity']['hasDocuments'] ?? false) === true;
        $qa = ($payload['followed']['metadata']['hasQuestionsAnswers'] ?? false) === true;
        $noMinimum = preg_match('/sans\s+minimum/iu', $description) === 1;
        $ceiling = self::ceiling($description);
        if ($ceiling !== null) {
            $evidence[] = self::fact('Plafond annoncé dans le descriptif', $ceiling, $descriptionSource, 'text');
            $warnings[] = self::textualAmountWarning($descriptionSource);
        }
        if ($noMinimum) $evidence[] = self::fact('Minimum annoncé dans le descriptif', 'Sans minimum', $descriptionSource, 'text');
        if ($ceiling && preg_match('/durée\s+ferme/iu', $description) && preg_match('/durée\s+totale/iu', $description)) {
            $warnings[] = ['title' => 'Période du plafond à clarifier', 'text' => 'Le descriptif mentionne à la fois la durée ferme et la durée totale du marché. Confirmer la période à laquelle le maximum s’applique avant le chiffrage.', 'source' => $descriptionSource];
        }
        if ($c !== [] && !$percent) {
            $warnings[] = ['title' => 'Notation à compléter', 'text' => $criteria === [] ? 'Les critères d’attribution ne sont pas renseignés dans les données reçues. Lire le règlement de consultation avant de structurer le mémoire.' : 'Les pondérations sont absentes, incomplètes, hors de l’intervalle 0 à 100 ou ne totalisent pas 100. Vérifier leur unité, les sous-critères et la formule dans le règlement de consultation.', 'source' => self::CONTRACT.'/awardCriteria'];
        }
        if ($docs) $warnings[] = ['title' => 'Documents signalés, contenu à analyser', 'text' => 'Des documents sont signalés par Intescia. Cette synthèse exploite les données importées ; elle ne confirme pas la lecture du règlement, des pièces techniques, des plans ou des pièces de prix.', 'source' => ($payload['enrichment']['detail']['hasDocuments'] ?? null) === true ? '/enrichment/detail/hasDocuments' : '/followed/dealOpportunity/hasDocuments'];
        if ($qa) $evidence[] = self::fact('Questions-réponses', 'Signalées — contenu à consulter', '/followed/metadata/hasQuestionsAnswers', 'structured');
        $opportunity = is_array($payload['followed']['dealOpportunity'] ?? null) ? $payload['followed']['dealOpportunity'] : [];
        $responseSource = array_key_exists('response', $opportunity) ? '/followed/dealOpportunity/response' : self::CONTRACT.'/response';
        $response = array_key_exists('response', $opportunity) ? $opportunity['response'] : ($c['response'] ?? null);
        if (($deadline = InsightNormalizer::deadline($response)) !== null) {
            $parisDeadline = (new \DateTimeImmutable($deadline, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Paris'));
            $evidence[] = self::fact('Date limite annoncée', $parisDeadline->format('d/m/Y à H:i').' · heure de Paris', $responseSource, 'structured');
        }
        if ($durationLabel !== null) $evidence[] = self::fact('Durée initiale annoncée', $durationLabel, self::CONTRACT.'/initialDuration', 'structured');
        if ($start !== null) $evidence[] = self::fact('Début annoncé', $start, self::CONTRACT.'/timeFrame/start', 'structured');
        if (is_scalar($c['reference'] ?? null) && trim((string) $c['reference']) !== '') $evidence[] = self::fact('Référence', self::text($c['reference']), self::CONTRACT.'/reference', 'structured');
        if (is_bool($c['withFrameworkAgreement'] ?? null)) $evidence[] = self::fact('Accord-cadre', $c['withFrameworkAgreement'] ? 'Oui, selon les données reçues' : 'Non, selon les données reçues', self::CONTRACT.'/withFrameworkAgreement', 'structured');
        // A structured estimate is not a ceiling, even when it is an upper estimate.
        $amount = $c['estimatedAmount']['singleValue'] ?? null;
        if (is_numeric($amount)) {
            $currency = self::text($c['estimatedAmount']['currencyCode'] ?? '') ?: 'devise non renseignée';
            $tax = match ($c['estimatedAmountExcludingTax'] ?? null) { true => ' HT', false => ' TTC', default => ' · régime fiscal non renseigné' };
            $evidence[] = self::fact('Estimation renseignée', number_format((float) $amount, 2, ',', ' ').' '.$currency.$tax, self::CONTRACT.'/estimatedAmount/singleValue · '.self::CONTRACT.'/estimatedAmountExcludingTax', 'structured');
        }
        $lots = [];
        foreach (self::lotRows($payload) as [$lot, $source]) {
            [$lotDescription, $lotDescriptionSource] = self::translated($lot, 'description', $source);
            [$title] = self::translated($lot, 'object', $source);
            $lotCeiling = self::ceiling($lotDescription);
            $lotStart = self::date($lot['timeFrame']['start'] ?? null);
            $lots[] = [
                'id' => is_scalar($lot['id'] ?? null) ? (string) $lot['id'] : null,
                'number' => self::text($lot['number'] ?? ''),
                'title' => $title ?: 'Lot sans intitulé renseigné',
                'description' => $lotDescription,
                'ceiling' => $lotCeiling,
                'deadline' => InsightNormalizer::deadline($lot['response'] ?? null),
                'start' => $lotStart,
                'duration' => self::duration($lot['timeFrame'] ?? []),
                'source' => $source,
            ];
            if ($lotCeiling !== null) {
                $evidence[] = self::fact('Plafond annoncé · lot '.self::text($lot['number'] ?? ''), $lotCeiling, $lotDescriptionSource, 'text');
                $warnings[] = self::textualAmountWarning($lotDescriptionSource);
            }
            if ($start && $lotStart && $start !== $lotStart) $warnings[] = ['title' => 'Démarrage du lot à confirmer', 'text' => 'Le début annoncé du lot '.self::text($lot['number'] ?? '').' diffère du début annoncé du marché. Vérifier les dates applicables à chaque périmètre.', 'source' => $source.'/timeFrame/start · '.self::CONTRACT.'/timeFrame/start'];
            if (self::calendarMismatch($lotStart, $lot['timeFrame'] ?? [], $extensions)) $warnings[] = ['title' => 'Reconduction et démarrage du lot à rapprocher', 'text' => 'Pour le lot '.self::text($lot['number'] ?? '').', le début et la durée annoncés ne rejoignent pas la première reconduction du marché. Les dates peuvent porter sur des périmètres différents : confirmer leur articulation.', 'source' => $source.'/timeFrame · '.self::CONTRACT.'/extensions'];
        }
        $expectedLots = $payload['enrichment']['detail']['lotsCount'] ?? null;
        if (is_numeric($expectedLots) && (int) $expectedLots > count($lots)) $warnings[] = ['title' => 'Détail des lots incomplet', 'text' => 'Le nombre de lots annoncé dépasse le nombre de lots détaillés disponibles. Compléter la récupération avant de décider des lots à chiffrer.', 'source' => '/enrichment/detail/lotsCount · /enrichment/lots'];
        $phases = self::phases($description, $descriptionSource);
        $treeWork = preg_match('/élag|elag|arbre/iu', $object);
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
        if (preg_match('/déchet|dechet|envahissant/iu', $object.' '.$description) && !$treeWork) $specific = [
            'Distinguer les sites, phases et tranches annoncés ; confirmer les volumes et la nature des déchets dans les pièces techniques.',
            'Demander les tarifs et conditions d’acceptation des filières adaptées ; chiffrer le tri, le chargement, le transport et le traitement.',
            'Pour les espèces envahissantes mentionnées, faire valider une méthode d’arrachage, de transport et de traitement limitant leur dissémination.',
            'Prévoir la traçabilité des évacuations, les accès, les zones de stockage et les moyens nécessaires à la remise en état.',
        ];
        if (preg_match('/enrochement|inondation|cours d[’\x27]eau/iu', $object.' '.$description)) $specific = [
            'Séparer les secteurs décrits et vérifier les plans, profils, volumes de terrassement et caractéristiques des enrochements.',
            'Ne pas convertir les linéaires annoncés en volumes sans géométrie justifiée ; expliciter les hypothèses de rendement et de quantité.',
            'Vérifier les accès, le phasage, les niveaux d’eau, les contraintes environnementales et les conditions d’intervention précisées au dossier.',
            'Chiffrer les approvisionnements, la manutention, les évacuations et les moyens de protection du chantier.',
        ];
        $warnings = self::uniqueWarnings($warnings);
        return compact('specific', 'criteria', 'percent', 'description', 'warnings', 'requirements', 'docs', 'qa', 'noMinimum', 'ceiling', 'initial', 'start', 'extensions', 'lots', 'phases', 'evidence', 'durationLabel', 'extensionLabels') + [
            'framework' => $c['withFrameworkAgreement'] ?? null,
            'renewal' => $c['renewal'] ?? null,
            'renewalEstimated' => $renewalEstimated,
            'hasAward' => !empty($c['award']),
            'responses' => $c['totalResponsesReceived'] ?? null,
            'responseUrl' => InsightPresentation::safeUrl($c['responseUrl'] ?? null) ? $c['responseUrl'] : null,
        ];
    }

    /** Decode nested API/HTML escaping before removing markup, keeping paragraph boundaries. */
    private static function text(mixed $value): string
    {
        if (!is_scalar($value)) return '';
        $text = (string) $value;
        for ($i = 0; $i < 4; ++$i) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) break;
            $text = $decoded;
        }
        $text = preg_replace('~<(?:br\s*/?|/p|/div|/li|/h[1-6])\s*>~iu', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = str_replace(["\r\n", "\r", "\u{00A0}", "\u{202F}"], ["\n", "\n", ' ', ' '], $text);
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        return trim(preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text);
    }

    /** @return array{string, string} */
    private static function translated(array $row, string $field, string $source): array
    {
        $fallback = ['', $source.'/translations'];
        foreach ($row['translations'] ?? [] as $index => $translation) {
            if (!is_array($translation)) continue;
            $text = self::text($translation[$field] ?? '');
            if ($text === '') continue;
            $found = [$text, $source.'/translations/'.$index.'/'.$field];
            if (($translation['language'] ?? '') === 'FR') return $found;
            if ($fallback[0] === '') $fallback = $found;
        }
        return $fallback;
    }

    private static function ceiling(string $text): ?string
    {
        // Require explicit maximum language and a currency; estimated amounts are not ceilings.
        if (!preg_match('/\bmaxi(?:mum)?\b[^\d\n.!?]{0,90}?([\d][\d\s.,\x{2019}\x27]*?)\s*(?:euros?(?:\(s\))?|EUR\b|€)/iu', $text, $match, PREG_OFFSET_CAPTURE)) return null;
        $amount = trim($match[1][0]);
        $after = substr($text, $match[0][1] + strlen($match[0][0]), 30);
        $before = substr($text, max(0, $match[0][1] - 30), min(30, $match[0][1]));
        $tax = ' · régime fiscal à confirmer';
        if (preg_match('/^\s*H\s*\.?\s*T\b\.?/iu', $after)) $tax = ' HT';
        elseif (preg_match('/^\s*T\s*\.?\s*T\s*\.?\s*C\b\.?/iu', $after)) $tax = ' TTC';
        elseif (preg_match('/\bH\s*\.?\s*T\b\.?\s*$/iu', $before)) $tax = ' HT';
        elseif (preg_match('/\bT\s*\.?\s*T\s*\.?\s*C\b\.?\s*$/iu', $before)) $tax = ' TTC';
        return $amount.' €'.$tax;
    }

    private static function duration(mixed $duration): ?string
    {
        if (!is_array($duration) || !is_numeric($duration['duration'] ?? null) || (float) $duration['duration'] <= 0) return null;
        $n = (float) $duration['duration'];
        $unit = match ($duration['durationType'] ?? '') { 'YEAR' => $n > 1 ? 'ans' : 'an', 'MONTH' => 'mois', 'WEEK' => $n > 1 ? 'semaines' : 'semaine', 'DAY' => $n > 1 ? 'jours' : 'jour', default => null };
        return $unit === null ? null : str_replace('.', ',', (string) $n).' '.$unit;
    }

    private static function date(mixed $date): ?string
    {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) return null;
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date ? $date : null;
    }

    private static function calendarMismatch(?string $start, array $duration, array $extensions): bool
    {
        $next = self::date($extensions[0]['startDate'] ?? null);
        if (!$start || !$next || !is_numeric($duration['duration'] ?? null) || (float) $duration['duration'] <= 0 || floor((float) $duration['duration']) !== (float) $duration['duration']) return false;
        $unit = match ($duration['durationType'] ?? '') { 'YEAR' => 'years', 'MONTH' => 'months', 'WEEK' => 'weeks', 'DAY' => 'days', default => null };
        if ($unit === null) return false;
        $expected = (new \DateTimeImmutable($start))->modify('+'.(int) $duration['duration'].' '.$unit);
        // Inclusive period-end dates may differ by one day from the next start.
        return abs((new \DateTimeImmutable($next))->getTimestamp() - $expected->getTimestamp()) > 86400;
    }

    /** @return list<array{array, string}> */
    private static function lotRows(array $payload): array
    {
        $rows = [];
        foreach ($payload['enrichment']['lots'] ?? [] as $index => $lot) if (is_array($lot)) $rows[] = [$lot, '/enrichment/lots/'.$index];
        foreach ($payload['enrichment']['lot_pages'] ?? [] as $page => $data) {
            foreach ($data['content'] ?? [] as $index => $lot) if (is_array($lot)) $rows[] = [$lot, '/enrichment/lot_pages/'.$page.'/content/'.$index];
        }
        $unique = [];
        foreach ($rows as [$lot, $source]) {
            $key = is_scalar($lot['id'] ?? null) ? (string) $lot['id'] : hash('sha256', serialize($lot));
            if (!isset($unique[$key])) $unique[$key] = [$lot, $source];
        }
        return array_values($unique);
    }

    /** Explicit named sections, not inferred contractual lots. */
    private static function phases(string $description, string $source): array
    {
        $phases = [];
        preg_match_all('/\b(Tranche\s+(?:ferme|conditionnelle|optionnelle))\s*:/iu', $description, $tranches, PREG_OFFSET_CAPTURE);
        foreach ($tranches[0] as $i => [$heading, $offset]) {
            $bodyStart = $offset + strlen($heading);
            $bodyEnd = $tranches[0][$i + 1][1] ?? strlen($description);
            $body = trim(substr($description, $bodyStart, $bodyEnd - $bodyStart));
            $trancheTitle = $tranches[1][$i][0];
            preg_match_all('/(?:^|\n)\s*[-–•]?\s*(Phase\s+n[°ºo]?\s*\d+)\s*[-–:]?/iu', $body, $parts, PREG_OFFSET_CAPTURE);
            if ($parts[0] === []) {
                $phases[] = ['title' => $trancheTitle.' (descriptif)', 'description' => ltrim($body, "-– \n"), 'source' => $source];
                continue;
            }
            foreach ($parts[0] as $j => [$phaseHeading, $phaseOffset]) {
                $from = $phaseOffset + strlen($phaseHeading);
                $to = $parts[0][$j + 1][1] ?? strlen($body);
                $phases[] = ['title' => $trancheTitle.' · '.$parts[1][$j][0], 'description' => trim(substr($body, $from, $to - $from)), 'source' => $source];
            }
        }
        if ($phases !== []) return $phases;
        // Named sectors with explicit linear quantities are kept as verbatim scope excerpts.
        foreach (explode("\n", $description) as $line) {
            if (!preg_match('/\bsecteur\s+([^.;]+)[.]?$/iu', $line, $sector) || !preg_match('/\b\d+\s*(?:ml|mètres?\s+linéaires?)\b/iu', $line)) continue;
            $phases[] = ['title' => 'Secteur '.trim($sector[1]).' (descriptif)', 'description' => ltrim($line, "-– \t"), 'source' => $source];
        }
        return $phases;
    }

    private static function fact(string $label, string $value, string $source, string $kind): array
    {
        return compact('label', 'value', 'source', 'kind');
    }

    private static function textualAmountWarning(string $source): array
    {
        return ['title' => 'Plafond extrait du texte à confirmer', 'text' => 'Un maximum est annoncé dans le descriptif. Confirmer son périmètre, sa durée et son régime fiscal dans les pièces de consultation ; il ne constitue pas un chiffre d’affaires garanti.', 'source' => $source];
    }

    private static function uniqueWarnings(array $warnings): array
    {
        $result = [];
        foreach ($warnings as $warning) {
            $key = $warning['title'].'|'.$warning['text'];
            if (isset($result[$key])) {
                $sources = array_unique(explode(' · ', $result[$key]['source'].' · '.$warning['source']));
                $result[$key]['source'] = implode(' · ', $sources);
            } else $result[$key] = $warning;
        }
        return array_values($result);
    }
}
