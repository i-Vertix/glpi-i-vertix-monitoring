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

namespace GlpiPlugin\Ivertixmonitoring;

use Computer;
use CommonDBTM;
use CommonGLPI;
use DateTime;
use DateTimeZone;
use Dropdown;
use Html;
use NetworkEquipment;
use Plugin;
use Glpi\Application\View\TemplateRenderer;

class Host extends CommonDBTM
{
    private ApiClient $api_client;

    public function __construct(ApiClient $api_client = null)
    {
        parent::__construct();
        if ($api_client === null) {
            $this->api_client = new ApiClient();
        } else {
            $this->api_client = $api_client;
        }
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('i-Vertix Monitoring', 'i-Vertix Monitoring', $nb);
    }

    public function getAllHosts(): array
    {
        $api = $this->api_client;
        $connected = $api->authenticate();
        if ($connected === true) {
            // TODO: PAGING
            $list = $api->getHosts();
            if ($list["success"]) {
                $list = $list["result"];
                $items = [];
                foreach ($list["result"] as $item) {
                    $items[$item["id"]] = $item;
                }
                return $items;
            }
        }
        return [];
    }

    public static function getHostList(?string $name, ?int $limit, ?int $page): array
    {
        $self = new self();
        $api = $self->api_client;
        $connected = $api->authenticate();
        if ($connected === true) {
            $query = [];
            if (!empty($name)) {
                $query['search'] = json_encode([
                    'host.name' => [
                        '$lk' => "%" . $name . "%",
                    ],
                ]);
            }
            if ($limit) {
                $query["limit"] = $limit;
            }
            if ($page) {
                $query["page"] = $page;
            }
            $list = $api->getHosts($query);
            $items = [];
            if ($list["success"]) {
                $list = $list["result"];
                foreach ($list["result"] as $item) {
                    $items[] = ["id" => $item["id"], "name" => $item["name"]];
                }
            }
            return $items;
        }
        return [];
    }

    public function getHostListItem(): ?array
    {
        if (!isset($this->fields["monitoring_id"])) {
            return null;
        }
        $connected = $this->api_client->authenticate();
        if ($connected === true) {
            $host = $this->api_client->getHostById((int)$this->fields["monitoring_id"]);
            if ($host["success"]) {
                $host = $host["result"];
                return ["id" => $host["id"], "name" => $host["name"]];
            }
        }
        return null;
    }

    public function getHostDetail(): ?array
    {
        if (!isset($this->fields["monitoring_id"])) {
            return null;
        }
        $connected = $this->api_client->authenticate();
        if ($connected === true) {
            $hostDetail = $this->api_client->getHostById((int)$this->fields["monitoring_id"]);
            $hostResources = $this->api_client->getHostResourceDetailById((int)$this->fields["monitoring_id"]);
            $hostServices = $this->api_client->getServicesByHostId((int)$this->fields["monitoring_id"]);
//            $hostDowntimes = $this->api_client->getHostDowntimes((int)$this->fields["monitoring_id"]);
            if ($hostDetail["success"] && $hostResources["success"] && $hostServices["success"]) {
                $hostDetail = $hostDetail["result"];
                $hostResources = $hostResources["result"];
                $hostServices = $hostServices["result"];
                $host = [
                    'status' => $hostResources['status']['name'],
                    'name' => $hostResources['name'],
                    'alias' => $hostDetail['alias'],
                    'fqdn' => $hostResources['fqdn'],
                    'last_check' => $hostDetail['last_check'],
                    'next_check' => $hostDetail['next_check'],
                    'check_period' => $hostDetail['check_period'],
                    'in_downtime' => $hostResources['in_downtime'],
                    'acknowledged' => $hostResources["acknowledgement"] !== null ?
                        sprintf("Problem acknowledged by '%s' on %s%s",
                            $hostResources["acknowledgement"]["author_name"],
                            $hostResources["acknowledgement"]["entry_time"],
                            trim($hostResources["acknowledgement"]["comment"]) !== "" ? " - " . $hostResources["acknowledgement"]["comment"] : ""
                        ) : false,
                ];
                if ($hostResources['in_downtime']) {
                    $host['downtimes'] = $hostResources['downtimes'];
                }
                $host['services'] = $hostServices['result'];
                $host['nb_services'] = count($host['services']);
                return $host;
            }
        }
        return null;
    }

    public function getTimeline(string $period): ?array
    {
        if (!isset($this->fields["monitoring_id"])) {
            return null;
        }
        $api = $this->api_client;
        $connected = $api->authenticate();
        $timeline = [];
        if ($connected === true) {
            $hostTimelineById = $api->getHostTimelineById((int)$this->fields["monitoring_id"]);
            if (!$hostTimelineById["success"]) {
                return null;
            }
            $hostTimelineById = $hostTimelineById["result"]["result"];
            foreach ($hostTimelineById as $event) {
                if (!isset($event["status"]["name"])) {
                    $event['status']['name'] = $event["type"];
                }
                $timeline[] = [
                    'date' => self::transformDate($event['date']),
                    'content' => $event['content'],
                    'status' => $event['status']['name'],
                    'tries' => $event['tries'] ?? "",
                ];
            }

            $periodString = '';
            switch ($period) {
                case 'day':
                    $periodString = '-1 day';
                    break;
                case 'week':
                    $periodString = '-7 days';
                    break;
                case 'month':
                    $periodString = '-1 month';
                    break;
            }
            $dateEnd = date('Y-m-d', strtotime(date('Y-m-d') . $periodString));
            $filteredTimeline = [];
            foreach ($timeline as $event => $info) {
                $setdate = self::transformDateForCompare($info['date']);
                if ($setdate >= $dateEnd) {
                    $filteredTimeline[$event] = $info;
                }
            }
            return $filteredTimeline;
        }
        return null;
    }

    private static function transformDate($date)
    {
        $timestamp = strtotime($date);
        return date('l,F d,Y G:i:s', $timestamp);
    }

    private static function transformDateForCompare($date)
    {
        $timestamp = strtotime($date);
        return date('Y-m-d', $timestamp);
    }

    public function sendHostCheck(): bool
    {
        if (!isset($this->fields["monitoring_id"])) {
            return false;
        }
        $api = $this->api_client;
        $connected = $api->authenticate();
        if ($connected === true) {
            $res = $api->sendHostCheck((int)$this->fields["monitoring_id"]);
            return $res["success"];
        }
        return false;
    }

    public function setDowntime(string $startTime, string $endTime, bool $isFixed, ?array $duration, string $comment, bool $withServices): bool
    {
        if (!isset($this->fields["monitoring_id"])) {
            return false;
        }
        $downtime = [
            "is_fixed" => $isFixed,
            "start_time" => self::convertDateToIso8601($startTime),
            "end_time" => self::convertDateToIso8601($endTime),
            "comment" => $comment,
            "with_services" => $withServices
        ];
        if ($isFixed) {
            $downtime["duration"] = self::diffDateInSeconds($endTime, $startTime);
        } else {
            $downtime["duration"] = self::convertDuration($duration["unit"], $duration["value"]);
        }
        $api = $this->api_client;
        $connected = $api->authenticate();
        if ($connected === true) {
            $res = $api->setHostDowntime((int)$this->fields["monitoring_id"], $downtime);
            return $res["success"];
        }
        return false;
    }

    public static function convertDateToIso8601(string $date): string
    {
        $timezone = new DateTimeZone($_SESSION['glpi_tz'] ?? date_default_timezone_get());
        return (new DateTime($date, $timezone))->format(DATE_ATOM);
    }

    public static function diffDateInSeconds($date1, $date2)
    {
        $ts1 = strtotime($date1);
        $ts2 = strtotime($date2);
        return abs($ts2 - $ts1);
    }

    public static function convertDuration(string $unit, int $value): int
    {
        switch ($unit) {
            case "m":
                return $value * 60;
            case "h":
                return $value * 3600;
            default:
                return $value;
        }
    }

    public function cancelDowntime(int $downtimeId): bool
    {
        if (!isset($this->fields["monitoring_id"])) {
            return false;
        }
        $api = $this->api_client;
        $connected = $api->authenticate();

        if ($connected === true) {
            $hostDowntime = $api->getDowntimeById($downtimeId);
            if (!$hostDowntime["success"]) {
                return false;
            }
            $hostDowntime = $hostDowntime["result"];
            // check host id
            if ((int)$this->fields["monitoring_id"] !== $hostDowntime["host_id"]) {
                return false;
            }

            $hostId = $hostDowntime['host_id'];
            $startTime = $hostDowntime['start_time'];
            $endTime = $hostDowntime['end_time'];

            $servicesDowntimes = $api->getServiceDowntimesByHostId($hostId, 200);
            if ($servicesDowntimes["success"]) {
                $servicesDowntimes = $servicesDowntimes["result"];
                foreach ($servicesDowntimes['result'] as $serviceDowntime) {
                    if (isset($serviceDowntime['start_time'], $serviceDowntime['end_time'])
                        && $serviceDowntime['start_time'] === $startTime && $serviceDowntime['end_time'] === $endTime) {
                        $api->cancelDowntimeById($serviceDowntime['id']);
                    }
                }
            }
            $res = $api->cancelDowntimeById($downtimeId);
            return $res["success"];
        }

        return false;
    }

    public function acknowledge(string $comment, bool $isNotifyContacts, bool $isPersistentComment, bool $isSticky, bool $withServices): bool
    {
        if (!isset($this->fields["monitoring_id"])) {
            return false;
        }
        $api = $this->api_client;
        $connected = $api->authenticate();
        if ($connected === true) {
            $res = $api->acknowledgeHostById((int)$this->fields["monitoring_id"], [
                "comment" => $comment,
                "is_notify_contacts" => $isNotifyContacts,
                "is_persistent_comment" => $isPersistentComment,
                "is_sticky" => $isSticky,
                "with_services" => $withServices
            ]);
            return $res["success"];
        }
        return false;
    }

    public function linkItemToMonitoringHostByItemName(int $id, string $type): bool
    {
        // todo: temporary disabled / used on showForItem to auto-link
        return false;

        $item = getItemForItemtype($type);
        $item->getFromDB($id);
        $obj_name = $item->fields['name'];

        $api = $this->api_client;
        $connected = $api->authenticate();
        if ($connected === true) {
            $query = [
                'search' => json_encode([
                    'host.name' => [
                        '$eq' => $obj_name,
                    ],
                ]),
            ];
            $match = $api->getHosts($query);
        } else {
            return false;
        }

        if ($match["success"] && isset($match["result"]["result"][0])) {
            $match = $match["result"]["result"][0];
            if ($match['name'] === $obj_name) {
                $monitoring_id = $match['id'];
                $new_id = $this->add([
                    'itemtype' => $type,
                    'item_id' => $id,
                    'monitoring_id' => $monitoring_id,
                    'monitoring_type' => 'host',
                ]);
                $this->getFromDB($new_id);

                return true;
            }
        }

        return false;
    }

    public function isItemLinked(int $id, string $type): bool
    {
        if ($this->getFromDBByCrit(['item_id' => $id, 'itemtype' => $type])) {
            return true;
        }

        return false;
    }

    public function linkItemToMonitoringHostByHostId(int $id, string $type, ?int $hostId): bool
    {
        global $CFG_GLPI;

        $config = Config::getConfig();
        $item = getItemForItemtype($type);

        if ($this->isItemLinked($id, $type)) {
            $oldHostId = (int)$this->fields["monitoring_id"];
            // currently linked host id is same as host id to link - return
            if ($oldHostId === $hostId) {
                return false;
            }
            // item is already linked, remove link
            $this->deleteByCriteria(['item_id' => $id, 'itemtype' => $type]);
            // when sync to monitoring is enabled -> remove old note url
            if ($config["monitoring-sync"] === "1") {
                $this->api_client->updateHost($oldHostId, ["json" => [
                    "note_url" => null
                ]]);
            }
        }
        // return true when host is not to link
        if ($hostId === null) {
            return true;
        }
        $newId = $this->add(
            [
                'itemtype' => $type,
                'item_id' => $id,
                'monitoring_id' => $hostId,
                'monitoring_type' => 'host',
            ]
        );
        // set note url to new linked monitoring host
        if (is_int($newId)) {
            $this->getFromDB($newId);
            if ($config["monitoring-sync"] === "1") {
                $url = substr($item::getFormURLWithID($id), 1);
                $this->api_client->updateHost($hostId, ["json" => [
                    "note_url" => $CFG_GLPI['url_base'] . "/" . $url
                ]]);
            }
            return true;
        }

        return false;
    }

    public static function linkAll(): int
    {
        global $CFG_GLPI;

        $self = new self();
        if (!$self->api_client->authenticate()) {
            return 0;
        }

        $cnt = 0;
        $obj = [new Computer(), new NetworkEquipment()];

        // load monitoring hosts
        $hosts = $self->getAllHosts();
        $hostHashByName = [];
        foreach ($hosts as $host) {
            $hostHashByName[$host["name"]] = $host;
        }

        // get currently linked
        $currentLinks = $self->find();
        $linkHash = [];
        $linkHashByHostId = [];
        foreach ($currentLinks as $link) {
            $linkHash[$link["itemtype"]][$link["item_id"]] = $link;
            $linkHashByHostId[$link["monitoring_id"]] = $link;
        }

        $assetsArr = [];
        foreach ($obj as $o) {
            $assetsArr[] = $o->find(["is_deleted" => 0]);
        }

        foreach ($assetsArr as $index => $assets) {
            foreach ($assets as $asset) {
                // check if already linked
                if (isset($linkHash[$obj[$index]->getType()][$asset["id"]])) {
                    continue;
                }

                // check if monitoring host is available
                $host = $hostHashByName[$asset["name"]] ?? null;
                if (isset($host)) {
                    $link = [
                        "itemtype" => $obj[$index]->getType(),
                        "item_id" => $asset["id"],
                        "monitoring_id" => $host["id"],
                        "monitoring_name" => $host["name"]
                    ];
                    $self->add($link);
                    $linkHashByHostId[$host["id"]] = $link;
                    $linkHash[$obj[$index]->getType()][$asset["id"]] = $link;
                    $cnt++;
                }
            }
        }

        $config = Config::getConfig();
        if ($config["monitoring-sync"] === "1") {
            foreach ($hosts as $host) {
                $link = $linkHashByHostId[$host["id"]] ?? null;
                if (isset($link)) {
                    // host is linked - update notes url
                    $item = getItemForItemtype($link["itemtype"]);
                    if ($item !== false) {
                        $url = substr($item::getFormURLWithID($link["item_id"]), 1);
                        $self->api_client->updateHost($host["id"], ["json" => [
                            "note_url" => $CFG_GLPI['url_base'] . "/" . $url
                        ]]);
                    }
                } else {
                    // host is not linked
                }
            }
        }

        return $cnt;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof CommonDBTM) {
            $nb = countElementsInTable(
                self::getTable(),
                [
                    'item_id' => $item->getID(),
                    'itemtype' => $item->getType(),
                ],
            );

            return self::createTabEntry(self::getTypeName($nb), $nb);
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof CommonDBTM) {
            self::showForItem($item, $withtemplate);
        }

        return true;
    }

    public static function showForItem(CommonDBTM $item, $withtemplate = 0)
    {
        global $CFG_GLPI;

        $self = new self();
        $item_id = $item->getID();
        $item_type = $item->getType();
        // check api
        $user = $self->api_client->getAuthUser();
        if ($user === null) {
            TemplateRenderer::getInstance()->display('@ivertixmonitoring/noconnection.html.twig');
        } else {
            $alreadyLinked = $self->isItemLinked($item_id, $item_type) || $self->linkItemToMonitoringHostByItemName($item_id, $item_type);

            if ($alreadyLinked) {
                // get monitoring host id and name
                $hostItem = $self->getHostListItem();
            } else {
                $hostItem = null;
            }

            // render host select
            $pluginWebPath = Plugin::getWebDir("ivertixmonitoring");
            $hostDropdown = Html::jsAjaxDropdown(
                "monitoring_host",
                Html::cleanId("dropdown_monitoring_host"),
                $pluginWebPath . "/ajax/hostlist.php",
                [
                    "value" => $hostItem ? $hostItem["id"] : 0,
                    "valuename" => $hostItem ? $hostItem["name"] : Dropdown::EMPTY_VALUE,
                    'on_change' => "onHostChange(e)",
                    'placeholder' => "Linked Monitoring Host"
                ]
            );
            TemplateRenderer::getInstance()->display('@ivertixmonitoring/selecthost.html.twig', [
                'host_dropdown' => $hostDropdown,
                'host_dropdown_id' => Html::cleanId("dropdown_monitoring_host"),
                'itemtype' => $item_type,
                'item_id' => $item_id
            ]);
            if ($alreadyLinked) {
                $hostDetail = $self->getHostDetail();
                if (empty($hostDetail)) {
                    // when one host is empty, linked host probably does not exist anymore
                    TemplateRenderer::getInstance()->display('@ivertixmonitoring/invalidhost.html.twig');
                } else {
                    TemplateRenderer::getInstance()->display('@ivertixmonitoring/host.html.twig', [
                        'item_id' => $item_id,
                        'itemtype' => $item_type,
                        'host' => $hostDetail,
                        'user_name' => $user["alias"],
                        'logo' => Plugin::getWebDir('ivertixmonitoring') . '/files/logo-ivertix.png',
                    ]);
                }
            } else {
                TemplateRenderer::getInstance()->display('@ivertixmonitoring/nohost.html.twig');
            }
        }
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        switch ($field) {
            case 'id':
                if ((int)$values['id'] > 0) {
                    $self = new self();
                    $self->getFromDB((int)$values["id"]);
                    $res = $self->getHostDetail();

                    return $res['status'] ?? '';
                }
                break;
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }
}
