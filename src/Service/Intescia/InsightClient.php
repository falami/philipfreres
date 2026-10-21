<?php

declare(strict_types=1);
namespace App\Service\Intescia;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class InsightClient
{
    public const AUTH = 'https://sso.intescia.com/realms/intescia/protocol/openid-connect/auth';
    private const TOKEN = 'https://sso.intescia.com/realms/intescia/protocol/openid-connect/token';
    private const API = 'https://v1.api.intescia.dev';
    private HttpClientInterface $http;
    private int $calls = 0;
    private int $limit = 100;

    public function __construct(private readonly InsightStore $store, private readonly InsightVault $vault)
    {
        // Deliberately outside the profiler: OAuth bodies and Bearer tokens must never be collected.
        $this->http = HttpClient::create(['timeout' => 20, 'max_duration' => 60, 'max_redirects' => 0]);
    }

    public function setHttpClient(HttpClientInterface $http): void { $this->http = $http; }
    public function budget(int $limit): void { $this->calls = 0; $this->limit = max(1, min(1000, $limit)); }
    public function calls(): int { return $this->calls; }

    public function exchange(int $tenant, string $code, string $redirect, string $verifier): void
    {
        $this->token($tenant, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect, 'code_verifier' => $verifier]);
    }

    private function token(int $tenant, array $grant): string
    {
        $credentials = $this->vault->open($this->store->credentials($tenant));
        if (empty($credentials['client_id']) || empty($credentials['client_secret'])) {
            throw new InsightException('Renseignez les identifiants de l’application OAuth Intescia.');
        }
        $body = $grant + ['client_id' => $credentials['client_id'], 'client_secret' => $credentials['client_secret']];
        try {
            $response = $this->http->request('POST', self::TOKEN, ['body' => $body, 'max_redirects' => 0]);
            if ($response->getStatusCode() !== 200) { throw new \RuntimeException(); }
            $tokens = $response->toArray(false);
            if (empty($tokens['access_token']) || !is_string($tokens['access_token']) || empty($tokens['refresh_token']) || !is_string($tokens['refresh_token'])) {
                throw new \RuntimeException();
            }
        } catch (\Throwable) {
            throw new InsightException('Connexion OAuth refusée ou indisponible. Reconnectez DoubleTrade et vérifiez les droits API.');
        }
        $credentials['access_token'] = $tokens['access_token'];
        $credentials['refresh_token'] = $tokens['refresh_token'];
        $credentials['expires_at'] = time() + max(1, (int) ($tokens['expires_in'] ?? 60));
        // Persist rotated refresh tokens immediately, before any business API call.
        $this->store->saveCredentials($tenant, $this->vault->seal($credentials));
        return $credentials['access_token'];
    }

    private function access(int $tenant, bool $force = false): string
    {
        $credentials = $this->vault->open($this->store->credentials($tenant));
        if (!$force && !empty($credentials['access_token']) && ($credentials['expires_at'] ?? 0) > time() + 30) {
            return $credentials['access_token'];
        }
        if (empty($credentials['refresh_token'])) { throw new InsightException('Connectez d’abord votre compte DoubleTrade.'); }
        return $this->token($tenant, ['grant_type' => 'refresh_token', 'refresh_token' => $credentials['refresh_token']]);
    }

    public function request(int $tenant, string $method, string $path, ?array $body = null): array
    {
        if (!preg_match('~^/(?:me(?:/organization(?:/collaborative-statuses)?)?|me/deal-opportunities|contracts/[a-f0-9-]{36}(?:/lots)?)(?:\?[a-zA-Z0-9=&%,._-]+)?$~D', $path)) {
            throw new InsightException('Chemin API non autorisé.');
        }
        $access = $this->access($tenant);
        $refreshed = false;
        $redirects = 0;
        while (true) {
            if ($this->calls >= $this->limit) { throw new InsightException('Plafond d’appels atteint. Import incomplet ; augmentez le plafond ou réduisez la sélection. Les fiches déjà récupérées sont conservées.'); }
            ++$this->calls;
            // Stay below the documented 10 requests/second, including error responses.
            usleep(120000);
            try {
                $options = ['auth_bearer' => $access, 'headers' => ['Accept' => 'application/vnd.hal+json, application/json'], 'max_redirects' => 0];
                if ($body !== null) { $options['json'] = $body; }
                $response = $this->http->request($method, self::API.$path, $options);
                $status = $response->getStatusCode();
                if ($status === 401 && !$refreshed) { $refreshed = true; $access = $this->access($tenant, true); continue; }
                if ($status === 308 && $method === 'GET' && ++$redirects <= 3) {
                    $location = $response->getHeaders(false)['location'][0] ?? '';
                    if (str_starts_with($location, self::API.'/')) { $location = substr($location, strlen(self::API)); }
                    if (!preg_match('~^/contracts/[a-f0-9-]{36}(?:/lots)?$~D', $location)) { throw new InsightException('Redirection API inattendue ; import arrêté.'); }
                    $path = $location;
                    continue;
                }
                if ($status !== 200) {
                    throw new InsightException(match ($status) {
                        401 => 'Session DoubleTrade expirée. Reconnectez le compte.',
                        402 => 'Crédits Intescia insuffisants. Import arrêté sans nouvelle tentative automatique.',
                        403 => 'Votre abonnement ou vos droits Intescia ne permettent pas cet accès.',
                        429 => 'Limite de requêtes Intescia atteinte. Relancez l’import plus tard.',
                        default => 'API Intescia indisponible ou réponse refusée (HTTP '.$status.').',
                    });
                }
                $data = $response->toArray(false);
                return $data;
            } catch (InsightException $e) { throw $e; }
            catch (\Throwable) { throw new InsightException('Réponse Intescia illisible ou délai de connexion dépassé.'); }
        }
    }
}
