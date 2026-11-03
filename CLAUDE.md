# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A GLPI plugin (`ivertixmonitoring`) that shows real-time data from the i-Vertix Monitoring (Centreon) REST API on GLPI assets. It adds an "i-Vertix Monitoring" tab to **Computers** and **Network devices**, plus a settings tab under `Setup -> General`. The branch `12-next` targets **GLPI 12 only** (`setup.php` pins min `12.0.0`, max `12.0.99`, PHP >= 8.3). GLPI 11 stays on `11-next` (plugin 11.0.x).

The plugin must live in a GLPI install's `plugins/ivertixmonitoring/` folder to run. There is no test suite and no lint config; test in the Docker dev environment.

## Commands

```bash
dc="docker compose -f tools/docker/docker-compose.yml"
$dc up -d glpi12 mock-api                                          # GLPI 12 on http://127.0.0.1:8013 (glpi/glpi) + fake monitoring API
$dc exec glpi12 php bin/console plugin:install ivertixmonitoring -u glpi
$dc exec glpi12 php bin/console plugin:activate ivertixmonitoring
$dc exec glpi12 php bin/console cache:clear                         # after editing templates
$dc exec glpi12 php -l /var/www/glpi/plugins/ivertixmonitoring/src/Host.php
```

```bash
./vendor/bin/extract-locales   # regenerate locales/ivertixmonitoring.pot + en_GB.po (needs PHP, xgettext, msginit; Windows: Git Bash with gettext in PATH)
```

In Git Bash, prefix commands that pass absolute container paths with `MSYS_NO_PATHCONV=1`. See `tools/docker/README.md` (mock credentials: URL `http://mock-api:8080/centreon/api/latest/`, `admin`/`admin` or API token `mock-api-token`).

## Architecture

- **`setup.php` / `hook.php`**: standard GLPI plugin entry points. `setup.php` registers the tabs (`addtabon` => `Host::LINKABLE_ITEMTYPES`), marks `monitoring-password` and `monitoring-api-token` as secured configs (encrypted with the GLPI key), hooks `PRE_ITEM_UPDATE` on `Config` to normalize the settings input, registers a proxy exclusion for the plugin context, and loads `public/css/ivertixmonitoring.css` only on the Config, Computer and NetworkEquipment form pages. `hook.php` creates and drops the single table `glpi_plugin_ivertixmonitoring_hosts` (itemtype, **`item_id`** (not `items_id`) → monitoring_id, monitoring_type), adds missing config defaults (`Config::DEFAULTS`) on install/upgrade, and adds search option `6942` (host status column, needs `specific_items_id_column => 'item_id'`).
- **`src/` (namespace `GlpiPlugin\Ivertixmonitoring`, PSR-4)**:
  - `ApiClient`: wrapper around the monitoring API using `Glpi\Toolbox\HttpClient` (Symfony HttpClient; GLPI 12 no longer ships Guzzle) with context `Config::class`. That context is what admins add to `GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS`, otherwise private IPs are blocked. `clientRequest()` never throws: it returns `['success', 'status', 'result']` and sets `getLastError()` (translated) / `isPrivateNetworkBlocked()`. Authenticates lazily, once per instance (a failed login is remembered): with an API token (Centreon 24.10+, `Config::usesApiToken()`, takes precedence) it calls `GET configuration/users/current/parameters` to check the token and get the contact; otherwise `POST login`. Both tokens are sent as `X-AUTH-TOKEN`. TLS verification follows the `monitoring-verify-tls` setting.
  - `Host` (a `CommonDBTM`): the link record plus all business logic (host detail, timeline, downtimes, acknowledge, forced checks, link/link-all). Instances share one static `ApiClient` per request (one login per request). Methods are **stateful**: `isItemLinked($id, $type)` loads the link row into `$this->fields`, and the API methods (`getHostDetail`, `sendHostCheck`, `acknowledge`, …) then use `$this->fields['monitoring_id']`. `getStatusLabels()` translates API states (UP, DOWN, OK, …); dates are formatted with `formatDate()` (user timezone and date format).
  - `Config`: the settings tab on GLPI's `Config`. Values live in config context `Config::CONTEXT` (`plugin:ivertixmonitoring`): `monitoring-url`, `monitoring-username`, `monitoring-password`, `monitoring-api-token`, `monitoring-verify-tls`, `monitoring-sync`. `prepareConfigUpdate` only touches updates of this context: `prepareAuthenticationUpdate()` stores either the API token or username+password (saving one empties the other, both at once is refused; empty secret fields keep the stored value), validates and normalizes the URL. GLPI stores every input key of a plugin context, so never add helper fields to the form without unsetting them there. Secret fields are `type="text"` masked with `-webkit-text-security` so browsers don't suggest/save passwords.
- **Rights** (`Profile`, tab on GLPI profiles): right `plugin_ivertixmonitoring_host` (`Host::$rightname`) with READ (view tab/search column), UPDATE (link/unlink, link-all) and `Host::RIGHT_ACTION` (check, downtime, acknowledge). Always combined with the asset right (READ for view/actions, UPDATE for linking; `can()` also checks the entity). `Host::canLinkMonitoringHost()` refuses a host already linked to an asset the user cannot update. `Profile::installRights()` derives the initial rights from the computer/networking rights (only when the right does not exist yet).
- **Linking**: one link per asset (UNIQUE `unicity` on itemtype+item_id; the 12.0.0 upgrade removes duplicates). Links are made in the tab's host dropdown (clearing it removes the link) or in bulk with `Host::linkAll()` (exact name match, skips deleted assets and templates). When "Sync asset link to monitoring" is enabled, linking writes the GLPI asset URL into the host's `note_url` via `ApiClient::updateHost`.
- **`ajax/*.php`**: endpoints called by the templates' jQuery (`CFG_GLPI.root_doc + '/plugins/ivertixmonitoring/ajax/...'`). Pattern: `Session::checkLoginUser()`, `Host::checkPostRequest()` on every endpoint that changes data (GLPI 12 only checks CSRF on POST/PUT/PATCH/DELETE, a GET would bypass it), then `Host::getRequestedLink($itemRight, $pluginRight)` (validates `itemtype`/`item_id`, checks plugin and asset rights, loads the link) or `Host::getRequestedItem()`. Validate and bound every input; on failure throw `Glpi\Exception\Http\*HttpException` (400/403/404/405); end with `echo`, not `die`.
- **API data is untrusted**: cast ids to int before they reach templates/JS, read optional keys with `??`; the `ApiClient` never follows redirects (token/password leak).
- **`templates/*.html.twig`**: rendered via `TemplateRenderer::getInstance()->display('@ivertixmonitoring/<name>.html.twig', ...)`. `host.html.twig` is the main tab view; the modal and timeline templates are loaded into it.
- **`public/`**: GLPI serves plugin static files from here (`/plugins/ivertixmonitoring/css/...`, `/images/...`). Don't put `public/` in the URLs.
- **Paths**: use `$CFG_GLPI['root_doc'] . '/plugins/ivertixmonitoring/...'` for URLs. Don't hard-code `marketplace/` or build URLs relative to the current page.

## Translations

Languages: en_GB, de_DE, it_IT. `vendor/bin/extract-locales` (glpi-project/tools) writes the `.pot` and `en_GB.po`; de_DE/it_IT are merged and translated by hand (Poedit: update from POT, save compiles the `.mo`). Rules:

- Every string uses the domain: `__('...', 'ivertixmonitoring')`, `_n(..., 'ivertixmonitoring')`, also for strings GLPI itself has. Strings without domain are not extracted.
- Placeholders instead of concatenation: `sprintf(__('From: %s', 'ivertixmonitoring'), $x)`, Twig `__('From: %s', 'ivertixmonitoring')|format(x)`; numbered (`%1$s`) when several.
- Inside `<script>`: `"{{ __('...', 'ivertixmonitoring')|e('js') }}"`; `{{ itemtype|url_encode }}` in URLs.
- After adding strings, translate them in de_DE.po and it_IT.po and commit `.pot`, `.po` and `.mo`.

## Conventions

- Every PHP file starts with the GPLv3 license header block (copy it from an existing file).
- JSON helpers come from `Safe\` (`use function Safe\json_encode;`), as in the existing classes.
- Override GLPI methods with signatures compatible with GLPI 12's typed `CommonGLPI`/`CommonDBTM`; don't redeclare core properties (e.g. `$rightname`) without the exact core type.
- Bump `PLUGIN_IVERTIXMONITORING_VERSION` in `setup.php` for a release.
