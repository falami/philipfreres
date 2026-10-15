<?php

use App\Kernel;

require dirname(__DIR__).'/scripts/deploy-maintenance.php';

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    if (defined('PHILIP_DEPLOYMENT_HEALTHCHECK') && PHILIP_DEPLOYMENT_HEALTHCHECK === true) {
        header('X-Deployment-Environment: '.($context['APP_ENV'] ?? 'unknown'));
        header('X-Deployment-Debug: '.(!empty($context['APP_DEBUG']) ? '1' : '0'));
    }
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
