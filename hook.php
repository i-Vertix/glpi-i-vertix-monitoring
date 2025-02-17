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

use GlpiPlugin\Ivertixmonitoring\Host;

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

    $table = GlpiPlugin\Ivertixmonitoring\Host::getTable();
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
                  `id`              int unsigned primary key not null auto_increment,
                  `itemtype`        varchar(100) not null,
                  `item_id`         int UNSIGNED not null,
                  `monitoring_id`   int UNSIGNED not null,
                  `monitoring_type` varchar(100) default 'host'
                 ) ENGINE=InnoDB
                 DEFAULT CHARSET={$default_charset}
                 COLLATE={$default_collation}";
        $DB->doQuery($query);
        $DB->doQuery("CREATE INDEX {$table}_index1 ON `$table` (itemtype, item_id);");
        $DB->doQuery("CREATE INDEX {$table}_index2 ON `$table` (monitoring_type, monitoring_id);");
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
    /** @var DBmysql $DB */
    global $DB;

    $tables = [GlpiPlugin\Ivertixmonitoring\Host::getTable(), ];

    foreach ($tables as $table) {
        $migration = new Migration(PLUGIN_IVERTIXMONITORING_VERSION);
        $migration->displayMessage("Uninstalling $table");
        $migration->dropTable($table);
        $DB->error();
    }

    $config = new \Config();
    $config->deleteByCriteria(['context' => 'plugin:ivertixmonitoring']);

    return true;
}

function plugin_ivertixmonitoring_getAddSearchOptionsNew($itemtype)
{
    $sopt = [];

    if ($itemtype === 'Computer') {
        $sopt[] = [
            'id'               => 6942,
            'table'            => GlpiPlugin\Ivertixmonitoring\Host::getTable(),
            'field'            => 'id',
            'name'             => __('i-Vertix Monitoring Host Status', 'ivertixmonitoring'),
            'additionalfields' => ['monitoring_id'],
            'datatype'         => 'specific',
            'nosearch'         => true,
            'nosort'           => true,
            'massiveaction'    => false,
            'joinparams'       => [
                'jointype' => 'itemtype_item',
            ],
        ];
    }

    return $sopt;
}
