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

use GLPIKey;
use Glpi\Toolbox\HttpClient;
use Safe\Exceptions\JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

use function Safe\json_decode;
use function Safe\json_encode;

/**
 * Client for the i-Vertix Monitoring REST API.
 *
 * Requests are sent with GLPI's HTTP client using the plugin config class as context, so the GLPI proxy settings
 * and the private network restriction (`GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS`) apply.
 */
class ApiClient
{
    /**
     * Authentication data of this instance (token and contact).
     */
    private ?array $auth = null;

    /**
     * Set when the login failed, so the login is not retried for every request of this instance
     * (e.g. one per row in a search list while the API is down).
     */
    private bool $auth_failed = false;

    /**
     * Translated message of the last failed request.
     */
    private ?string $last_error = null;

    /**
     * Whether the last request was blocked because the API URL points to a private network.
     */
    private bool $private_network_blocked = false;

    public function getLastError(): ?string
    {
        return $this->last_error;
    }

    public function isPrivateNetworkBlocked(): bool
    {
        return $this->private_network_blocked;
    }

    /**
     * @return array{id: int, name: string, alias: string}|null
     */
    public function getAuthUser(): ?array
    {
        if (!$this->authenticate()) {
            return null;
        }
        return [
            "id" => $this->auth["contact_id"],
            "name" => $this->auth["contact_name"],
            "alias" => $this->auth["contact_alias"],
        ];
    }

    public function authenticate(): bool
    {
        if (isset($this->auth)) {
            return true;
        }
        if ($this->auth_failed) {
            return false;
        }
        $this->auth_failed = true;
        $config = Config::getConfig();
        if (Config::usesApiToken($config)) {
            return $this->authenticateWithApiToken((string)(new GLPIKey())->decrypt($config['monitoring-api-token']));
        }
        if (empty($config['monitoring-username']) || empty($config['monitoring-password'])) {
            $this->last_error = __('The monitoring API credentials are not configured', 'ivertixmonitoring');
            return false;
        }

        $data = $this->clientRequest('login', [
            'json' => [
                'security' => [
                    'credentials' => [
                        'login' => $config['monitoring-username'],
                        'password' => (new GLPIKey())->decrypt($config['monitoring-password']),
                    ],
                ],
            ],
        ], 'POST');
        if (!$data["success"]) {
            if ($data["status"] === 401) {
                $this->last_error = __('Authentication failed, please check the username and the password', 'ivertixmonitoring');
            }
            return false;
        }
        if (!is_string($data["result"]["security"]["token"] ?? null)) {
            $this->last_error = __('The monitoring API returned an unexpected response', 'ivertixmonitoring');
            return false;
        }
        $contact = $data["result"]['contact'] ?? [];
        $this->auth = [
            "token" => $data["result"]['security']['token'],
            "contact_id" => (int)($contact['id'] ?? 0),
            "contact_name" => (string)($contact['name'] ?? $config['monitoring-username']),
            "contact_alias" => (string)($contact['alias'] ?? $contact['name'] ?? $config['monitoring-username']),
        ];
        $this->auth_failed = false;

        return true;
    }

    /**
     * Use an API token (i-Vertix Monitoring / Centreon 24.10+) instead of a login. The token is sent in the same
     * `X-AUTH-TOKEN` header as a login token; the current user is read to check it and to get the contact name.
     */
    private function authenticateWithApiToken(string $token): bool
    {
        if ($token === '') {
            // e.g. the GLPI key changed since the token was stored
            $this->last_error = __('The stored API token cannot be decrypted, please enter it again', 'ivertixmonitoring');
            return false;
        }
        $data = $this->clientRequest('configuration/users/current/parameters', [
            'headers' => ['X-AUTH-TOKEN' => $token],
        ]);
        if (!$data["success"]) {
            if ($data["status"] === 401 || $data["status"] === 403) {
                $this->last_error = __('Authentication failed, the API token is invalid or expired', 'ivertixmonitoring');
            }
            return false;
        }
        if (!is_array($data["result"])) {
            $this->last_error = __('The monitoring API returned an unexpected response', 'ivertixmonitoring');
            return false;
        }
        $user = $data["result"];
        $this->auth = [
            "token" => $token,
            "contact_id" => (int)($user['id'] ?? 0),
            "contact_name" => (string)($user['name'] ?? $user['alias'] ?? ''),
            "contact_alias" => (string)($user['alias'] ?? $user['name'] ?? ''),
        ];
        $this->auth_failed = false;

        return true;
    }

    /**
     * Send a request to the monitoring API. Never throws: failures are returned with `success` = false
     * and described by getLastError().
     *
     * @param string $endpoint endpoint relative to the configured API URL
     * @param array $options options of the Symfony HTTP client (`query`, `json`, ...)
     * @param string $method HTTP method
     * @return array{success: bool, status: int|null, result: mixed}
     */
    public function clientRequest(string $endpoint = '', array $options = [], string $method = 'GET'): array
    {
        $this->last_error = null;
        $this->private_network_blocked = false;

        $config = Config::getConfig();
        if (empty($config["monitoring-url"])) {
            $this->last_error = __('The monitoring API URL is not configured', 'ivertixmonitoring');
            return ["success" => false, "status" => null, "result" => null];
        }
        $verify_tls = ($config['monitoring-verify-tls'] ?? '1') === '1';

        $client = new HttpClient(Config::class, [
            'base_uri' => $config['monitoring-url'],
            'verify_peer' => $verify_tls,
            'verify_host' => $verify_tls,
            'timeout' => 10,
            'max_duration' => 30,
            // never follow redirects: they would send the token (and the password on login) to another host
            'max_redirects' => 0,
        ]);
        $options['headers'] = ['Accept' => 'application/json'] + ($options['headers'] ?? []);
        if ($this->auth !== null) {
            $options['headers']['X-AUTH-TOKEN'] = $this->auth["token"];
        }

        try {
            $response = $client->request($method, $endpoint, $options);
            $body = $response->getContent();
            return [
                "success" => true,
                "status" => $response->getStatusCode(),
                "result" => $body !== "" ? json_decode($body, true) : null,
            ];
        } catch (HttpExceptionInterface $e) {
            $status = $e->getResponse()->getStatusCode();
            $this->last_error = sprintf(
                __('The monitoring API answered with HTTP status %d', 'ivertixmonitoring'),
                $status
            );
            return ["success" => false, "status" => $status, "result" => $e->getMessage()];
        } catch (TransportExceptionInterface $e) {
            // thrown by Symfony's NoPrivateNetworkHttpClient
            if (str_contains($e->getMessage(), ' is blocked for ')) {
                $this->private_network_blocked = true;
                $this->last_error = __('The monitoring API URL points to a private network, which GLPI blocks by default', 'ivertixmonitoring');
            } else {
                $this->last_error = sprintf(
                    __('The monitoring API is not reachable: %s', 'ivertixmonitoring'),
                    $e->getMessage()
                );
            }
            return ["success" => false, "status" => null, "result" => $e->getMessage()];
        } catch (ExceptionInterface | JsonException $e) {
            $this->last_error = __('The monitoring API returned an unexpected response', 'ivertixmonitoring');
            return ["success" => false, "status" => null, "result" => $e->getMessage()];
        }
    }

    public function getHosts(?array $query = null): array
    {
        $options = [];
        if (isset($query)) {
            $options["query"] = $query;
        }
        return $this->clientRequest('monitoring/hosts', $options);
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

    public function getServicesList(array $options = []): array
    {
        return $this->clientRequest('monitoring/services', $options);
    }

    public function getServicesByHostId(int $hostId, int $limit = 30): array
    {
        return $this->clientRequest('monitoring/hosts/' . $hostId . '/services', [
            'query' => ['limit' => $limit],
        ]);
    }

    public function sendHostCheck(int $hostId, bool $isForced = true): array
    {
        return $this->clientRequest('monitoring/hosts/' . $hostId . '/check', [
            'json' => ['is_forced' => $isForced],
        ], 'POST');
    }

    public function setHostDowntime(int $hostId, array $downtime): array
    {
        return $this->clientRequest('monitoring/hosts/' . $hostId . '/downtimes', ['json' => $downtime], 'POST');
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
        return $this->clientRequest('monitoring/services/downtimes', [
            'query' => [
                'search' => json_encode([
                    'host.id' => [
                        '$eq' => $hostId,
                    ],
                ]),
                'limit' => $limit,
                'page' => 1,
            ],
        ]);
    }

    public function cancelDowntimeById(int $downtimeId): array
    {
        return $this->clientRequest('monitoring/downtimes/' . $downtimeId, [], 'DELETE');
    }

    public function acknowledgeHostById(int $hostId, array $acknowledge): array
    {
        return $this->clientRequest('monitoring/hosts/' . $hostId . '/acknowledgements', ['json' => $acknowledge], 'POST');
    }

    /**
     * Update fields of a host in the monitoring configuration (e.g. `note_url`).
     */
    public function updateHost(int $hostId, array $hostFields): array
    {
        return $this->clientRequest('configuration/hosts/' . $hostId, ['json' => $hostFields], 'PATCH');
    }
}
