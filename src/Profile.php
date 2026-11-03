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

use CommonGLPI;
use Html;
use Profile as Glpi_Profile;
use ProfileRight;

/**
 * Plugin rights tab on GLPI profiles.
 *
 * The right `Host::$rightname` has these bits:
 * - READ: view the monitoring tab of assets
 * - UPDATE: link and unlink assets and monitoring hosts (single and "link all")
 * - Host::RIGHT_ACTION: send checks, set and cancel downtimes, acknowledge problems
 *
 * They are always combined with the right on the asset itself (READ to view, UPDATE to change).
 */
class Profile extends Glpi_Profile
{
    public static string $rightname = 'profile';

    public static function getTypeName($nb = 0)
    {
        return __('i-Vertix Monitoring', 'ivertixmonitoring');
    }

    public static function getIcon()
    {
        return 'ti ti-heart-rate-monitor';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Glpi_Profile && $item->fields['interface'] === 'central') {
            return self::createTabEntry(self::getTypeName(), 0, $item::getType(), self::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Glpi_Profile) {
            (new self())->showForm($item->getID());
        }
        return true;
    }

    public function showForm($ID, array $options = [])
    {
        if (!Glpi_Profile::canView()) {
            return false;
        }
        $canedit = Glpi_Profile::canUpdate();
        $profile = new Glpi_Profile();
        $profile->getFromDB($ID);

        if ($canedit) {
            echo "<form action='" . htmlescape($profile->getFormURL()) . "' method='post'>";
        }

        $profile->displayRightsChoiceMatrix([
            [
                'itemtype' => Host::class,
                'label'    => __('i-Vertix Monitoring', 'ivertixmonitoring'),
                'field'    => Host::$rightname,
            ],
        ], [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
        ]);

        if ($canedit) {
            echo "<div class='center'>";
            echo "<input type='hidden' name='id' value='" . (int)$ID . "'>";
            echo "<input type='submit' name='update' value=\"" . htmlescape(_sx('button', 'Save')) . "\" class='btn btn-primary'>";
            echo "</div>";
            Html::closeForm();
        }
        return true;
    }

    /**
     * Add the plugin right to all profiles (install and upgrade).
     *
     * When the right does not exist yet, it is derived from the asset rights the plugin relied on before:
     * profiles that can update computers or network devices get all plugin rights, profiles that can read them
     * get READ. This keeps the behaviour of existing installations.
     */
    public static function installRights(): void
    {
        if (countElementsInTable(ProfileRight::getTable(), ['name' => Host::$rightname]) > 0) {
            return;
        }
        ProfileRight::addProfileRights([Host::$rightname]);

        $all = READ | UPDATE | Host::RIGHT_ACTION;
        foreach ((new Glpi_Profile())->find() as $profile) {
            $assetRights = ProfileRight::getProfileRights($profile['id'], ['computer', 'networking']);
            $assetRights = (int)($assetRights['computer'] ?? 0) | (int)($assetRights['networking'] ?? 0);

            $rights = 0;
            if ($assetRights & UPDATE) {
                $rights = $all;
            } elseif ($assetRights & READ) {
                $rights = READ;
            }
            if ($rights === 0) {
                continue;
            }
            ProfileRight::updateProfileRights($profile['id'], [Host::$rightname => $rights]);

            // the session profile was loaded before the right existed
            if (($_SESSION['glpiactiveprofile']['id'] ?? null) == $profile['id']) {
                $_SESSION['glpiactiveprofile'][Host::$rightname] = $rights;
            }
        }
    }

    public static function uninstallRights(): void
    {
        ProfileRight::deleteProfileRights([Host::$rightname]);
        unset($_SESSION['glpiactiveprofile'][Host::$rightname]);
    }
}
