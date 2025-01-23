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

use Computer;
use CommonDBTM;
use CommonGLPI;
use NetworkEquipment;
use Plugin;
use Glpi\Application\View\TemplateRenderer;

class Host extends CommonDBTM
{
    private $api_client;
    public $glpi_items = [];
    public $monitoring_items = [];
    public $one_host = [];
    public $uid = '';
    public $username = '';

    public function __construct(ApiClient $api_client = null)
    {
        if ($api_client == null) {
            $this->api_client = new ApiClient();
        } else {
            $this->api_client = $api_client;
        }
    }

    public static function getTypeName($nb = 0)
    {
        return _n('i-Vertix Monitoring', 'i-Vertix Monitoring', $nb);
    }

    public function getMonitoringHosts(): array
    {
        $api = $this->api_client;
        $connected = $api->authenticate();
        if ($connected === true) {
            $list = $api->getHosts();
            if ($list != null) {
                $items_monitoring = [];
                foreach ($list['result'] as $item) {
                    $items_monitoring[$item["id"]] = $item;
                }
                $this->monitoring_items = $items_monitoring;
            }
        }
        return $this->monitoring_items;
    }

    public function matchItems()
    {
        foreach ($this->glpi_items as $o1) {
            foreach ($this->monitoring_items as $o2) {
                if ($o1['cpt_name'] == $o2['monitoring_name']) {
                    $this->add([
                        'items_id' => $o1['cpt_id'],
                        'monitoring_id' => $o2['monitoring_id'],
                        'itemtype' => 'Computer',
                        'monitoring_type' => 'Host',
                    ]);
                }
            }
        }
    }

    public function getHostById($id)
    {
        $connected = $this->api_client->authenticate();
        if ($connected === true) {
            $gethost = $this->api_client->getHostById($id);
            $gethost_r = $this->api_client->getHostResourceDetailById($id);
            $getservices = $this->api_client->getServicesByHostId($id);
            $getdowntimes = $this->api_client->getHostDowntimes($id);
            if ($gethost != null) {
                $i_host = [
                    'status' => $gethost_r['status']['name'],
                    'name' => $gethost_r['name'],
                    'alias' => $gethost['alias'],
                    'fqdn' => $gethost_r['fqdn'],
                    'last_check' => $gethost['last_check'],
                    'next_check' => $gethost['next_check'],
                    'check_period' => $gethost['check_period'],
                    'in_downtime' => $gethost_r['in_downtime'],
                ];
                if ($gethost_r['in_downtime']) {
                    $i_host['downtimes'] = $gethost_r['downtimes'];
                }
                $i_host['services'] = $getservices['result'];
                $i_host['nb_services'] = count($i_host['services']);
                $this->one_host = $i_host;

                return $i_host;
            }
        }
    }

    public function hostTimeline(int $id, string $period)
    {
        $api = $this->api_client;
        $connected = $api->authenticate();
        $timeline = [];
        if ($connected === true) {
            $gettimeline = $api->getHostTimelineById($id);
            $timeline_r = $gettimeline['result'];
            foreach ($timeline_r as $event) {
                if ($event['type'] === 'downtime') {
                    $event['status']['name'] = __('unset', 'ivertixmonitoring');
                    $event['tries'] = __('unset', 'ivertixmonitoring');
                }
                $timeline[] = [
                    'id' => $event['id'],
                    'date' => $this->transformDate($event['date']),
                    'content' => $event['content'],
                    'status' => $event['status']['name'],
                    'tries' => $event['tries'],
                ];
            }

            $period_string = '';
            switch ($period) {
                case 'day':
                    $period_string = '-1 day';
                    break;
                case 'week':
                    $period_string = '-7 days';
                    break;
                case 'month':
                    $period_string = '-1 month';
                    break;
            }
            $date_end = date('Y-m-d', strtotime(date('Y-m-d') . $period_string));
            $filtered_timeline = [];
            foreach ($timeline as $event => $info) {
                $setdate = $this->transformDateForCompare($info['date']);
                if ($setdate >= $date_end) {
                    $filtered_timeline[$event] = $info;
                }
            }
            TemplateRenderer::getInstance()->display('@ivertixmonitoring/timeline.html.twig', [
                'timeline' => $filtered_timeline,
            ]);
        }
    }

    public function transformDate($date)
    {
        $timestamp = strtotime($date);
        return date('l,F d,Y G:i:s', $timestamp);
    }

    public function transformDateForCompare($date)
    {
        $timestamp = strtotime($date);
        return date('Y-m-d', $timestamp);
    }

    public function sendCheck(int $id)
    {
        $api = $this->api_client;
        $connected = $api->authenticate();
        if ($connected === true) {
            try {
                $res = $api->sendHostCheck($id);
                return __('Check sent', 'ivertixmonitoring');
            } catch (\Exception $e) {
                return $e->getMessage();
            }
        }
    }

    public function setDowntime(int $id, array $params)
    {
        $params['is_fixed'] = filter_var($params['is_fixed'], FILTER_VALIDATE_BOOLEAN);
        $params['with_services'] = filter_var($params['with_services'], FILTER_VALIDATE_BOOLEAN);
        $params['start_time'] = $this->convertDateToIso8601($params['start_time']);
        $params['end_time'] = $this->convertDateToIso8601($params['end_time']);

        if ($params['is_fixed'] == true) {
            $params['duration'] = $this->diffDateInSeconds($params['end_time'], $params['start_time']);
        }
        if ($params['is_fixed'] == false) {
            $option = $params['time_select'];
            $params['duration'] = $this->convertToSeconds($option, $params['duration']);
            $params['duration'] = filter_var($params['duration'], FILTER_VALIDATE_INT);
        }
        unset($params['time_select']);
        $api = $this->api_client;
        $connected = $api->authenticate();
        if ($connected === true) {
            try {
                return $api->setHostDowntime($id, ['json' => $params]);
            } catch (\Exception $e) {
                return $e->getMessage();
            }
        }
    }

    public function convertDateToIso8601($date)
    {
        $timezone = new \DateTimeZone($_SESSION['glpi_tz'] ?? date_default_timezone_get());
        $new_date = new \DateTime($date, $timezone);
        return $new_date->format(DATE_ATOM);
    }

    public function diffDateInSeconds($date1, $date2)
    {
        $ts1 = strtotime($date1);
        $ts2 = strtotime($date2);
        return abs($ts2 - $ts1);
    }

    public function convertToSeconds($option, $duration)
    {
        if ($option == 2) {
            $new_duration = $duration * 60;
        } elseif ($option == 3) {
            $new_duration = $duration * 60 * 60;
        } else {
            $new_duration = $duration;
        }

        return $new_duration;
    }

    public function cancelActualDownTime(int $downtime_id): array
    {
        $api = $this->api_client;
        $connected = $api->authenticate();
        $error = [];

        if ($connected === true) {
            try {
                $actualDowntime = $api->getDowntimeById($downtime_id);
                $host_id = $actualDowntime['host_id'];
                $start_time = $actualDowntime['start_time'];
                $end_time = $actualDowntime['end_time'];

                $servicesDowntimes = $api->getServiceDowntimesByHostId($host_id);
                foreach ($servicesDowntimes['result'] as $serviceDowntime) {
                    if (isset($serviceDowntime['start_time']) && isset($serviceDowntime['end_time'])) {
                        if ($serviceDowntime['start_time'] == $start_time && $serviceDowntime['end_time'] == $end_time) {
                            $s_downtime_id = $serviceDowntime['id'];
                            $api->cancelDowntimeById($s_downtime_id);
                        }
                    } else {
                        $error[] = [
                            'service_id' => $serviceDowntime['id'],
                            'message' => 'No downtime found for this service',
                        ];
                    }
                }
                $api->cancelDowntimeById($downtime_id);
            } catch (\Exception $e) {
                $error[] = [
                    'message' => $e->getMessage(),
                ];
            }
        } else {
            $error[] = [
                'message' => $connected,
            ];
        }

        return $error;
    }

    public function acknowledgement(int $host_id, array $request = [])
    {
        $api = $this->api_client;
        $connected = $api->authenticate();
        if ($connected === true) {
            try {
                $result[] = $api->acknowledgeHostById($host_id, $request);

                return $result;
            } catch (\Exception $e) {
                $error_msg = $e->getMessage();

                return $error_msg;
            }
        } else return $connected;
    }

    public function searchItemMatch(int $id, string $type)
    {
        $item = getItemForItemtype($type);
        $obj = $item->getFromDB($id);
        $obj_name = $item->fields['name'];

        $api = $this->api_client;
        $connected = $api->authenticate();
        if ($connected === true) {
            $params = [
                'query' => [
                    'search' => json_encode([
                        'host.name' => [
                            '$eq' => $obj_name,
                        ],
                    ]),
                ],
            ];
            $match = $api->getHosts($params);
        } else return false;

        if (isset($match['result']['0']['name']) && $match['result']['0']['name'] === $obj_name) {
            $monitoring_id = $match['result']['0']['id'];
            $new_id = $this->add([
                'itemtype' => $type,
                'item_id' => $id,
                'monitoring_id' => $monitoring_id,
                'monitoring_type' => 'host',
            ]);
            $this->getFromDB($new_id);

            return true;
        }

        return false;
    }

    public function searchForItem($id)
    {
        if ($this->getFromDBByCrit(['item_id' => $id])) {
            return true;
        } else {
            return false;
        }
    }

    public function linkAll(): int
    {
        $cnt = 0;
        $obj = [new Computer(), new NetworkEquipment()];

        // load monitoring hosts
        $hosts = $this->getMonitoringHosts();
        $hostHashByName = [];
        foreach ($hosts as $host) {
            $hostHashByName[$host["name"]] = $host;
        }

        // get currently linked
        $currentLinks = $this->find();
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
                if (isset($linkHash[$obj[$index]->getType()][$asset["id"]])) continue;

                // check if monitoring host is available
                $host = $hostHashByName[$asset["name"]] ?? null;
                if (isset($host)) {
                    $link = [
                        "itemtype" => $obj[$index]->getType(),
                        "item_id" => $asset["id"],
                        "monitoring_id" => $host["id"],
                        "monitoring_name" => $host["name"]
                    ];
                    $this->add($link);
                    $linkHashByHostId[$host["id"]] = $link;
                    $linkHash[$obj[$index]->getType()][$asset["id"]] = $link;
                    $cnt++;
                }
            }
        }

        $config = Config::getConfig();
        if ($config["monitoring-sync"] === "1" && !empty($config["itam-url"])) {
            foreach ($hosts as $host) {
                $link = $linkHashByHostId[$host["id"]] ?? null;
                if (isset($link)) {
                    // host is linked - update notes url
                    $item = getItemForItemtype($link["itemtype"]);
                    if ($item !== false) {
                        $baseUrl = substr($config["itam-url"], -1) === "/" ? $config["itam-url"] : $config["itam-url"] . "/";
                        $url = substr($item::getFormURLWithID($link["item_id"]), 1);
                        $this->api_client->updateHost($host["id"], ["json" => [
                            "note_url" => $baseUrl . $url
                        ]]);
                    }
                } else {
                    // host is not linked
                }
            }
        }

        return $cnt;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
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

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof CommonDBTM) {
            return self::showForItem($item, $withtemplate);
        }

        return true;
    }

    public static function showForItem(CommonDBTM $item, $withtemplate = 0)
    {
        $self = new self();
        $item_id = $item->getID();
        $item_type = $item->getTypeClass();
        if ($self->searchForItem($item_id, $item_type) == true || $self->searchItemMatch($item_id, $item_type) == true) {
            $host_id = $self->fields['monitoring_id'];
            $self->getHostById($host_id);
            $user = $self->api_client->getAuthUser();
            TemplateRenderer::getInstance()->display('@ivertixmonitoring/host.html.twig', [
                'one_host' => $self->one_host,
                'hostid' => $host_id,
                'uid' => $user["id"],
                'username' => $user["name"],
                'logo' => Plugin::getWebDir('ivertixmonitoring') . '/files/logo-ivertixmonitoring.png',
            ]);
        } else {
            TemplateRenderer::getInstance()->display('@ivertixmonitoring/nohost.html.twig');
        }
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        switch ($field) {
            case 'id':
                if ((int)$values['monitoring_id'] > 0) {
                    $self = new self();
                    $res = $self->getHostById($values['monitoring_id']);

                    return $res['status'] ?? '';
                }
                break;
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }
}
