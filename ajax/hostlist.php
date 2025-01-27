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

include('../../../inc/includes.php');

header("Content-Type: application/json; charset=UTF-8");
Html::header_nocache();

Session::checkLoginUser();

Session::checkSeveralRightsOr(['computer' => UPDATE, 'networking' => UPDATE]);

$hosts = Host::getHostList(
    $_POST["searchText"] ?? null,
    isset($_POST["page_limit"]) ? (int)$_POST["page_limit"] : null,
    isset($_POST["page"]) ? (int)$_POST["page"] : null
);
echo json_encode(["results" => array_map(static function ($i) {
    return ["id" => $i["id"], "text" => $i["name"]];
}, $hosts)]);
