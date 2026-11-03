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

use CommonDBTM;
use CommonGLPI;
use Computer;
use DateTimeImmutable;
use DateTimeZone;
use Dropdown;
use Exception;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Exception\Http\HttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use Html;
use NetworkEquipment;
use Session;

use function Safe\json_encode;

class Host extends CommonDBTM
{
    /**
     * Plugin right, see Profile.
     */
    public static string $rightname = 'plugin_ivertixmonitoring_host';

    /**
     * Right bit to send checks, set/cancel downtimes and acknowledge problems.
     */
    public const RIGHT_ACTION = 128;

    /**
     * Asset types that can be linked to a monitoring host.
     */
    public const LINKABLE_ITEMTYPES = [Computer::class, NetworkEquipment::class];

    /**
     * Longest downtime that can be set from GLPI (one year).
     */
    public const MAX_DOWNTIME_SECONDS = 31536000;

    /**
     * Upper bound of pages read from the API in getHostListByHostId(), in case the API ignores the page parameter.
     */
    private const MAX_PAGES = 1000;

    private ApiClient $api_client;

    /**
     * API client shared by all instances of a request, so the API login is done only once.
     */
    private static ?ApiClient $shared_api_client = null;

    public function __construct(?ApiClient $api_client = null)
    {
        parent::__construct();
        $this->api_client = $api_client ?? (self::$shared_api_client ??= new ApiClient());
    }

    public static function getTypeName($nb = 0): string
    {
        return __('i-Vertix Monitoring', 'ivertixmonitoring');
    }

    public static function getIcon()
    {
        return 'ti ti-heart-rate-monitor';
    }

    public function getRights($interface = 'central')
    {
        return [
            READ => __('Read', 'ivertixmonitoring'),
            UPDATE => [
                'short' => __('Link hosts', 'ivertixmonitoring'),
                'long' => __('Link and unlink assets and monitoring hosts', 'ivertixmonitoring'),
            ],
            self::RIGHT_ACTION => [
                'short' => __('Run actions', 'ivertixmonitoring'),
                'long' => __('Send checks, set and cancel downtimes, acknowledge problems', 'ivertixmonitoring'),
            ],
        ];
    }

    public static function canRunActions(): bool
    {
        return Session::haveRight(self::$rightname, self::RIGHT_ACTION);
    }

    /**
     * Translated labels of the host and service states returned by the monitoring API.
     *
     * @return array<string, string>
     */
    public static function getStatusLabels(): array
    {
        return [
            'UP' => __('Up', 'ivertixmonitoring'),
            'DOWN' => __('Down', 'ivertixmonitoring'),
            'UNREACHABLE' => __('Unreachable', 'ivertixmonitoring'),
            'OK' => __('OK', 'ivertixmonitoring'),
            'WARNING' => __('Warning', 'ivertixmonitoring'),
            'CRITICAL' => __('Critical', 'ivertixmonitoring'),
            'UNKNOWN' => __('Unknown', 'ivertixmonitoring'),
            'PENDING' => __('Pending', 'ivertixmonitoring'),
        ];
    }

    public static function getStatusLabel(string $status): string
    {
        return self::getStatusLabels()[$status] ?? $status;
    }

    /**
     * All monitoring hosts, indexed by id.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getHostListByHostId(): array
    {
        $api = $this->api_client;
        if (!$api->authenticate()) {
            return [];
        }
        $items = [];
        $limit = 300;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $list = $api->getHosts(["limit" => $limit, "page" => $page]);
            $result = $list["success"] ? ($list["result"]["result"] ?? null) : null;
            if (!is_array($result)) {
                break;
            }
            $before = count($items);
            foreach ($result as $item) {
                if (isset($item["id"], $item["name"])) {
                    $items[(int)$item["id"]] = ["id" => (int)$item["id"], "name" => (string)$item["name"]];
                }
            }
            $total = $list["result"]["meta"]["total"] ?? null;
            if (
                count($result) < $limit
                || count($items) === $before // page parameter ignored by the API
                || (is_numeric($total) && count($items) >= (int)$total)
            ) {
                break;
            }
        }
        return $items;
    }

    /**
     * Monitoring hosts for the host dropdown.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function getHostList(?string $name, int $limit, int $page): array
    {
        $api = (new self())->api_client;
        if (!$api->authenticate()) {
            return [];
        }
        $query = ["limit" => $limit, "page" => $page];
        if ($name !== null && $name !== '') {
            $query['search'] = json_encode([
                'host.name' => [
                    '$lk' => "%" . $name . "%",
                ],
            ]);
        }
        $list = $api->getHosts($query);
        $items = [];
        foreach (($list["success"] ? ($list["result"]["result"] ?? []) : []) as $item) {
            if (isset($item["id"], $item["name"])) {
                $items[] = ["id" => (int)$item["id"], "name" => (string)$item["name"]];
            }
        }
        return $items;
    }

    /**
     * Id and name of a monitoring host, or null when it does not exist (or the API is not available).
     *
     * @return array{id: int, name: string}|null
     */
    public function getMonitoringHost(int $hostId): ?array
    {
        if (!$this->api_client->authenticate()) {
            return null;
        }
        $host = $this->api_client->getHostById($hostId);
        if (!$host["success"] || !isset($host["result"]["id"], $host["result"]["name"])) {
            return null;
        }
        return ["id" => (int)$host["result"]["id"], "name" => (string)$host["result"]["name"]];
    }

    public function getHostListItem(): ?array
    {
        if (!isset($this->fields["monitoring_id"])) {
            return null;
        }
        return $this->getMonitoringHost((int)$this->fields["monitoring_id"]);
    }

    public function getHostDetail(): ?array
    {
        if (!isset($this->fields["monitoring_id"]) || !$this->api_client->authenticate()) {
            return null;
        }
        $hostId = (int)$this->fields["monitoring_id"];
        $hostDetail = $this->api_client->getHostById($hostId);
        $hostResources = $this->api_client->getHostResourceDetailById($hostId);
        $hostServices = $this->api_client->getServicesByHostId($hostId);
        if (
            !$hostDetail["success"] || !$hostResources["success"] || !$hostServices["success"]
            || !is_array($hostDetail["result"]) || !is_array($hostResources["result"])
        ) {
            return null;
        }
        $hostDetail = $hostDetail["result"];
        $hostResources = $hostResources["result"];

        $acknowledgement = $hostResources["acknowledgement"] ?? null;
        $host = [
            'status' => (string)($hostResources['status']['name'] ?? ''),
            'name' => (string)($hostResources['name'] ?? $hostDetail['name'] ?? ''),
            'alias' => (string)($hostDetail['alias'] ?? ''),
            'fqdn' => (string)($hostResources['fqdn'] ?? ''),
            'last_check' => self::formatDate($hostDetail['last_check'] ?? null),
            'next_check' => self::formatDate($hostDetail['next_check'] ?? null),
            'check_period' => (string)($hostDetail['check_period'] ?? ''),
            'in_downtime' => (bool)($hostResources['in_downtime'] ?? false),
            'acknowledged' => is_array($acknowledgement) ? self::formatAcknowledgement($acknowledgement) : false,
            'downtimes' => [],
        ];
        if ($host['in_downtime'] && is_array($hostResources['downtimes'] ?? null)) {
            foreach ($hostResources['downtimes'] as $downtime) {
                // the id is used in the HTML and JS of the template: only accept integers
                if (!is_array($downtime) || !is_numeric($downtime['id'] ?? null)) {
                    continue;
                }
                $host['downtimes'][] = [
                    'id' => (int)$downtime['id'],
                    'start_time' => self::formatDate($downtime['start_time'] ?? null),
                    'end_time' => self::formatDate($downtime['end_time'] ?? null),
                    'author_name' => (string)($downtime['author_name'] ?? ''),
                    'comment' => (string)($downtime['comment'] ?? ''),
                ];
            }
        }

        $services = is_array($hostServices["result"]["result"] ?? null) ? $hostServices["result"]["result"] : [];
        // critical, warning, unknown, pending first
        $statusOrderMap = array_flip([2, 1, 3, 4]);
        usort($services, static function ($a, $b) use ($statusOrderMap) {
            $posA = $statusOrderMap[$a["state"] ?? null] ?? PHP_INT_MAX;
            $posB = $statusOrderMap[$b["state"] ?? null] ?? PHP_INT_MAX;
            return $posA <=> $posB;
        });
        $host['services'] = $services;
        $host['nb_services'] = count($services);
        return $host;
    }

    /**
     * Build the tooltip text of a host acknowledgement.
     */
    private static function formatAcknowledgement(array $acknowledgement): string
    {
        $text = sprintf(
            __('Problem acknowledged by %1$s on %2$s', 'ivertixmonitoring'),
            (string)($acknowledgement["author_name"] ?? ''),
            self::formatDate($acknowledgement["entry_time"] ?? null)
        );
        $comment = trim((string)($acknowledgement["comment"] ?? ""));
        return $comment !== "" ? $text . " - " . $comment : $text;
    }

    /**
     * Get the status name (UP, DOWN, ...) of a monitoring host, using one API request.
     */
    public function getHostStatus(int $hostId): ?string
    {
        if (!$this->api_client->authenticate()) {
            return null;
        }
        $hostResources = $this->api_client->getHostResourceDetailById($hostId);
        $status = $hostResources["success"] ? ($hostResources["result"]["status"]["name"] ?? null) : null;
        return is_string($status) ? $status : null;
    }

    public function getTimeline(string $period): ?array
    {
        if (!isset($this->fields["monitoring_id"]) || !$this->api_client->authenticate()) {
            return null;
        }
        $response = $this->api_client->getHostTimelineById((int)$this->fields["monitoring_id"]);
        if (!$response["success"]) {
            return null;
        }
        $minDate = new DateTimeImmutable(match ($period) {
            'week' => 'today -7 days',
            'month' => 'today -1 month',
            default => 'today -1 day',
        });

        $timeline = [];
        foreach (($response["result"]["result"] ?? []) as $event) {
            $date = self::parseDate($event['date'] ?? null);
            if ($date === null || $date < $minDate) {
                continue;
            }
            $timeline[] = [
                'date' => self::formatDate($event['date']),
                'content' => (string)($event['content'] ?? ''),
                'status' => (string)($event['status']['name'] ?? $event['type'] ?? ''),
                'tries' => $event['tries'] ?? "",
            ];
        }
        return $timeline;
    }

    /**
     * Parse a date (ISO 8601 from the API or the browser), null when it is not valid.
     */
    public static function parseDate(mixed $date): ?DateTimeImmutable
    {
        if (!is_string($date) || $date === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($date);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Format a date of the monitoring API with the timezone and date format of the current GLPI user.
     */
    private static function formatDate(?string $date): string
    {
        $dateTime = self::parseDate($date);
        if ($dateTime === null) {
            return (string)$date;
        }
        // GLPI sets the PHP default timezone to the timezone of the user
        $dateTime = $dateTime->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return (string)Html::convDateTime($dateTime->format('Y-m-d H:i:s'));
    }

    public function sendHostCheck(): bool
    {
        if (!isset($this->fields["monitoring_id"]) || !$this->api_client->authenticate()) {
            return false;
        }
        return $this->api_client->sendHostCheck((int)$this->fields["monitoring_id"])["success"];
    }

    /**
     * @param DateTimeImmutable $start start of the downtime
     * @param DateTimeImmutable $end end of the downtime
     * @param bool $isFixed fixed downtime (from start to end), otherwise flexible with $duration
     * @param int|null $duration duration of a flexible downtime in seconds
     */
    public function setDowntime(DateTimeImmutable $start, DateTimeImmutable $end, bool $isFixed, ?int $duration, string $comment, bool $withServices): bool
    {
        if (!isset($this->fields["monitoring_id"]) || !$this->api_client->authenticate()) {
            return false;
        }
        $downtime = [
            "is_fixed" => $isFixed,
            "start_time" => $start->format(DATE_ATOM),
            "end_time" => $end->format(DATE_ATOM),
            "duration" => $isFixed ? $end->getTimestamp() - $start->getTimestamp() : (int)$duration,
            "comment" => $comment,
            "with_services" => $withServices,
        ];
        return $this->api_client->setHostDowntime((int)$this->fields["monitoring_id"], $downtime)["success"];
    }

    public static function convertDuration(string $unit, int $value): int
    {
        return match ($unit) {
            "m" => $value * 60,
            "h" => $value * 3600,
            default => $value,
        };
    }

    public function cancelDowntime(int $downtimeId): bool
    {
        if (!isset($this->fields["monitoring_id"]) || !$this->api_client->authenticate()) {
            return false;
        }
        $api = $this->api_client;
        $hostDowntime = $api->getDowntimeById($downtimeId);
        if (!$hostDowntime["success"] || !isset($hostDowntime["result"]["host_id"])) {
            return false;
        }
        $hostDowntime = $hostDowntime["result"];
        // only downtimes of the linked host can be canceled
        if ((int)$this->fields["monitoring_id"] !== (int)$hostDowntime["host_id"]) {
            return false;
        }

        // cancel the service downtimes that were set together with the host downtime
        $servicesDowntimes = $api->getServiceDowntimesByHostId((int)$hostDowntime['host_id'], 200);
        foreach (($servicesDowntimes["success"] ? ($servicesDowntimes["result"]["result"] ?? []) : []) as $serviceDowntime) {
            if (
                isset($serviceDowntime['id'], $serviceDowntime['start_time'], $serviceDowntime['end_time'])
                && $serviceDowntime['start_time'] === ($hostDowntime['start_time'] ?? null)
                && $serviceDowntime['end_time'] === ($hostDowntime['end_time'] ?? null)
            ) {
                $api->cancelDowntimeById((int)$serviceDowntime['id']);
            }
        }
        return $api->cancelDowntimeById($downtimeId)["success"];
    }

    public function acknowledge(string $comment, bool $isNotifyContacts, bool $isPersistentComment, bool $isSticky, bool $withServices): bool
    {
        if (!isset($this->fields["monitoring_id"]) || !$this->api_client->authenticate()) {
            return false;
        }
        return $this->api_client->acknowledgeHostById((int)$this->fields["monitoring_id"], [
            "comment" => $comment,
            "is_notify_contacts" => $isNotifyContacts,
            "is_persistent_comment" => $isPersistentComment,
            "is_sticky" => $isSticky,
            "with_services" => $withServices,
        ])["success"];
    }

    /**
     * Reject other methods than POST on endpoints that change data
     * (GLPI only checks CSRF on POST, PUT, PATCH and DELETE requests).
     *
     * @throws HttpException
     */
    public static function checkPostRequest(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? null) !== 'POST') {
            throw new HttpException(405, "Method not allowed");
        }
    }

    /**
     * Read and validate the `itemtype` and `item_id` parameters of an ajax request, then check the plugin right and the
     * user's right on the item (which also checks the entity).
     *
     * @param int $itemRight right required on the asset (READ or UPDATE)
     * @param int $pluginRight plugin right required (READ, UPDATE or RIGHT_ACTION)
     * @return array{0: string, 1: int} item type and item id
     * @throws BadRequestHttpException
     * @throws AccessDeniedHttpException
     */
    public static function getRequestedItem(int $itemRight, int $pluginRight): array
    {
        if (!Session::haveRight(self::$rightname, $pluginRight)) {
            throw new AccessDeniedHttpException();
        }

        $itemType = $_GET["itemtype"] ?? null;
        $itemId = $_GET["item_id"] ?? null;
        if (!is_string($itemType) || !in_array($itemType, self::LINKABLE_ITEMTYPES, true)) {
            throw new BadRequestHttpException("Missing or invalid parameter: 'itemtype'");
        }
        if (!is_string($itemId) || !ctype_digit($itemId)) {
            throw new BadRequestHttpException("Missing or invalid parameter: 'item_id'");
        }
        $itemId = (int)$itemId;

        $item = getItemForItemtype($itemType);
        if ($item === false || !$item->can($itemId, $itemRight)) {
            throw new AccessDeniedHttpException();
        }

        return [$itemType, $itemId];
    }

    /**
     * Same as getRequestedItem(), then load the link of the requested item.
     *
     * @return self the loaded link
     * @throws BadRequestHttpException
     * @throws AccessDeniedHttpException
     * @throws NotFoundHttpException when the item is not linked to a monitoring host
     */
    public static function getRequestedLink(int $itemRight, int $pluginRight): self
    {
        [$itemType, $itemId] = self::getRequestedItem($itemRight, $pluginRight);

        $host = new self();
        if (!$host->isItemLinked($itemId, $itemType)) {
            throw new NotFoundHttpException("No linked monitoring host found for item");
        }
        return $host;
    }

    public function isItemLinked(int $id, string $type): bool
    {
        return $this->getFromDBByCrit(['item_id' => $id, 'itemtype' => $type]);
    }

    /**
     * Whether the current user may link an asset to the given monitoring host: the host must not be linked to an asset
     * the user cannot update (e.g. in another entity), as the actions and the note URL would affect that asset too.
     */
    public function canLinkMonitoringHost(int $hostId): bool
    {
        foreach ($this->find(['monitoring_id' => $hostId]) as $link) {
            $item = getItemForItemtype($link['itemtype']);
            if ($item === false || !$item->can((int)$link['item_id'], UPDATE)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Link an asset to a monitoring host, or remove the link when $hostId is null.
     * When "Sync asset link to monitoring" is enabled, the note URL of the monitoring host is updated.
     */
    public function linkItemToMonitoringHostByHostId(int $id, string $type, ?int $hostId): bool
    {
        global $CFG_GLPI;

        $config = Config::getConfig();
        $item = getItemForItemtype($type);
        if ($item === false) {
            return false;
        }

        if ($this->isItemLinked($id, $type)) {
            $oldHostId = (int)$this->fields["monitoring_id"];
            if ($oldHostId === $hostId) {
                // already linked to this host
                return true;
            }
            $this->deleteByCriteria(['item_id' => $id, 'itemtype' => $type]);
            if ($config["monitoring-sync"] === "1") {
                $this->api_client->updateHost($oldHostId, ["note_url" => null]);
            }
        }
        if ($hostId === null) {
            return true;
        }
        $newId = $this->add([
            'itemtype' => $type,
            'item_id' => $id,
            'monitoring_id' => $hostId,
            'monitoring_type' => 'host',
        ]);
        if (!is_int($newId) || $newId <= 0) {
            return false;
        }
        $this->getFromDB($newId);
        if ($config["monitoring-sync"] === "1") {
            $url = substr($item::getFormURLWithID($id), 1);
            $this->api_client->updateHost($hostId, ["note_url" => $CFG_GLPI['url_base'] . "/" . $url]);
        }
        return true;
    }

    /**
     * Link all assets (not deleted, no templates) without link to the monitoring host with the same name.
     * When "Sync asset link to monitoring" is enabled, the note URL of all linked monitoring hosts is updated.
     *
     * @return int number of new links
     */
    public static function linkAll(): int
    {
        global $CFG_GLPI;

        $self = new self();
        if (!$self->api_client->authenticate()) {
            return 0;
        }

        $hosts = $self->getHostListByHostId();
        $hostByName = [];
        foreach ($hosts as $host) {
            $hostByName[$host["name"]] = $host;
        }

        $linkByItem = [];
        $linkByHostId = [];
        foreach ($self->find() as $link) {
            $linkByItem[$link["itemtype"]][$link["item_id"]] = $link;
            $linkByHostId[$link["monitoring_id"]] = $link;
        }

        $cnt = 0;
        foreach (self::LINKABLE_ITEMTYPES as $itemtype) {
            $assets = (new $itemtype())->find(["is_deleted" => 0, "is_template" => 0]);
            foreach ($assets as $asset) {
                $host = $hostByName[$asset["name"]] ?? null;
                if ($host === null || isset($linkByItem[$itemtype][$asset["id"]])) {
                    continue;
                }
                $link = [
                    "itemtype" => $itemtype,
                    "item_id" => $asset["id"],
                    "monitoring_id" => $host["id"],
                    "monitoring_type" => "host",
                ];
                if ($self->add($link)) {
                    $linkByHostId[$host["id"]] = $link;
                    $linkByItem[$itemtype][$asset["id"]] = $link;
                    $cnt++;
                }
            }
        }

        if (Config::getConfig()["monitoring-sync"] === "1") {
            foreach ($hosts as $host) {
                $link = $linkByHostId[$host["id"]] ?? null;
                $item = $link !== null ? getItemForItemtype($link["itemtype"]) : false;
                if ($item !== false) {
                    $url = substr($item::getFormURLWithID($link["item_id"]), 1);
                    $self->api_client->updateHost($host["id"], ["note_url" => $CFG_GLPI['url_base'] . "/" . $url]);
                }
            }
        }

        return $cnt;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!($item instanceof CommonDBTM) || !self::canView()) {
            return '';
        }
        $nb = countElementsInTable(self::getTable(), [
            'item_id' => $item->getID(),
            'itemtype' => $item->getType(),
        ]);
        return self::createTabEntry(self::getTypeName($nb), $nb, $item::getType(), self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof CommonDBTM && self::canView()) {
            self::showForItem($item);
        }
        return true;
    }

    public static function showForItem(CommonDBTM $item): void
    {
        global $CFG_GLPI;

        $self = new self();
        $item_id = $item->getID();
        $item_type = $item->getType();

        $user = $self->api_client->getAuthUser();
        if ($user === null) {
            TemplateRenderer::getInstance()->display('@ivertixmonitoring/noconnection.html.twig');
            return;
        }

        $linked = $self->isItemLinked($item_id, $item_type);
        $hostItem = $linked ? $self->getHostListItem() : null;
        $canLink = self::canUpdate() && $item->can($item_id, UPDATE);

        $rand = mt_rand();
        $hostDropdownId = Html::cleanId("dropdown_monitoring_host") . $rand;
        $hostDropdown = $canLink ? Html::jsAjaxDropdown(
            "monitoring_host",
            $hostDropdownId,
            $CFG_GLPI["root_doc"] . "/plugins/ivertixmonitoring/ajax/hostlist.php",
            [
                "value" => $hostItem ? $hostItem["id"] : 0,
                "valuename" => $hostItem ? $hostItem["name"] : Dropdown::EMPTY_VALUE,
                'on_change' => "onHostChange$rand(this.value)",
                'placeholder' => __('Linked monitoring host', 'ivertixmonitoring'),
            ]
        ) : '';
        TemplateRenderer::getInstance()->display('@ivertixmonitoring/selecthost.html.twig', [
            'can_link' => $canLink,
            'host_dropdown' => $hostDropdown,
            'host_dropdown_id' => $hostDropdownId,
            'host_name' => $hostItem["name"] ?? null,
            'rand' => $rand,
            'linked_host_id' => $hostItem ? (int)$hostItem["id"] : 0,
            'itemtype' => $item_type,
            'item_id' => $item_id,
        ]);

        if (!$linked) {
            TemplateRenderer::getInstance()->display('@ivertixmonitoring/nohost.html.twig');
            return;
        }
        $hostDetail = $self->getHostDetail();
        if (empty($hostDetail)) {
            // the linked host probably does not exist anymore
            TemplateRenderer::getInstance()->display('@ivertixmonitoring/invalidhost.html.twig');
            return;
        }
        TemplateRenderer::getInstance()->display('@ivertixmonitoring/host.html.twig', [
            'item_id' => $item_id,
            'itemtype' => $item_type,
            'host' => $hostDetail,
            'can_act' => self::canRunActions(),
            'user_name' => $user["alias"],
            'status_labels' => self::getStatusLabels(),
            'logo' => $CFG_GLPI['root_doc'] . '/plugins/ivertixmonitoring/images/logo-ivertix.png',
        ]);
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'id') {
            if (!self::canView() || !is_numeric($values['monitoring_id'] ?? null)) {
                return '';
            }
            $status = (new self())->getHostStatus((int)$values['monitoring_id']);
            return $status !== null ? htmlescape(self::getStatusLabel($status)) : '';
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }
}
