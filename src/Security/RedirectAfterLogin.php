<?php

namespace App\Security;

use App\Entity\Utilisateur;
use App\Entity\UtilisateurEntite;
use App\Repository\UtilisateurEntiteRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

final class RedirectAfterLogin
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly UtilisateurEntiteRepository $ueRepo,
    ) {}

    public function redirect(Request $request, Utilisateur $user): RedirectResponse
    {
        $session = $request->getSession();
        // A saved destination is single use, including rejected destinations.
        $target = $this->localTarget($session->remove('_security.main.target_path'), $request);
        $memberships = array_values(array_filter(
            $this->ueRepo->findAllForUser($user),
            static fn (UtilisateurEntite $membership): bool => $membership->isActive()
                && $membership->getEntite()?->getId() !== null,
        ));

        // The destination controller remains responsible for its own permission checks.
        $path = $target === null ? '' : (string) parse_url($target, PHP_URL_PATH);
        $baseUrl = $request->getBaseUrl();
        if ($baseUrl !== '' && str_starts_with($path, $baseUrl . '/')) {
            $path = substr($path, strlen($baseUrl));
        }
        if (preg_match('#^/(?:fr/|en/)?(administrateur|premium|employe)/(\d+)(/.*)?$#D', $path, $match)) {
            foreach ($memberships as $membership) {
                if ($membership->getEntite()->getId() !== (int) $match[2]) {
                    continue;
                }

                $area = $match[1];
                $suffix = $match[3] ?? '';
                $canRestore = $membership->isTenantAdmin()
                    || $area === 'employe'
                    || ($area === 'administrateur'
                        && $membership->hasRole(UtilisateurEntite::TENANT_CHEF)
                        && preg_match('#^/chantier(?:/|$)#D', $suffix));

                if ($canRestore) {
                    $session->set('current_entite_id', $membership->getEntite()->getId());
                    return new RedirectResponse($target);
                }

                return $this->redirectToMembership($request, $membership);
            }
        }

        $currentId = (int) $session->get('current_entite_id', 0);
        foreach ($memberships as $membership) {
            if ($membership->getEntite()->getId() === $currentId) {
                return $this->redirectToMembership($request, $membership);
            }
        }

        if (count($memberships) === 1) {
            return $this->redirectToMembership($request, $memberships[0]);
        }

        $session->remove('current_entite_id');
        return new RedirectResponse($this->router->generate('app_workspace'));
    }

    public function redirectToMembership(Request $request, UtilisateurEntite $membership): RedirectResponse
    {
        $entiteId = $membership->getEntite()?->getId();
        if (!$membership->isActive() || $entiteId === null) {
            $request->getSession()->remove('current_entite_id');
            return new RedirectResponse($this->router->generate('app_workspace'));
        }

        $request->getSession()->set('current_entite_id', $entiteId);
        $route = match (true) {
            $membership->isTenantAdmin() => 'app_administrateur_fuel_dashboard',
            $membership->hasRole(UtilisateurEntite::TENANT_CHEF) => 'app_administrateur_chantier_index',
            default => 'app_employe_access_denied',
        };

        return new RedirectResponse($this->router->generate($route, ['entite' => $entiteId]));
    }

    /** Return a local path only: never reflect an untrusted origin into Location. */
    private function localTarget(mixed $target, Request $request): ?string
    {
        if (!is_string($target) || $target === '' || strlen($target) > 4096) {
            return null;
        }

        // Browsers and proxies can interpret backslashes, controls and encoded
        // separators differently. Reject ambiguous paths before parsing them.
        $decoded = $target;
        for ($i = 0; $i < 5; ++$i) {
            if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)) {
                return null;
            }
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }
        if ($decoded !== rawurldecode($decoded) || str_starts_with($decoded, '//')
            || preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)) {
            return null;
        }

        $parts = parse_url($target);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }
        if (isset($parts['scheme']) || isset($parts['host'])) {
            $scheme = strtolower($parts['scheme'] ?? '');
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            if (!in_array($scheme, ['http', 'https'], true)
                || $scheme !== $request->getScheme()
                || strtolower($parts['host'] ?? '') !== strtolower($request->getHost())
                || $port !== $request->getPort()) {
                return null;
            }
        }

        $path = $parts['path'] ?? '';
        $decodedPath = (string) parse_url($decoded, PHP_URL_PATH);
        if (!str_starts_with($path, '/') || str_starts_with($decodedPath, '//')
            || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $decodedPath)
            || $path !== $decodedPath) {
            return null;
        }

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
