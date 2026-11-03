# Docker dev environment

GLPI 12 (`glpi/glpi:12.0.1`, port 8013) with this repo mounted as the plugin, a MariaDB and a small fake of the
i-Vertix Monitoring API (`mock-api`, port 8080). Compose project name: `ivertixmonitoring` (does not collide with the
simcard environment).

All commands are run from the repo root. Shorthand used below:

    dc="docker compose -f tools/docker/docker-compose.yml"

Git Bash on Windows rewrites absolute paths in arguments (`/var/glpi` becomes `C:/Program Files/Git/var/glpi`).
Prefix such commands with `MSYS_NO_PATHCONV=1` (or `export MSYS_NO_PATHCONV=1`).

## Start

    $dc up -d glpi12 mock-api
    $dc exec glpi12 php bin/console plugin:install ivertixmonitoring -u glpi
    $dc exec glpi12 php bin/console plugin:activate ivertixmonitoring
    # http://127.0.0.1:8013, login glpi / glpi (ports are bound to 127.0.0.1 only)

The database is installed automatically on the first start (takes about a minute).

Plugin settings (`Setup -> General -> i-Vertix Monitoring settings`):

- URL: `http://mock-api:8080/centreon/api/latest/`
- username / password: `admin` / `admin`

The mock knows the hosts `glpi-srv-01` (UP), `core-switch-01` (DOWN), `mail-srv-01` (UNREACHABLE) and `host-04` to
`host-40`. Create assets with the same names to test the automatic link. Its state (downtimes, acknowledgements, note
URLs, checks) is kept in `/tmp/mock-api-state.json` in the container; every request is logged (`$dc logs mock-api`).

## Private network restriction

GLPI 12 blocks server-side requests to private networks. `local_define.php` is mounted as
`/var/www/glpi/config/local_define.php` and allows the plugin context, so the plugin can reach the mock API.
To test the blocked case, comment out the plugin line and recreate the container (a single-file bind mount does not
see an edited file): `$dc up -d --force-recreate glpi12`.

## Templates

After editing templates, clear the Twig cache:

    $dc exec glpi12 php bin/console cache:clear

## Logs

Inside the container, in `/var/glpi/logs/`: `php-errors.log`, `sql-errors.log`, ...

    MSYS_NO_PATHCONV=1 $dc exec glpi12 tail -n 50 /var/glpi/logs/php-errors.log

## SQL

    $dc exec db mariadb -uglpi -pglpi glpi -e "SELECT * FROM glpi_plugin_ivertixmonitoring_hosts"

## Stop / reset

    $dc stop          # keeps volumes
    $dc down -v       # removes containers AND volumes
