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

use Glpi\Http\Response;
use GlpiPlugin\Ivertixmonitoring\Host;

include('../../../inc/includes.php');

header("Content-Type: application/json; charset=UTF-8");
Html::header_nocache();

Session::checkLoginUser();

$host = new Host();
$itemId = $_GET["item_id"] ?? null;
$itemType = $_GET["itemtype"] ?? null;
$hostId = $_POST["host_id"] ?? null;

if (!isset($itemType)) {
    Response::sendError(400, "Missing or invalid parameter: 'itemtype'");
}

if (!isset($itemId) || !is_numeric($itemId)) {
    Response::sendError(400, "Missing or invalid parameter: 'item_id'");
} else {
    $itemId = (int)Toolbox::cleanInteger($itemId);
}

if ($hostId === "") {
    $hostId = null;
} else if (is_numeric($hostId)) {
    $hostId = (int)Toolbox::cleanInteger($hostId);
} else {
    Response::sendError(400, "Missing parameter: 'host_id'");
}

$item = getItemForItemtype($itemType);
if ($item === false) {
    Response::sendError(400, "Missing or invalid parameter: 'itemtype'");
} else if (!$item->can($itemId, UPDATE)) {
    Response::sendError(404, __("You don't have permission to perform this action."));
}

$success = $host->linkItemToMonitoringHostByHostId($itemId, $itemType, $hostId);

if (!$success) {
    Response::sendError(404, __("linking host failed"));
}

echo json_encode($hostId !== null ? $host->fields : "link to host removed");
