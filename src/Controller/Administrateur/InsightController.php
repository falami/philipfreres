<?php

declare(strict_types=1);
namespace App\Controller\Administrateur;

use App\Entity\Entite;
use App\Service\Intescia\{InsightStore, InsightVault, InsightClient, InsightNormalizer, InsightRecipients, InsightException};
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/marches', name: 'app_administrateur_marches_', requirements: ['entite' => '\d+'])]
#[IsGranted('ENTITE_ADMIN', subject: 'entite')]
final class InsightController extends AbstractController
{
    public function __construct(private readonly InsightStore $store, private readonly InsightVault $vault, private readonly InsightClient $client, private readonly InsightRecipients $recipients, private readonly \App\Service\Intescia\InsightPreparation $preparation, #[Autowire('%env(default::APP_URL)%')] private readonly ?string $appUrl) {}

    private function callbackUrl(Entite $entite): string
    {
        return rtrim($this->appUrl ?? '', '/').$this->generateUrl('app_administrateur_marches_callback', ['entite' => $entite->getId(), '_locale' => 'fr']);
    }

    private function csrf(Request $request, Entite $entite): void
    {
        if (!$this->isCsrfTokenValid('insight-'.$entite->getId(), $request->request->get('_token'))) { throw $this->createAccessDeniedException('Formulaire expiré.'); }
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[MapEntity(id: 'entite')] Entite $entite): Response
    {
        $tenant = $entite->getId();
        $config = $this->store->config($tenant);
        $stats = $this->store->db->fetchAssociative('SELECT COUNT(*) AS total, SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN active = 1 AND deadline IS NULL THEN 1 ELSE 0 END) AS undated FROM insight_market WHERE tenant_id = ?', [$tenant]);
        $deliveries = $this->store->db->fetchAllAssociative('SELECT d.status, d.updated_at, m.title, d.user_id FROM insight_delivery d JOIN insight_market m ON m.id = d.market_id AND m.tenant_id = d.tenant_id WHERE d.tenant_id = ? ORDER BY d.created_at DESC LIMIT 50', [$tenant]);
        $digests = $this->store->db->fetchAllAssociative('SELECT status, updated_at, scheduled_date, user_id FROM insight_digest_delivery WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 50', [$tenant]);
        return $this->render('administrateur/marches/index.html.twig', ['entite' => $entite, 'config' => $config, 'stats' => $stats, 'ready' => $this->vault->ready(), 'users' => $this->recipients->available($tenant), 'callback' => $this->callbackUrl($entite), 'deliveries' => $deliveries, 'digests' => $digests]);
    }

    #[Route('/mail-test/{kind}', name: 'mail_test', methods: ['POST'], requirements: ['kind' => 'digest|deadline'])]
    public function testMail(Request $request, #[MapEntity(id: 'entite')] Entite $entite, string $kind, \App\Service\Intescia\InsightDigest $digest, \App\Service\Intescia\InsightAlerts $alerts, \Symfony\Component\Mailer\Transport\TransportInterface $transport): Response
    {
        $this->csrf($request, $entite);
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\Utilisateur || !filter_var($user->getEmail(), FILTER_VALIDATE_EMAIL)) { throw $this->createAccessDeniedException('Adresse du compte invalide.'); }
        try {
            $this->store->locked($entite->getId(), function () use ($request, $entite, $kind, $digest, $alerts, $transport, $user): void {
                $session = $request->getSession();
                $key = 'insight_mail_test_'.$entite->getId();
                if ((int) $session->get($key, 0) > time() - 60) { throw new InsightException('Patientez une minute avant un nouvel envoi de test.'); }
                $session->set($key, time());
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $markets = $this->store->db->fetchAllAssociative('SELECT id, title, deadline FROM insight_market WHERE tenant_id = ? AND active = 1 AND deadline > ? ORDER BY deadline ASC LIMIT 20', [$entite->getId(), $now->format('Y-m-d H:i:s')]);
                $demo = !$markets;
                if ($demo) { $markets = [['id' => 'demonstration', 'title' => 'Exemple · Entretien des espaces verts', 'deadline' => $now->modify('+7 days')->format('Y-m-d H:i:s')]]; }
                if ($kind === 'digest') {
                    foreach ($markets as &$market) {
                        $market['deadline'] = new \DateTimeImmutable($market['deadline'], new \DateTimeZone('UTC'));
                        $market['days'] = (int) ceil(($market['deadline']->getTimestamp() - $now->getTimestamp()) / 86400);
                    }
                    unset($market);
                    $email = $digest->createEmail($entite->getId(), $markets, $now, $user->getEmail());
                } else {
                    $market = $demo ? $markets[0] + ['buyer' => 'Acheteur de démonstration', 'payload' => ['contract_id' => 'demonstration']] : $this->store->market($entite->getId(), $markets[0]['id']);
                    $email = $alerts->createEmail($entite->getId(), $market, ['name' => trim($user->getPrenom().' '.$user->getNom()), 'email' => $user->getEmail()]);
                }
                $email->subject('[TEST'.($demo ? ' · DÉMONSTRATION' : '').'] '.$email->getSubject());
                $transport->send($email);
            });
            $this->addFlash('success', 'Mail de test transmis au serveur SMTP pour '.$user->getEmail().'. Aucun destinataire des alertes n’a été sollicité.');
        } catch (InsightException $e) { $this->addFlash('warning', $e->getMessage()); }
        catch (\Throwable) { $this->addFlash('warning', 'Envoi du test non confirmé. Vérifiez votre boîte et la configuration SMTP avant de réessayer.'); }
        return $this->redirectToRoute('app_administrateur_marches_index', ['entite' => $entite->getId()]);
    }

    #[Route('/parametres', name: 'settings', methods: ['POST'])]
    public function settings(Request $request, #[MapEntity(id: 'entite')] Entite $entite): Response
    {
        $this->csrf($request, $entite);
        try {
            $this->store->locked($entite->getId(), function () use ($request, $entite): void {
                $tenant = $entite->getId();
                $config = $this->store->config($tenant);
                $hours = filter_var($request->request->get('lead_hours'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 8760]]);
                $calls = filter_var($request->request->get('max_calls'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 1000]]);
                if ($hours === false || $calls === false) { throw new InsightException('Délai : 1 à 8 760 heures. Plafond : 5 à 1 000 appels.'); }
                $selected = array_map('intval', $request->request->all('recipients'));
                $users = $this->recipients->available($tenant);
                if (array_diff($selected, array_keys($users))) { throw new InsightException('Un destinataire ne fait plus partie des utilisateurs actifs de cette entité.'); }
                $statuses = $request->request->all('statuses');
                if (array_diff($statuses, array_column($config['status_catalog'], 'id'))) { throw new InsightException('Un statut DoubleTrade est invalide. Actualisez la liste.'); }
                $fields = trim((string) $request->request->get('custom_fields', ''));
                if ($fields !== '' && !preg_match('/^\d+(?:\s*,\s*\d+)*$/D', $fields)) { throw new InsightException('Les identifiants de champs personnalisés doivent être des nombres séparés par des virgules.'); }
                $digest = $request->request->has('digest_enabled');
                $interval = (int) $request->request->get('digest_interval', 1);
                $digestTime = (string) $request->request->get('digest_time', '08:00');
                $digestStart = (string) $request->request->get('digest_start', $config['digest_start'] ?? (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d'));
                $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $digestStart);
                if (!in_array($interval, [1, 2, 7], true) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $digestTime) || !$parsedDate || $parsedDate->format('Y-m-d') !== $digestStart) { throw new InsightException('Vérifiez la fréquence, la date de début et l’heure du récapitulatif.'); }
                $alerts = $request->request->has('alerts');
                $auto = $request->request->has('auto_sync');
                if (($alerts || $auto || $digest) && (!$statuses || !$config['account'] || !$config['authorized'])) { throw new InsightException('Connectez DoubleTrade et choisissez les statuts avant d’activer les automatismes.'); }
                if (($alerts || $digest) && !$selected) { throw new InsightException('Choisissez au moins un destinataire.'); }
                if ($config['statuses'] !== $statuses) { $config['sync_ok'] = false; }
                $config = array_replace($config, ['digest_enabled' => $digest, 'digest_interval' => $interval, 'digest_time' => $digestTime, 'digest_start' => $digestStart, 'lead_hours' => $hours, 'max_calls' => $calls, 'recipients' => array_values(array_unique($selected)), 'statuses' => array_values($statuses), 'alerts' => $alerts, 'auto_sync' => $auto, 'include_details' => $request->request->has('include_details'), 'include_lots' => $request->request->has('include_lots'), 'custom_fields' => $fields === '' ? [] : array_map('intval', explode(',', $fields))]);
                $this->store->saveConfig($tenant, $config);
            });
            $this->addFlash('success', 'Paramètres enregistrés.');
        } catch (InsightException $e) { $this->addFlash('warning', $e->getMessage()); }
        return $this->redirectToRoute('app_administrateur_marches_index', ['entite' => $entite->getId()]);
    }

    #[Route('/connexion', name: 'connect', methods: ['POST'])]
    public function connect(Request $request, #[MapEntity(id: 'entite')] Entite $entite): Response
    {
        $this->csrf($request, $entite);
        try {
            $url = $this->store->locked($entite->getId(), function () use ($request, $entite): string {
                $tenant = $entite->getId();
                if (!filter_var($this->appUrl, FILTER_VALIDATE_URL)) { throw new InsightException('Configurez APP_URL avec l’adresse publique de cette installation avant la connexion.'); }
                $credentials = $this->vault->open($this->store->credentials($tenant));
                $clientId = trim((string) $request->request->get('client_id', ''));
                $secret = (string) $request->request->get('client_secret', '');
                if ($clientId !== '' || $secret !== '') {
                    if ($clientId === '' || $secret === '' || strlen($clientId) > 250 || strlen($secret) > 4000) { throw new InsightException('Renseignez ensemble le Client ID et le Client Secret.'); }
                    $credentials = ['client_id' => $clientId, 'client_secret' => $secret];
                    $this->store->saveCredentials($tenant, $this->vault->seal($credentials));
                    $this->store->saveConfig($tenant, array_replace($this->store->config($tenant), ['authorized' => false, 'digest_enabled' => false, 'auto_sync' => false, 'alerts' => false, 'queued' => false, 'sync_ok' => false, 'message' => 'Nouveaux identifiants enregistrés. Terminez la connexion OAuth pour reprendre.']));
                }
                if (empty($credentials['client_id'])) { throw new InsightException('Renseignez les identifiants OAuth fournis par Intescia.'); }
                $state = bin2hex(random_bytes(32));
                $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
                $request->getSession()->set('insight_oauth_'.$tenant, ['state' => $state, 'verifier' => $verifier, 'expires' => time() + 600, 'redirect' => $this->callbackUrl($entite)]);
                return InsightClient::AUTH.'?'.http_build_query(['response_type' => 'code', 'client_id' => $credentials['client_id'], 'redirect_uri' => $this->callbackUrl($entite), 'scope' => 'openid', 'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256']);
            });
            return $this->redirect($url);
        } catch (InsightException $e) { $this->addFlash('warning', $e->getMessage()); }
        return $this->redirectToRoute('app_administrateur_marches_index', ['entite' => $entite->getId()]);
    }

    #[Route('/oauth/retour', name: 'callback', methods: ['GET'])]
    public function callback(Request $request, #[MapEntity(id: 'entite')] Entite $entite): Response
    {
        $tenant = $entite->getId();
        $state = $request->getSession()->remove('insight_oauth_'.$tenant);
        if (!is_array($state) || ($state['expires'] ?? 0) < time() || !hash_equals($state['state'], (string) $request->query->get('state', ''))) { throw $this->createAccessDeniedException('Connexion OAuth expirée ou invalide.'); }
        try {
            if (!$request->query->get('code') || $request->query->has('error')) { throw new InsightException('Autorisation DoubleTrade annulée ou refusée.'); }
            $this->store->locked($tenant, function () use ($tenant, $request, $state): void {
                $this->client->budget(10);
                $previous = $this->store->credentials($tenant);
                try {
                    $this->client->exchange($tenant, (string) $request->query->get('code'), $state['redirect'], $state['verifier']);
                    $me = $this->client->request($tenant, 'GET', '/me');
                    if (empty($me['id']) || empty($me['email'])) { throw new InsightException('Profil DoubleTrade incomplet.'); }
                    $config = $this->store->config($tenant);
                    if ($config['account'] && (int) $config['account']['id'] !== (int) $me['id']) { throw new InsightException('Ce suivi est lié à un autre compte DoubleTrade. Déconnectez-le avant de changer de compte.'); }
                    $catalog = $this->client->request($tenant, 'POST', '/me/organization/collaborative-statuses');
                    $config['status_catalog'] = $this->catalog($catalog);
                    $config['authorized'] = true;
                    $config['account'] = ['id' => (int) $me['id'], 'email' => (string) $me['email'], 'name' => (string) ($me['fullName'] ?? $me['email'])];
                    $config['message'] = 'DoubleTrade connecté. Choisissez les statuts à importer dans les paramètres.';
                    $this->store->saveConfig($tenant, $config);
                } catch (\Throwable $e) { $this->store->saveCredentials($tenant, $previous); throw $e; }
            });
            $this->addFlash('success', 'DoubleTrade est connecté.');
        } catch (InsightException $e) { $this->addFlash('warning', $e->getMessage()); }
        return $this->redirectToRoute('app_administrateur_marches_index', ['entite' => $tenant]);
    }

    private function catalog(array $data): array
    {
        if (!array_is_list($data)) { throw new InsightException('Liste des statuts Intescia inattendue.'); }
        foreach ($data as $row) { if (!is_array($row) || !preg_match('/^[a-f0-9-]{36}$/D', $row['id'] ?? '')) { throw new InsightException('Statut Intescia invalide.'); } }
        return array_map(static fn (array $row) => ['id' => $row['id'], 'name' => $row['name'] ?? $row['nameTranslationKey'] ?? $row['id']], $data);
    }

    #[Route('/action/{action}', name: 'action', methods: ['POST'], requirements: ['action' => 'import|statuses|disconnect'])]
    public function action(string $action, Request $request, #[MapEntity(id: 'entite')] Entite $entite): Response
    {
        $this->csrf($request, $entite);
        try {
            $this->store->locked($entite->getId(), function () use ($action, $entite): void {
                $id = $entite->getId(); $config = $this->store->config($id);
                if ($action === 'disconnect') {
                    $credentials = $this->vault->open($this->store->credentials($id));
                    unset($credentials['access_token'], $credentials['refresh_token'], $credentials['expires_at']);
                    $this->store->saveCredentials($id, $this->vault->seal($credentials));
                    $config = array_replace($config, ['account' => null, 'digest_enabled' => false, 'authorized' => false, 'statuses' => [], 'status_catalog' => [], 'auto_sync' => false, 'alerts' => false, 'queued' => false, 'sync_ok' => false, 'message' => 'Connexion déconnectée. Données conservées.']);
                } elseif ($action === 'statuses') {
                    $this->client->budget(10);
                    $config['status_catalog'] = $this->catalog($this->client->request($id, 'POST', '/me/organization/collaborative-statuses'));
                } else {
                    if (!$config['authorized'] || !$config['account'] || !$config['statuses']) { throw new InsightException('Connectez DoubleTrade et choisissez au moins un statut.'); }
                    $config['queued'] = true;
                    $config['message'] = 'Import en attente du prochain passage de la tâche planifiée.';
                }
                $this->store->saveConfig($id, $config);
            });
            $this->addFlash('success', 'Demande enregistrée.');
        } catch (InsightException $e) { $this->addFlash('warning', $e->getMessage()); }
        return $this->redirectToRoute('app_administrateur_marches_index', ['entite' => $entite->getId()]);
    }


    #[Route('/fiche/{id}/actualiser', name: 'refresh', methods: ['POST'], requirements: ['id' => '[a-f0-9]{64}'])]
    public function refresh(string $id, Request $request, #[MapEntity(id: 'entite')] Entite $entite): Response
    {
        $this->csrf($request, $entite);
        $market = $this->store->market($entite->getId(), $id);
        if (!$market) { throw $this->createNotFoundException(); }
        try {
            $this->store->locked($entite->getId(), function () use ($entite, $market): void {
                $tenant = $entite->getId(); $config = $this->store->config($tenant);
                if (!$config['authorized'] || !$config['account'] || !$config['statuses'] || !$market['active']) { throw new InsightException('Ce marché doit appartenir à la sélection active du compte connecté.'); }
                $cache = $this->store->cache($tenant, $market['payload']['contract_id']);
                $cache['refresh_detail'] = true; $cache['refresh_lots'] = true;
                $this->store->saveCache($tenant, $market['payload']['contract_id'], $cache);
                $config['queued'] = true;
                $config['message'] = 'Actualisation complète demandée ; des appels de détail et de lots seront effectués.';
                $this->store->saveConfig($tenant, $config);
            });
            $this->addFlash('success', 'Actualisation complète ajoutée au prochain import.');
        } catch (InsightException $e) { $this->addFlash('warning', $e->getMessage()); }
        return $this->redirectToRoute('app_administrateur_marches_index', ['entite' => $entite->getId()]);
    }

    #[Route('/donnees', name: 'data', methods: ['GET'])]
    public function data(Request $request, #[MapEntity(id: 'entite')] Entite $entite): JsonResponse
    {
        $tenant = $entite->getId();
        $start = max(0, $request->query->getInt('start'));
        $length = max(1, min(100, $request->query->getInt('length', 25)));
        $search = mb_substr((string) ($request->query->all('search')['value'] ?? ''), 0, 200);
        $where = 'tenant_id = ?'; $params = [$tenant];
        if ($search !== '') { $where .= ' AND (title LIKE ? OR buyer LIKE ? OR remote_id LIKE ?)'; array_push($params, '%'.$search.'%', '%'.$search.'%', '%'.$search.'%'); }
        $order = $request->query->all('order')[0] ?? [];
        $column = [0 => 'title', 1 => 'buyer', 2 => 'deadline', 6 => 'active', 7 => 'updated_at'][(int) ($order['column'] ?? 2)] ?? 'deadline';
        $direction = strtolower($order['dir'] ?? '') === 'desc' ? 'DESC' : 'ASC';
        $rows = $this->store->db->fetchAllAssociative('SELECT id, title, buyer, deadline, active, updated_at, payload FROM insight_market WHERE '.$where.' ORDER BY '.$column.' '.$direction.', id ASC LIMIT '.$length.' OFFSET '.$start, $params);
        $catalog = array_column($this->store->config($tenant)['status_catalog'], 'name', 'id');
        foreach ($rows as &$row) {
            $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $op = $payload['followed']['dealOpportunity'] ?? [];
            $row['amount'] = $op['estimatedAmountExcludingTax'] ?? $op['estimatedAmount'] ?? null;
            $row['location'] = implode(', ', $op['executionCities'] ?? []);
            $statuses = [];
            foreach ($payload['followed']['metadata']['collaborativeDealOpportunities'] ?? [] as $followed) {
                $status = $followed['collaborativeStatusId'] ?? '';
                if (isset($catalog[$status])) { $statuses[] = $catalog[$status]; }
            }
            $row['statuses'] = implode(', ', array_unique($statuses));
            unset($row['payload']);
            $row['url'] = $this->generateUrl('app_administrateur_marches_detail', ['entite' => $tenant, 'id' => $row['id']]);
        }
        return $this->json(['draw' => $request->query->getInt('draw'), 'recordsTotal' => (int) $this->store->db->fetchOne('SELECT COUNT(*) FROM insight_market WHERE tenant_id = ?', [$tenant]), 'recordsFiltered' => (int) $this->store->db->fetchOne('SELECT COUNT(*) FROM insight_market WHERE '.$where, $params), 'data' => $rows]);
    }

    #[Route('/fiche/{id}/preparation', name: 'preparation', methods: ['POST'], requirements: ['id' => '[a-f0-9]{64}'])]
    public function savePreparation(string $id, Request $request, #[MapEntity(id: 'entite')] Entite $entite): Response
    {
        $this->csrf($request, $entite);
        $market = $this->store->market($entite->getId(), $id);
        if (!$market) throw $this->createNotFoundException();
        $payload = $market['payload'] + ['enrichment' => $this->store->cache($entite->getId(), $market['payload']['contract_id'])];
        try {
            $input = $request->request->all();
            $revision = filter_var($input['revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($revision === false || $revision === null) throw new InsightException('Version de formulaire invalide.');
            $snapshot = \App\Service\Intescia\InsightPreparation::snapshot($payload, $market);
            if (!is_string($input['source_version'] ?? null) || !hash_equals(\App\Service\Intescia\InsightPreparation::fingerprint($snapshot), $input['source_version'])) throw new InsightException('Les données du marché ont changé pendant votre préparation. Copiez votre saisie, rechargez la fiche et vérifiez les changements avant de réessayer.');
            $prior = $this->preparation->load($entite->getId(), $id);
            if (\App\Service\Intescia\InsightPreparation::changes($prior['data']['snapshot'], $snapshot) && ($input['reviewed_changes'] ?? '') !== '1') throw new InsightException('Confirmez la lecture des changements du marché.');
            $data = \App\Service\Intescia\InsightPreparation::validate($input, $this->recipients->available($entite->getId()));
            $user = $this->getUser();
            $author = $user instanceof \App\Entity\Utilisateur ? trim($user->getPrenom().' '.$user->getNom()) : 'Administrateur';
            $this->preparation->save($entite->getId(), $id, $revision, $data, $author, \App\Service\Intescia\InsightPreparation::snapshot($payload, $market));
            return $this->json(['ok' => true, 'revision' => $revision + 1, 'message' => 'Préparation enregistrée.', 'totals' => \App\Service\Intescia\InsightPreparation::totals($data['costs'])]);
        } catch (InsightException $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    #[Route('/fiche/{id}', name: 'detail', methods: ['GET'], requirements: ['id' => '[a-f0-9]{64}'])]
    public function detail(string $id, Request $request, #[MapEntity(id: 'entite')] Entite $entite): Response
    {
        $market = $this->store->market($entite->getId(), $id);
        if (!$market) { throw $this->createNotFoundException(); }
        $payload = $market['payload'] + ['enrichment' => $this->store->cache($entite->getId(), $market['payload']['contract_id'])];
        if ($request->query->get('format') === 'json') {
            return new Response(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="marche-'.$market['remote_id'].'.json"', 'X-Content-Type-Options' => 'nosniff']);
        }
        $preparation = $this->preparation->load($entite->getId(), $id);
        return $this->render('administrateur/marches/detail.html.twig', ['entite' => $entite, 'market' => $market, 'fields' => array_map(static fn ($field) => [$field[0], $field[1], \App\Service\Intescia\InsightPresentation::safeUrl($field[1])], InsightNormalizer::flatten($payload)), 'presentation' => \App\Service\Intescia\InsightPresentation::build($payload), 'payload' => $payload, 'preparation' => $preparation, 'preparation_source_version' => \App\Service\Intescia\InsightPreparation::fingerprint(\App\Service\Intescia\InsightPreparation::snapshot($payload, $market)), 'preparation_users' => $this->recipients->available($entite->getId()), 'preparation_decisions' => \App\Service\Intescia\InsightPreparation::DECISIONS, 'preparation_tasks' => \App\Service\Intescia\InsightPreparation::TASKS, 'preparation_changes' => \App\Service\Intescia\InsightPreparation::changes($preparation['data']['snapshot'], \App\Service\Intescia\InsightPreparation::snapshot($payload, $market)), 'preparation_totals' => \App\Service\Intescia\InsightPreparation::totals($preparation['data']['costs'])]);
    }
}
