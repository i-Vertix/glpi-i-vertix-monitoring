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

    /**
     * Config values that must be set to connect to the monitoring API.
     */
    private const REQUIRED = ['monitoring-url', 'monitoring-username', 'monitoring-password'];

    public static function getTypeName($nb = 0)
    {
        return __('i-Vertix Monitoring settings', 'ivertixmonitoring');
    }

    public static function getConfig(): array
    {
        return Glpi_Config::getConfigurationValues(self::CONTEXT) + self::DEFAULTS;
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

        $missing = array_filter(self::REQUIRED, static fn($key) => empty($current_config[$key]));
        if (count($missing) === count(self::REQUIRED)) {
            // nothing configured yet
            return true;
        }
        if ($missing !== []) {
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
        // keep the stored password when the field is left empty
        if (($item->input['monitoring-password'] ?? null) === '') {
            unset($item->input['monitoring-password']);
        }
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
}
