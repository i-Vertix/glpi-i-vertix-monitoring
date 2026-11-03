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

use CommonDBTM;
use CommonGLPI;
use Config as Glpi_Config;
use Glpi\Application\View\TemplateRenderer;
use Session;

class Config extends Glpi_Config
{
    public const CONTEXT = 'plugin:ivertixmonitoring';

    /**
     * Config values set on install/upgrade when missing.
     * URL and credentials have no default: they must be entered by an admin.
     */
    public const DEFAULTS = [
        'monitoring-sync' => '0',
        'monitoring-verify-tls' => '1',
    ];

    public static function getTypeName($nb = 0)
    {
        return __('i-Vertix Monitoring settings', 'ivertixmonitoring');
    }

    public static function getConfig(): array
    {
        return Glpi_Config::getConfigurationValues(self::CONTEXT) + self::DEFAULTS;
    }

    /**
     * Whether an API token is configured (then username and password are empty, see prepareAuthenticationUpdate()).
     */
    public static function usesApiToken(array $config): bool
    {
        return !empty($config['monitoring-api-token']);
    }

    /**
     * Whether everything needed to connect is configured: the URL and either an API token or username and password.
     */
    public static function isComplete(array $config): bool
    {
        return !empty($config['monitoring-url'])
            && (self::usesApiToken($config) || (!empty($config['monitoring-username']) && !empty($config['monitoring-password'])));
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Glpi_Config) {
            return self::createTabEntry(
                self::getTypeName(),
                0,
                $item::getType(),
                'ti ti-heart-rate-monitor'
            );
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Glpi_Config) {
            return self::showForConfig($item, $withtemplate);
        }

        return true;
    }

    public static function showForConfig(Glpi_Config $config, $withtemplate = 0): bool
    {
        if (!self::canUpdate()) {
            return false;
        }

        $current_config = self::getConfig();
        $canedit = Session::haveRight(self::$rightname, UPDATE);

        TemplateRenderer::getInstance()->display('@ivertixmonitoring/config.html.twig', [
            'item' => $config,
            'current_config' => $current_config,
            'can_edit' => $canedit,
        ]);

        $configured = array_filter(
            ['monitoring-url', 'monitoring-username', 'monitoring-password', 'monitoring-api-token'],
            static fn($key) => !empty($current_config[$key])
        );
        if ($configured === []) {
            // nothing configured yet
            return true;
        }
        if (!self::isComplete($current_config)) {
            TemplateRenderer::getInstance()->display('@ivertixmonitoring/checkField.html.twig');
            return true;
        }

        $api = new ApiClient();
        $connected = $api->authenticate();

        TemplateRenderer::getInstance()->display('@ivertixmonitoring/diagnostic.html.twig', [
            'connected' => $connected,
            'error' => $api->getLastError(),
            'private_network_blocked' => $api->isPrivateNetworkBlocked(),
            'unencrypted' => str_starts_with(strtolower($current_config['monitoring-url']), 'http://'),
            'context_class' => self::class,
            'uses_api_token' => self::usesApiToken($current_config),
        ]);

        if ($connected && $canedit) {
            TemplateRenderer::getInstance()->display('@ivertixmonitoring/syncAll.html.twig', [
                'current_config' => $current_config,
            ]);
        }
        return true;
    }

    /**
     * Normalize the plugin settings before they are saved (hooked on `Config` PRE_ITEM_UPDATE).
     */
    public static function prepareConfigUpdate(CommonDBTM $item): void
    {
        if (!is_array($item->input) || ($item->input['config_context'] ?? null) !== self::CONTEXT) {
            return;
        }
        self::prepareAuthenticationUpdate($item);
        if (isset($item->input['monitoring-url'])) {
            $url = trim((string)$item->input['monitoring-url']);
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            if ($url !== '' && (!in_array($scheme, ['http', 'https'], true) || parse_url($url, PHP_URL_HOST) === null)) {
                // keep the stored URL
                unset($item->input['monitoring-url']);
                Session::addMessageAfterRedirect(
                    htmlescape(__('The monitoring API URL must be a valid http(s) URL', 'ivertixmonitoring')),
                    false,
                    ERROR
                );
                return;
            }
            if ($url !== '' && !str_ends_with($url, '/')) {
                $url .= '/';
            }
            $item->input['monitoring-url'] = $url;
        }
    }

    /**
     * Store either an API token or username and password, never both.
     *
     * Empty secret fields keep the stored value. A new token removes username and password, a new password removes
     * the token. Entering both is refused and keeps the stored authentication.
     */
    private static function prepareAuthenticationUpdate(CommonDBTM $item): void
    {
        $token = trim((string)($item->input['monitoring-api-token'] ?? ''));
        $password = (string)($item->input['monitoring-password'] ?? '');
        $username = trim((string)($item->input['monitoring-username'] ?? ''));
        // empty secret fields keep the stored values
        unset($item->input['monitoring-api-token'], $item->input['monitoring-password']);

        $stored = self::getConfig();
        $error = null;
        if ($token !== '' && $password !== '') {
            $error = __('Enter either an API token or username and password, not both', 'ivertixmonitoring');
        } elseif ($token !== '') {
            $item->input['monitoring-api-token'] = $token;
            $item->input['monitoring-username'] = '';
            $item->input['monitoring-password'] = '';
            return;
        } elseif ($password !== '') {
            if ($username === '') {
                $error = __('Enter the username together with the password', 'ivertixmonitoring');
            } else {
                $item->input['monitoring-username'] = $username;
                $item->input['monitoring-password'] = $password;
                $item->input['monitoring-api-token'] = '';
                return;
            }
        } elseif ($username !== '' && self::usesApiToken($stored)) {
            // switching from the token to username and password needs the password
            $error = __('Enter the password to use username and password instead of the API token', 'ivertixmonitoring');
        } else {
            // nothing new: only the username may change (credentials mode)
            if (array_key_exists('monitoring-username', $item->input)) {
                $item->input['monitoring-username'] = $username;
            }
            return;
        }

        // keep the stored authentication
        unset($item->input['monitoring-username']);
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }
}
