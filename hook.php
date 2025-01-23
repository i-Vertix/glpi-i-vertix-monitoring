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

    $table = Host::getTable();
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
                  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `itemtype`        VARCHAR(100) NOT NULL,
                  `item_id`         INT(10) UNSIGNED NOT NULL DEFAULT '0',
                  `monitoring_id`   INT(10) NOT NULL,
                  `monitoring_type` VARCHAR(100) DEFAULT 'host',
                  PRIMARY KEY  (`id`),
                  KEY `item_id` (`item_id`)
                 ) ENGINE=InnoDB
                 DEFAULT CHARSET={$default_charset}
                 COLLATE={$default_collation}";
        $DB->doQuery($query);
    }
    $monitoring_password = Config::getConfigurationValue('plugin:ivertixmonitoring', 'monitoring-password');
    /**Migration to 1.0.1 */
    if ($monitoring_password !== null) {
        /** Check if pwd is already encrypted, if not, it returns empty string */
        /** It's not necessary to encrypt again, because setConfigurationValues() check
         * if the value is in secured_configs and if yes, and encrypt it
         */
        $decrypted_pwd = @(new GLPIKey())->decrypt($monitoring_password);
        if ($decrypted_pwd == '') {
            Config::setConfigurationValues('plugin:ivertixmonitoring', [
                'monitoring-password' => $monitoring_password,
            ]);
        }
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

    if ($itemtype == 'Computer') {
        $sopt[] = [
            'id'               => 2023,
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
