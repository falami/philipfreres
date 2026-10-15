<?php

declare(strict_types=1);

// This guard deliberately runs before Composer and Symfony, including when vendor is unavailable.
$deploymentRoot = dirname(__DIR__);
$deploymentFlag = $deploymentRoot . '/var/deployment-maintenance.json';
if (!file_exists($deploymentFlag) && !is_link($deploymentFlag)) {
    return;
}
$deploymentState = is_file($deploymentFlag) ? json_decode((string) @file_get_contents($deploymentFlag), true) : null;
$deploymentProbe = $_SERVER['HTTP_X_DEPLOYMENT_PROBE'] ?? '';
$deploymentHealthPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$deploymentProbeAllowed = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && in_array($deploymentHealthPath, ['/fr/login', '/css/auth.css', '/js/auth.js'], true)
    && is_string($deploymentProbe) && strlen($deploymentProbe) === 64
    && is_array($deploymentState) && isset($deploymentState['probe_hash'])
    && is_string($deploymentState['probe_hash']) && strlen($deploymentState['probe_hash']) === 64
    && hash_equals($deploymentState['probe_hash'], hash('sha256', $deploymentProbe));
if (!$deploymentProbeAllowed) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('Retry-After: 120');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'");
    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Maintenance · Philip Frères</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#12372f;color:#faf8f1;font:18px system-ui}main{max-width:32rem;padding:2rem}small{color:#d9be87}h1{font-size:2.5rem;line-height:1.15}p{line-height:1.6}</style><main><small>PHILIP FRÈRES</small><h1>Votre espace évolue.</h1><p>Une mise à jour est en cours. Votre espace sera de nouveau disponible dans quelques instants.</p><p>Merci de votre patience.</p></main></html>';
    exit;
}
define('PHILIP_DEPLOYMENT_HEALTHCHECK', true);
unset($deploymentRoot, $deploymentFlag, $deploymentState, $deploymentProbe, $deploymentHealthPath, $deploymentProbeAllowed);
