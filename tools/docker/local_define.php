<?php

// Dev environment only: the mock API runs in the private docker network, which GLPI blocks by default.
define('GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS', [
    \Agent::class,
    \Auth::class,
    \GlpiPlugin\Ivertixmonitoring\Config::class,
]);
