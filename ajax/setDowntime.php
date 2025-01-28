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

use Glpi\Http\Response;
use GlpiPlugin\Ivertixmonitoring\Host;

include('../../../inc/includes.php');

header('Content-Type: text/html; charset=UTF-8');
Html::header_nocache();

$itemId = $_GET["item_id"] ?? null;
$itemType = $_GET["itemtype"] ?? null;
$startTime = $_POST["start_time"] ?? null;
$endTime = $_POST["end_time"] ?? null;
$isFixed = $_POST["is_fixed"] ?? null;
$withServices = $_POST["with_services"] ?? null;
$durationUnit = $_POST["duration_unit"] ?? null;
$durationValue = $_POST["duration_value"] ?? null;
$comment = $_POST["comment"] ?? null;

if (!isset($itemType)) {
    Response::sendError(400, "Missing or invalid parameter: 'itemtype'");
}

if (!isset($itemId) || !is_numeric($itemId)) {
    Response::sendError(400, "Missing or invalid parameter: 'item_id'");
} else {
    $itemId = (int)Toolbox::cleanInteger($itemId);
}

if (!isset($startTime, $endTime, $isFixed, $withServices, $comment)
    || !is_string($startTime)
    || !is_string($endTime)
    || !is_string($comment)) {
    Response::sendError(400, "Missing or invalid downtime parameters");
}
$withServices = $withServices === "true";
$isFixed = $isFixed === "true";

if ($isFixed === false) {
    if (!isset($durationUnit, $durationValue) || !is_numeric($durationValue) || !in_array($durationUnit, ["s", "m", "h"], true)) {
        Response::sendError(400, "Missing or invalid downtime parameters");
    }
    $durationValue = (int)$durationValue;
}

$item = getItemForItemtype($itemType);
if ($item === false) {
    Response::sendError(400, "Missing or invalid parameter: 'itemtype'");
} else if (!$item->can($itemId, UPDATE)) {
    Response::sendError(404, __("You don't have permission to perform this action."));
}

$host = new Host();
if ($host->isItemLinked($itemId, $itemType)) {
    if (!$host->setDowntime(
        $startTime,
        $endTime,
        $isFixed,
        $isFixed ? null : ["unit" => $durationUnit, "value" => $durationValue],
        $comment,
        $withServices
    )) {
        Response::sendError(500, "Request failed");
    }
    die("Request completed");
} else {
    Response::sendError(404, "No linked monitoring host found for item");
}