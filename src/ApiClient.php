<?php

/**
 * -------------------------------------------------------------------------
 * Centreon/i-Vertix Monitoring plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Centreon/i-Vertix Monitoring.
 *
 * Centreon/i-Vertix Monitoring is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * Centreon/i-Vertix Monitoring is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Centreon/i-Vertix Monitoring. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2022-2023 by Centreon plugin team.
 * @copyright Copyright (C) 2025 by i-Vertix Monitoring plugin team.
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/pluginsGLPI/centreon
 * @link      https://github.com/i-Vertix/glpi-i-vertix-monitoring
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Ivertixmonitoring;

use GuzzleHttp\Client;
use GLPIKey;
use GlpiPlugin\Ivertixmonitoring\Config;

class ApiClient
{
    public ?array $auth = null;
    public array $api_config = [];

    public function monitoringConfig()
    {
        $api_i = new Config();
        $this->api_config = $api_i::getConfig();
    }

    public function getAuthUser(): ?array
    {
        $connected = $this->authenticate();
        if (!$connected) return null;
        return ["id" => $this->auth["contact_id"], "name" => $this->auth["contact_name"]];
    }

    public function authenticate(array $params = [])
    {
        if (isset($this->auth)) return true;
        $this->monitoringConfig();

        $defaults = [
            'json' => [
                'security' => [
                    'credentials' => [
                        'login' => $this->api_config['monitoring-username'],
                        'password' => (new GLPIKey())->decrypt($this->api_config['monitoring-password']),
                    ],
                ],
            ],
        ];
        $params = array_replace_recursive($defaults, $params);

        try {
            $data = $this->clientRequest('login', $params, 'POST');
        } catch (\Exception $e) {
            if (isset($params['throw'])) {
                throw $e;
            }

            return $e->getMessage();
        }
        $this->auth = [
            "token" => $data['security']['token'],
            "contact_id" => $data['contact']['id'],
            "contact_name" => $data['contact']['name'],
        ];

        return true;
    }

    public function diagnostic()
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

    public function clientRequest(string $endpoint = '', array $params = [], string $method = 'GET')
    {
        $this->monitoringConfig();
        if (empty($this->api_config["monitoring-url"])) return null;
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
            $data = $api_client->request($method, $endpoint, $params);
        } catch (\Exception $e) {
            if (isset($params['throw'])) {
                throw $e;
            }
            return $e->getMessage();
        }
        $data_body = $data->getBody();
        return json_decode($data_body, true);
    }

    public function getHosts(array $params = [])
    {
        $defaults = [];
        $params = array_replace_recursive($defaults, $params);
        return $this->clientRequest('monitoring/hosts', $params);
    }

    public function getHostById(int $host_id, array $params = []): array
    {
        return $this->clientRequest('monitoring/hosts/' . $host_id, $params);
    }

    public function getHostResourceDetailById(int $host_id, array $params = []): array
    {
        return $this->clientRequest('monitoring/resources/hosts/' . $host_id, $params);
    }

    public function getHostTimelineById(int $host_id, array $params = []): array
    {
        return $this->clientRequest('monitoring/hosts/' . $host_id . '/timeline', $params);
    }

    public function getServicesList(array $params = []): array
    {
        return $this->clientRequest('monitoring/services', $params);
    }

    public function getServicesByHostId(int $host_id, array $params = [])
    {
        $params['query'] = ['limit' => 30];
        return $this->clientRequest('monitoring/hosts/' . $host_id . '/services', $params);
    }

    public function sendHostCheck(int $host_id, array $params = [])
    {
        $params['json']['is_forced'] = true;
        return $this->clientRequest('monitoring/hosts/' . $host_id . '/check', $params['json'], 'POST');
    }

    public function setHostDowntime(int $host_id, array $params)
    {
        return $this->clientRequest('monitoring/hosts/' . $host_id . '/downtimes', $params, 'POST');
    }

    public function getHostDowntimes(int $host_id, array $params = [])
    {
        return $this->clientRequest('monitoring/hosts/' . $host_id . '/downtimes', $params);
    }

    public function getDowntimeById(int $downtime_id): array
    {
        return $this->clientRequest('monitoring/downtimes/' . $downtime_id);
    }

    public function getServiceDowntimesByHostId(int $host_id, array $params = [])
    {
        $defaultParams = [
            'query' => [
                'search' => json_encode([
                    'host.id' => [
                        '$eq' => $host_id,
                    ],
                ]),
            ],
        ];

        $queryParams = array_merge($defaultParams, $params);

        return $this->clientRequest('monitoring/services/downtimes', $queryParams);
    }

    public function cancelDowntimeById(int $downtime_id, array $params = [])
    {
        return $this->clientRequest('monitoring/downtimes/' . $downtime_id, $params, 'DELETE');
    }

    public function acknowledgeHostById(int $host_id, array $request = [])
    {
        return $this->clientRequest('monitoring/hosts/' . $host_id . 'acknowledgements', $request, 'POST');
    }

    public function updateHost(int $host_id, array $request = [])
    {
        return $this->clientRequest('configuration/hosts/' . $host_id, $request, 'PATCH');
    }
}
