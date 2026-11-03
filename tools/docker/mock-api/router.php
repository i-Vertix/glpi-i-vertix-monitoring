<?php

/**
 * Minimal fake of the i-Vertix Monitoring (Centreon) REST API, for the dev environment only.
 * Base URL: http://mock-api:8080/centreon/api/latest/  -  login: admin / admin, or API token: mock-api-token
 * State (downtimes, acknowledgements, note URLs, forced checks) is kept in /tmp/mock-api-state.json.
 */

const TOKEN = 'mock-token';
// API token (Centreon 24.10+), sent in the same X-AUTH-TOKEN header as the login token
const API_TOKEN = 'mock-api-token';
const STATE_FILE = '/tmp/mock-api-state.json';

$hosts = [
    1 => ['id' => 1, 'name' => 'glpi-srv-01', 'alias' => 'GLPI server', 'fqdn' => '10.0.0.11', 'status' => 'UP'],
    2 => ['id' => 2, 'name' => 'core-switch-01', 'alias' => 'Core switch', 'fqdn' => '10.0.0.2', 'status' => 'DOWN'],
    3 => ['id' => 3, 'name' => 'mail-srv-01', 'alias' => "Mail server \"O'Neil\"", 'fqdn' => '10.0.0.25', 'status' => 'UNREACHABLE'],
];
for ($i = 4; $i <= 40; $i++) {
    $hosts[$i] = ['id' => $i, 'name' => sprintf('host-%02d', $i), 'alias' => "Host $i", 'fqdn' => "10.0.1.$i", 'status' => 'UP'];
}

$state = is_file(STATE_FILE) ? json_decode(file_get_contents(STATE_FILE), true) : [];
$state += ['downtimes' => [], 'acks' => [], 'notes' => [], 'checks' => [], 'next_id' => 100];

function respond(int $code, $body = null): void
{
    global $state;
    file_put_contents(STATE_FILE, json_encode($state));
    http_response_code($code);
    if ($body !== null) {
        header('Content-Type: application/json');
        echo json_encode($body);
    }
    error_log(sprintf('%s %s -> %d', $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $code));
}

function listing(array $items): array
{
    $limit = (int)($_GET['limit'] ?? 10);
    $page = max(1, (int)($_GET['page'] ?? 1));
    return [
        'result' => array_values(array_slice($items, ($page - 1) * $limit, $limit)),
        'meta' => ['page' => $page, 'limit' => $limit, 'total' => count($items)],
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$prefix = '/centreon/api/latest/';
if (!str_starts_with($path, $prefix)) {
    respond(404, ['message' => 'unknown path']);
    return true;
}
$path = substr($path, strlen($prefix));
$body = json_decode(file_get_contents('php://input') ?: 'null', true);

if ($method === 'POST' && $path === 'login') {
    $credentials = $body['security']['credentials'] ?? [];
    if (($credentials['login'] ?? null) === 'admin' && ($credentials['password'] ?? null) === 'admin') {
        respond(200, [
            'contact' => ['id' => 1, 'name' => 'admin', 'alias' => 'Admin "Mock"', 'email' => 'admin@example.org', 'is_admin' => true],
            'security' => ['token' => TOKEN],
        ]);
    } else {
        respond(401, ['code' => 401, 'message' => 'Invalid credentials']);
    }
    return true;
}

if (!in_array($_SERVER['HTTP_X_AUTH_TOKEN'] ?? null, [TOKEN, API_TOKEN], true)) {
    respond(401, ['code' => 401, 'message' => 'Invalid token']);
    return true;
}

$now = new DateTimeImmutable();
$m = [];

switch (true) {
    case $method === 'GET' && $path === 'configuration/users/current/parameters':
        respond(200, [
            'id' => 2,
            'name' => 'GLPI integration',
            'alias' => 'glpi-api',
            'email' => 'glpi@example.org',
            'timezone' => 'Europe/Rome',
            'locale' => 'en_US',
            'is_admin' => false,
            'can_manage_api_tokens' => false,
        ]);
        break;

    case $method === 'GET' && $path === 'monitoring/hosts':
        $list = $hosts;
        $search = json_decode($_GET['search'] ?? 'null', true);
        if (isset($search['host.name']['$lk'])) {
            $needle = trim($search['host.name']['$lk'], '%');
            $list = array_filter($list, static fn($h) => stripos($h['name'], $needle) !== false);
        } elseif (isset($search['host.name']['$eq'])) {
            $list = array_filter($list, static fn($h) => $h['name'] === $search['host.name']['$eq']);
        }
        respond(200, listing(array_map(static fn($h) => ['id' => $h['id'], 'name' => $h['name']], $list)));
        break;

    case $method === 'GET' && preg_match('#^monitoring/hosts/(\d+)$#', $path, $m) === 1:
        $h = $hosts[(int)$m[1]] ?? null;
        if ($h === null) {
            respond(404, ['code' => 404, 'message' => 'Host not found']);
            break;
        }
        respond(200, [
            'id' => $h['id'],
            'name' => $h['name'],
            'alias' => $h['alias'],
            'last_check' => $now->modify('-3 minutes')->format(DATE_ATOM),
            'next_check' => $now->modify('+2 minutes')->format(DATE_ATOM),
            'check_period' => '24x7',
        ]);
        break;

    case $method === 'GET' && preg_match('#^monitoring/resources/hosts/(\d+)$#', $path, $m) === 1:
        $h = $hosts[(int)$m[1]] ?? null;
        if ($h === null) {
            respond(404, ['code' => 404, 'message' => 'Host not found']);
            break;
        }
        $downtimes = array_values(array_filter($state['downtimes'], static fn($d) => $d['host_id'] === $h['id'] && $d['service_id'] === null));
        $ack = $state['acks'][$h['id']] ?? null;
        respond(200, [
            'id' => $h['id'],
            'name' => $h['name'],
            'fqdn' => $h['fqdn'],
            'status' => ['code' => 0, 'name' => $h['status'], 'severity_code' => 5],
            'in_downtime' => $downtimes !== [],
            'downtimes' => $downtimes,
            'acknowledgement' => $ack,
        ]);
        break;

    case $method === 'GET' && preg_match('#^monitoring/hosts/(\d+)/services$#', $path, $m) === 1:
        $services = [
            ['id' => 11, 'display_name' => 'Ping', 'state' => 0, 'status' => ['name' => 'OK'], 'output' => 'OK - rta 0.4ms, lost 0%', 'duration' => '3d 2h'],
            ['id' => 12, 'display_name' => 'Disk /', 'state' => 1, 'status' => ['name' => 'WARNING'], 'output' => 'WARNING - 85% used', 'duration' => '2h 5m'],
            ['id' => 13, 'display_name' => 'Memory', 'state' => 2, 'status' => ['name' => 'CRITICAL'], 'output' => 'CRITICAL - 97% used', 'duration' => '12m'],
            ['id' => 14, 'display_name' => 'NTP', 'state' => 3, 'status' => ['name' => 'UNKNOWN'], 'output' => 'UNKNOWN - <script>alert(1)</script>', 'duration' => '1d'],
        ];
        respond(200, listing($services));
        break;

    case $method === 'GET' && preg_match('#^monitoring/hosts/(\d+)/timeline$#', $path, $m) === 1:
        $events = [];
        foreach ([1 => 'UP', 5 => 'DOWN', 30 => 'UP', 200 => 'UNREACHABLE', 600 => 'UP'] as $hoursAgo => $status) {
            $events[] = [
                'id' => $hoursAgo,
                'type' => 'event',
                'date' => $now->modify("-$hoursAgo hours")->format(DATE_ATOM),
                'content' => "Host is $status",
                'status' => ['name' => $status],
                'tries' => 1,
            ];
        }
        $events[] = ['id' => 999, 'type' => 'comment', 'date' => $now->modify('-2 hours')->format(DATE_ATOM), 'content' => 'A comment'];
        respond(200, ['result' => $events, 'meta' => ['total' => count($events)]]);
        break;

    case $method === 'POST' && preg_match('#^monitoring/hosts/(\d+)/check$#', $path, $m) === 1:
        $state['checks'][] = ['host_id' => (int)$m[1], 'is_forced' => $body['is_forced'] ?? null];
        respond(204);
        break;

    case $method === 'POST' && preg_match('#^monitoring/hosts/(\d+)/downtimes$#', $path, $m) === 1:
        $hostId = (int)$m[1];
        $ids = [null];
        if (!empty($body['with_services'])) {
            $ids = [null, 11, 12, 13, 14];
        }
        foreach ($ids as $serviceId) {
            $id = $state['next_id']++;
            $state['downtimes'][$id] = [
                'id' => $id,
                'host_id' => $hostId,
                'service_id' => $serviceId,
                'start_time' => $body['start_time'],
                'end_time' => $body['end_time'],
                'is_fixed' => $body['is_fixed'],
                'duration' => $body['duration'],
                'author_name' => 'admin',
                'comment' => $body['comment'],
            ];
        }
        respond(204);
        break;

    case $method === 'GET' && preg_match('#^monitoring/downtimes/(\d+)$#', $path, $m) === 1:
        $d = $state['downtimes'][(int)$m[1]] ?? null;
        $d === null ? respond(404, ['code' => 404, 'message' => 'Downtime not found']) : respond(200, $d);
        break;

    case $method === 'DELETE' && preg_match('#^monitoring/downtimes/(\d+)$#', $path, $m) === 1:
        unset($state['downtimes'][(int)$m[1]]);
        respond(204);
        break;

    case $method === 'GET' && $path === 'monitoring/services/downtimes':
        $search = json_decode($_GET['search'] ?? 'null', true);
        $hostId = $search['host.id']['$eq'] ?? null;
        respond(200, listing(array_filter($state['downtimes'], static fn($d) => $d['service_id'] !== null && $d['host_id'] === $hostId)));
        break;

    case $method === 'POST' && preg_match('#^monitoring/hosts/(\d+)/acknowledgements$#', $path, $m) === 1:
        $state['acks'][(int)$m[1]] = [
            'author_name' => 'admin',
            'entry_time' => $now->format(DATE_ATOM),
            'comment' => $body['comment'] ?? '',
        ];
        respond(204);
        break;

    case $method === 'PATCH' && preg_match('#^configuration/hosts/(\d+)$#', $path, $m) === 1:
        $state['notes'][(int)$m[1]] = $body['note_url'] ?? null;
        respond(204);
        break;

    default:
        respond(404, ['code' => 404, 'message' => "No route for $method $path"]);
}

return true;
