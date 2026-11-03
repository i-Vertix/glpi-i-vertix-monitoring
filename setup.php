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

use Glpi\Config\ProxyExclusion;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Ivertixmonitoring\Config as PluginConfig;
use GlpiPlugin\Ivertixmonitoring\Host;
use GlpiPlugin\Ivertixmonitoring\Profile as PluginProfile;

define('PLUGIN_IVERTIXMONITORING_VERSION', '12.0.0');

// Minimal GLPI version, inclusive
define('PLUGIN_IVERTIXMONITORING_MIN_GLPI_VERSION', '12.0.0');
// Maximum GLPI version, exclusive
define('PLUGIN_IVERTIXMONITORING_MAX_GLPI_VERSION', '12.0.99');
// Define the plugin directory
define('IVERTIXMONITORING_DIR_PATH', __DIR__);

/**
 * Init hooks of the plugin.
 * REQUIRED
 *
 * @return void
 */
function plugin_init_ivertixmonitoring()
{
    /** @var array $PLUGIN_HOOKS */
    /** @var array $CFG_GLPI */
    global $PLUGIN_HOOKS, $CFG_GLPI;

    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['ivertixmonitoring'] = '../../front/config.form.php?forcetab=' . urlencode(PluginConfig::class . '$1');

    // stored encrypted with the GLPI key
    $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['ivertixmonitoring'] = ['monitoring-password', 'monitoring-api-token'];

    $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['ivertixmonitoring'] = [
        Config::class => [
            PluginConfig::class,
            'prepareConfigUpdate',
        ],
    ];

    // allow admins to bypass the GLPI proxy for requests to the monitoring API (Setup > General > System)
    $CFG_GLPI['possible_proxy_exclusions']?->addExclusion(new ProxyExclusion(
        PluginConfig::class,
        __('i-Vertix Monitoring', 'ivertixmonitoring'),
        __('Requests to the i-Vertix Monitoring API', 'ivertixmonitoring')
    ));

    // status fixer (glpi overwrites status color from status-green ecc)
    if (
        str_contains($_SERVER['REQUEST_URI'] ?? '', Config::getFormURL())
        || str_contains($_SERVER['REQUEST_URI'] ?? '', Computer::getFormURL())
        || str_contains($_SERVER['REQUEST_URI'] ?? '', NetworkEquipment::getFormURL())
    ) {
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['ivertixmonitoring'] = 'css/ivertixmonitoring.css';
    }


    Plugin::registerClass(Host::class, [
        'addtabon' => Host::LINKABLE_ITEMTYPES,
    ]);

    Plugin::registerClass(PluginConfig::class, [
        'addtabon' => ['Config'],
    ]);

    Plugin::registerClass(PluginProfile::class, [
        'addtabon' => [Profile::class],
    ]);
}


/**
 * Get the name and the version of the plugin
 * REQUIRED
 *
 * @return array
 */
function plugin_version_ivertixmonitoring()
{
    return [
        'name' => 'i-Vertix Monitoring',
        'version' => PLUGIN_IVERTIXMONITORING_VERSION,
        'author' => '<a href="https://i-vertix.com">i-Vertix</a>',
        'license' => 'GPLv3',
        'homepage' => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_IVERTIXMONITORING_MIN_GLPI_VERSION,
                'max' => PLUGIN_IVERTIXMONITORING_MAX_GLPI_VERSION,
            ],
        ],
    ];
}
