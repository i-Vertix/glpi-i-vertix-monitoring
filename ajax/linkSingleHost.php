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

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Exception\Http\HttpException;
use GlpiPlugin\Ivertixmonitoring\Host;

use function Safe\json_encode;

header("Content-Type: application/json; charset=UTF-8");
Html::header_nocache();

Session::checkLoginUser();
Host::checkPostRequest();

[$itemType, $itemId] = Host::getRequestedItem(UPDATE, UPDATE);

$host = new Host();

// an empty host id removes the link
$hostId = $_POST["host_id"] ?? null;
if ($hostId === "") {
    $hostId = null;
} elseif (is_string($hostId) && ctype_digit($hostId)) {
    $hostId = (int)$hostId;
    if ($host->getMonitoringHost($hostId) === null) {
        throw new BadRequestHttpException("Unknown monitoring host");
    }
    if (!$host->canLinkMonitoringHost($hostId)) {
        // the host is linked to an asset the user cannot update
        throw new AccessDeniedHttpException();
    }
} else {
    throw new BadRequestHttpException("Missing or invalid parameter: 'host_id'");
}

if (!$host->linkItemToMonitoringHostByHostId($itemId, $itemType, $hostId)) {
    throw new HttpException(500, "Linking host failed");
}

echo json_encode($hostId !== null ? $host->fields : "Link to host removed");
