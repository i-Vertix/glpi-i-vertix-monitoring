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

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;
use GlpiPlugin\Ivertixmonitoring\ApiClient;
use Toolbox;
use Config as Glpi_Config;

class Config extends Glpi_Config
{
    public static function getTypeName($nb = 0)
    {
        return __('i-Vertix Monitoring settings', 'ivertixmonitoring');
    }

    public static function getConfig()
    {
        return \Config::getConfigurationValues('plugin:ivertixmonitoring');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        switch ($item->getType()) {
            case \Config::class:
                return self::createTabEntry(self::getTypeName());
        }

        return '';
    }

    public static function displayTabContentForItem(
        CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ) {
        if ($item instanceof \Config) {
            return self::showForConfig($item, $withtemplate);
        }

        return true;
    }

    public static function showForConfig(\Config $config, $withtemplate = 0)
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        if (!self::canUpdate()) {
            return false;
        }

        $current_config = self::getConfig();
        $canedit        = Session::haveRight(self::$rightname, UPDATE);

        TemplateRenderer::getInstance()->display('@ivertixmonitoring/config.html.twig', [
            'item'           => $config,
            'current_config' => $current_config,
            'can_edit'       => $canedit,
        ]);

        $conf_ok = true;

        foreach ($current_config as $v) {
            if (strlen($v) === 0) {
                $conf_ok = false;
            }
        }
        if (empty($current_config)) $conf_ok = false;
        if ($conf_ok === true) {
            $api  = new ApiClient();
            $diag = $api->diagnostic();

            TemplateRenderer::getInstance()->display('@ivertixmonitoring/diagnostic.html.twig', [
                'diag' => $diag,
            ]);

            if ($diag["result"] === true && $canedit) {
                TemplateRenderer::getInstance()->display('@ivertixmonitoring/syncAll.html.twig', [
                    'current_config' => $current_config,
                ]);
            }
        } else {
            TemplateRenderer::getInstance()->display('@ivertixmonitoring/checkField.html.twig');
        }
    }

    public static function prepareConfigUpdate(\CommonDBTM $item): void
    {
        if (
            isset($item->input['monitoring-password'])
            && ($item->input['monitoring-password'] === '')
        ) {
            unset($item->input['monitoring-password']);
        }
    }
}
