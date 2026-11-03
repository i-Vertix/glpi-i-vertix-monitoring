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
use Glpi\Exception\Http\HttpException;
use GlpiPlugin\Ivertixmonitoring\Host;

header('Content-Type: text/html; charset=UTF-8');
Html::header_nocache();

Session::checkLoginUser();
Host::checkPostRequest();

$host = Host::getRequestedLink(READ, Host::RIGHT_ACTION);

$start = Host::parseDate($_POST["start_time"] ?? null);
$end = Host::parseDate($_POST["end_time"] ?? null);
$isFixed = ($_POST["is_fixed"] ?? null) === "true";
$withServices = ($_POST["with_services"] ?? null) === "true";
$comment = $_POST["comment"] ?? null;

if ($start === null || $end === null || $end <= $start || !is_string($comment)) {
    throw new BadRequestHttpException("Missing or invalid downtime parameters");
}
if ($end->getTimestamp() - $start->getTimestamp() > Host::MAX_DOWNTIME_SECONDS) {
    throw new BadRequestHttpException("Downtime too long");
}

$duration = null;
if (!$isFixed) {
    $durationUnit = $_POST["duration_unit"] ?? null;
    $durationValue = filter_var(
        $_POST["duration_value"] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => Host::MAX_DOWNTIME_SECONDS]]
    );
    if ($durationValue === false || !in_array($durationUnit, ["s", "m", "h"], true)) {
        throw new BadRequestHttpException("Missing or invalid downtime parameters");
    }
    $duration = Host::convertDuration($durationUnit, $durationValue);
    if ($duration > Host::MAX_DOWNTIME_SECONDS) {
        throw new BadRequestHttpException("Downtime too long");
    }
}

if (!$host->setDowntime($start, $end, $isFixed, $duration, $comment, $withServices)) {
    throw new HttpException(500, "Request failed");
}
echo "Request completed";
