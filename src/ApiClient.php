<?php

/**
 * -------------------------------------------------------------------------
 * i-Vertix Monitoring plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of i-Vertix Monitoring plugin for GLPI.
 *
 * "i-Vertix Monitoring plugin for GLPI" is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * "i-Vertix Monitoring plugin for GLPI" is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with "i-Vertix Monitoring plugin for GLPI". If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2025 by i-Vertix/PGUM.
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/i-Vertix/glpi-i-vertix-monitoring
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Ivertixmonitoring;

use GuzzleHttp\Client;
use GLPIKey;

class ApiClient
{
    public ?array $auth = null;
    public array $api_config = [];

    public function monitoringConfig(): void
    {
        $api_i = new Config();
        $this->api_config = $api_i::getConfig();
    }

    public function getAuthUser(): ?array
    {
        $connected = $this->authenticate();
        if (!$connected) {
            return null;
        }
        return [
            "id" => $this->auth["contact_id"],
            "name" => $this->auth["contact_name"],
            "alias" => $this->auth["contact_alias"]
        ];
    }

    public function authenticate(): bool
    {
        if (isset($this->auth)) {
            return true;
        }
        $this->monitoringConfig();

        $params = [
            'json' => [
                'security' => [
                    'credentials' => [
                        'login' => $this->api_config['monitoring-username'],
                        'password' => (new GLPIKey())->decrypt($this->api_config['monitoring-password']),
                    ],
                ],
            ],
        ];

        $data = $this->clientRequest('login', $params, 'POST');
        if (!$data["success"] || !isset($data["result"]["security"]["token"])) {
            return false;
        }
        $this->auth = [
            "token" => $data["result"]['security']['token'],
            "contact_id" => $data["result"]['contact']['id'],
            "contact_name" => $data["result"]['contact']['name'],
            "contact_alias" => $data["result"]['contact']['alias'],
        ];

        return true;
    }

    public function diagnostic(): array
    {
        $test = $this->authenticate();

        if ($test === true) {
            $result = [
                'result' => true,
                'message' => 'You are connected to the i-Vertix Monitoring API!',
            ];
        } else {
            $result = [
                'result' => false,
                'message' => $test,
            ];
        }

        return $result;
    }

    public function clientRequest(string $endpoint = '', array $params = [], string $method = 'GET'): array
    {
        $this->monitoringConfig();
        if (empty($this->api_config["monitoring-url"])) {
            return ["success" => false, "result" => "not authenticated"];
        }
        $api_client = new Client([
            'base_uri' => $this->api_config['monitoring-url'],
            'verify' => false,
            'connect_timeout' => 3,
            'timeout' => 10,
        ]);
        $params['headers'] = ['Content-Type' => 'application/json'];

        if ($this->auth !== null) {
            $params['headers'] = ['Content-Type' => 'application/json', 'X-AUTH-TOKEN' => $this->auth["token"]];
        }

        try {
            $result = $api_client->request($method, $endpoint, $params);
        } catch (\Exception $e) {
            return ["success" => false, "result" => $e->getMessage()];
        }
        $code = $result->getStatusCode();
        return ["success" => $code >= 200 && $code < 300, "result" => json_decode($result->getBody(), true)];
    }

    public function getHosts(array $query = null): array
    {
        $params = [];
        if (isset($query)) {
            $params["query"] = $query;
        }
        return $this->clientRequest('monitoring/hosts', $params);
    }

    public function getHostById(int $hostId): array
    {
        return $this->clientRequest('monitoring/hosts/' . $hostId);
    }

    public function getHostResourceDetailById(int $hostId): array
    {
        return $this->clientRequest('monitoring/resources/hosts/' . $hostId);
    }

    public function getHostTimelineById(int $hostId): array
    {
        return $this->clientRequest('monitoring/hosts/' . $hostId . '/timeline');
    }

    public function getServicesList(array $params = []): array
    {
        return $this->clientRequest('monitoring/services', $params);
    }

    public function getServicesByHostId(int $hostId, int $limit = 30): array
    {
        $params['query'] = ['limit' => $limit];
        return $this->clientRequest('monitoring/hosts/' . $hostId . '/services', $params);
    }

    public function sendHostCheck(int $hostId, bool $isForced = true): array
    {
        $params['json']['is_forced'] = $isForced;
        return $this->clientRequest('monitoring/hosts/' . $hostId . '/check', $params, 'POST');
    }

    public function setHostDowntime(int $hostId, array $downtime): array
    {
        $params["json"] = $downtime;
        return $this->clientRequest('monitoring/hosts/' . $hostId . '/downtimes', $params, 'POST');
    }

    public function getHostDowntimes(int $hostId): array
    {
        return $this->clientRequest('monitoring/hosts/' . $hostId . '/downtimes');
    }

    public function getDowntimeById(int $downtimeId): array
    {
        return $this->clientRequest('monitoring/downtimes/' . $downtimeId);
    }

    public function getServiceDowntimesByHostId(int $hostId, int $limit = 30): array
    {
        $params = [
            'query' => [
                'search' => json_encode([
                    'host.id' => [
                        '$eq' => $hostId,
                    ],
                ]),
                'limit' => $limit,
                'page' => 1,
            ],
        ];

        return $this->clientRequest('monitoring/services/downtimes', $params);
    }

    public function cancelDowntimeById(int $downtimeId): array
    {
        return $this->clientRequest('monitoring/downtimes/' . $downtimeId, [], 'DELETE');
    }

    public function acknowledgeHostById(int $hostId, array $acknowledge): array
    {
        $params["json"] = $acknowledge;
        return $this->clientRequest('monitoring/hosts/' . $hostId . '/acknowledgements', $params, 'POST');
    }

    public function updateHost(int $hostId, array $hostFields): array
    {
        $params["json"] = $hostFields;
        return $this->clientRequest('configuration/hosts/' . $hostId, $hostFields, 'PATCH');
    }
}
