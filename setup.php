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

define('PLUGIN_IVERTIXMONITORING_VERSION', '10.0.0');

// Minimal GLPI version, inclusive
define('PLUGIN_IVERTIXMONITORING_MIN_GLPI_VERSION', '10.0.0');
// Maximum GLPI version, exclusive
define('PLUGIN_IVERTIXMONITORING_MAX_GLPI_VERSION', '10.0.99');
// Define the plugin directory
define('IVERTIXMONITORING_DIR_PATH', __DIR__);

use Glpi\Plugin\Hooks;


/**
 * Init hooks of the plugin.
 * REQUIRED
 *
 * @return void
 */
function plugin_init_ivertixmonitoring()
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['ivertixmonitoring'] = true;

    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['ivertixmonitoring'] = '../../front/config.form.php';

    $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['ivertixmonitoring'] = ['monitoring-password'];

    $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['ivertixmonitoring'] = [
        \Config::class => [
            GlpiPlugin\Ivertixmonitoring\Config::class,
            'prepareConfigUpdate',
        ],
    ];


    Plugin::registerClass(GlpiPlugin\Ivertixmonitoring\Host::class, [
        'addtabon' => ['Computer', 'NetworkEquipment'],
    ]);

    Plugin::registerClass(GlpiPlugin\Ivertixmonitoring\Config::class, [
        'addtabon' => ['Config'],
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
        'name'         => 'i-Vertix Monitoring',
        'version'      => PLUGIN_IVERTIXMONITORING_VERSION,
        'author'       => '<a href="https://i-vertix.com">i-Vertix</a>, <a href="https://www.teclib.com">Teclib\'</a>',
        'license'      => 'GPLv3',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_IVERTIXMONITORING_MIN_GLPI_VERSION,
                'max' => PLUGIN_IVERTIXMONITORING_MAX_GLPI_VERSION,
            ],
        ],
    ];
}
