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

use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Ivertixmonitoring\Host;

header('Content-Type: text/html; charset=UTF-8');
Html::header_nocache();

Session::checkLoginUser();

$host = Host::getRequestedLink(READ, READ);

$period = $_GET["period"] ?? null;
if (!in_array($period, ["day", "week", "month"], true)) {
    throw new BadRequestHttpException("Missing or invalid parameter: 'period'");
}

TemplateRenderer::getInstance()->display('@ivertixmonitoring/timeline.html.twig', [
    'timeline' => $host->getTimeline($period) ?? [],
    'status_labels' => Host::getStatusLabels(),
]);
