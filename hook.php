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

use GlpiPlugin\Ivertixmonitoring\Config as PluginConfig;
use GlpiPlugin\Ivertixmonitoring\Host;
use GlpiPlugin\Ivertixmonitoring\Profile as PluginProfile;

/**
 * Plugin install process
 *
 * @return boolean
 */
function plugin_ivertixmonitoring_install($version)
{
    /** @var DBmysql $DB */
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();

    $migration = new Migration(PLUGIN_IVERTIXMONITORING_VERSION);

    $table = Host::getTable();
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
                  `id`              int unsigned primary key not null auto_increment,
                  `itemtype`        varchar(100) not null,
                  `item_id`         int UNSIGNED not null,
                  `monitoring_id`   int UNSIGNED not null,
                  `monitoring_type` varchar(100) default 'host',
                  UNIQUE KEY `unicity` (`itemtype`, `item_id`),
                  KEY `{$table}_index2` (`monitoring_type`, `monitoring_id`)
                 ) ENGINE=InnoDB
                 DEFAULT CHARSET={$default_charset}
                 COLLATE={$default_collation}";
        $DB->doQuery($query);
    } elseif (!isIndex($table, 'unicity')) {
        // 12.0.0: one link per asset; keep the oldest link of duplicates before adding the unique key
        $kept = [];
        foreach ($DB->request(['SELECT' => ['id', 'itemtype', 'item_id'], 'FROM' => $table, 'ORDER' => 'id']) as $row) {
            $key = $row['itemtype'] . '#' . $row['item_id'];
            if (isset($kept[$key])) {
                $DB->delete($table, ['id' => $row['id']]);
            } else {
                $kept[$key] = true;
            }
        }
        $migration->dropKey($table, "{$table}_index1");
        $migration->addKey($table, ['itemtype', 'item_id'], 'unicity', 'UNIQUE');
        $migration->executeMigration();
    }

    PluginProfile::installRights();

    // add missing config values with their defaults (fresh install and upgrade)
    $current_config = Config::getConfigurationValues(PluginConfig::CONTEXT);
    $missing_config = array_diff_key(PluginConfig::DEFAULTS, $current_config);
    if ($missing_config !== []) {
        Config::setConfigurationValues(PluginConfig::CONTEXT, $missing_config);
    }

    return true;
}

/**
 * Plugin uninstall process
 *
 * @return boolean
 */
function plugin_ivertixmonitoring_uninstall()
{
    $tables = [Host::getTable(), ];

    foreach ($tables as $table) {
        $migration = new Migration(PLUGIN_IVERTIXMONITORING_VERSION);
        $migration->displayMessage("Uninstalling $table");
        $migration->dropTable($table);
    }

    $config = new Config();
    $config->deleteByCriteria(['context' => PluginConfig::CONTEXT]);

    PluginProfile::uninstallRights();

    return true;
}

function plugin_ivertixmonitoring_getAddSearchOptionsNew($itemtype)
{
    $sopt = [];

    if (in_array($itemtype, Host::LINKABLE_ITEMTYPES, true) && Host::canView()) {
        $sopt[] = [
            'id'               => 6942,
            'table'            => Host::getTable(),
            'field'            => 'id',
            'name'             => __('i-Vertix Monitoring Host Status', 'ivertixmonitoring'),
            'additionalfields' => ['monitoring_id'],
            'datatype'         => 'specific',
            'nosearch'         => true,
            'nosort'           => true,
            'massiveaction'    => false,
            'joinparams'       => [
                'jointype'                 => 'itemtype_item',
                // the link table uses `item_id` instead of GLPI's usual `items_id`
                'specific_items_id_column' => 'item_id',
            ],
        ];
    }

    return $sopt;
}
