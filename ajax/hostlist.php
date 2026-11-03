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

use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Ivertixmonitoring\Host;

use function Safe\json_encode;

header("Content-Type: application/json; charset=UTF-8");
Html::header_nocache();

Session::checkLoginUser();
Session::checkRight(Host::$rightname, UPDATE);

$searchText = $_POST["searchText"] ?? null;
if ($searchText !== null && !is_string($searchText)) {
    throw new BadRequestHttpException("Invalid parameter: 'searchText'");
}
$limit = filter_var($_POST["page_limit"] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
$page = filter_var($_POST["page"] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
if ($limit === false || $page === false) {
    throw new BadRequestHttpException("Invalid parameter: 'page_limit' or 'page'");
}

$hosts = Host::getHostList($searchText, $limit, $page);
echo json_encode([
    "results" => array_map(static fn($i) => ["id" => $i["id"], "text" => $i["name"]], $hosts),
    // GLPI's select2 setup loads the next page while `count` reaches the page size
    "count" => count($hosts),
]);
