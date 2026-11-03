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

$comment = $_POST["comment"] ?? null;
foreach (["is_notify_contacts", "is_persistent_comment", "is_sticky", "with_services"] as $flag) {
    if (!isset($_POST[$flag]) || !is_string($_POST[$flag])) {
        throw new BadRequestHttpException("Missing or invalid acknowledge parameters");
    }
}
if (!is_string($comment)) {
    throw new BadRequestHttpException("Missing or invalid acknowledge parameters");
}

if (!$host->acknowledge(
    $comment,
    $_POST["is_notify_contacts"] === "true",
    $_POST["is_persistent_comment"] === "true",
    $_POST["is_sticky"] === "true",
    $_POST["with_services"] === "true"
)) {
    throw new HttpException(500, "Request failed");
}
echo "Request completed";
