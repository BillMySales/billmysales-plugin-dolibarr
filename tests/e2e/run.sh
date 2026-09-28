#!/usr/bin/env bash
#
# BillMySales for Dolibarr: end-to-end tests (make e2e).
#
# Clones the Dolibarr Docker stack into var/e2e/stack, starts it with the
# module loaded, and runs each test case against a local webhook receiver
# (tests/e2e/receiver.php, standing in for BillMySales): the case does
# something in Dolibarr (through the real invoice card, the settings form,
# or the module's own scheduled job), the module sends the invoice, and
# tests/e2e/check.php checks what arrived (signature, headers, payload,
# the invoice's JSON Schema). Two phases: the module mounted from plugin/
# (cases 1-8), then the built zip installed through the real "Deploy/install
# external module" form (cases 9-10). At the end the stack (containers,
# volumes, clone) and the receiver are removed; the results stay in var/e2e
# until the next run or make clean: webhooks/<case>-<time>.body (raw body)
# and .json (headers, decoded payload, status answered), real deliveries to
# look at or to replay against BillMySales; stack.log. E2E_KEEP=1 also keeps
# the stack running (then make e2e-clean).
#
# Dolibarr has no official CLI for thirdparty/invoice fixtures (unlike some
# platforms' WP-CLI-alike tools): tests/e2e/fixtures.php, copied into the
# "dolibarr" container, bootstraps Dolibarr itself for the setup steps that
# need it (a thirdparty and a draft invoice, granting the development admin
# every permission, enabling a Dolibarr module). Everything a real user
# would do (validating or paying an invoice, saving the settings, resending
# a delivery, installing the zip) goes through plain HTTP with curl, cookies
# and the page's own CSRF tokens, exactly as a browser would submit them.
# The scheduled job is run once through Dolibarr's own CLI
# (scripts/cron/cron_run_jobs.php), not by waiting for the cron container's
# interval.
#
# Needs only Docker on the host. The stack's development ports and the
# receiver's (8099) must be free: stop the Dolibarr development stack.
#
# Environment: TOOLS_IMAGE (set by the Makefile), STACK_REPO, STACK_REF
# (default master), E2E_KEEP.

set -euo pipefail

PLATFORM=dolibarr
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
E2E="${ROOT}/var/e2e"
STACK="${E2E}/stack"
PROJECT="${PLATFORM}-e2e"
STACK_REPO="${STACK_REPO:-https://github.com/BillMySales/billmysales-docker-${PLATFORM}.git}"
STACK_REF="${STACK_REF:-master}"
TOOLS_IMAGE="${TOOLS_IMAGE:?Run it with make e2e}"
RECEIVER="${PROJECT}-receiver"
RECEIVER_PORT=8099
RECEIVER_URL="http://host.docker.internal:${RECEIVER_PORT}/"
SECRET="e2e-secret"
# A secret with characters that must survive the settings form and JSON.
ADMIN_SECRET='e2e "secret"\x'
VERSION="$(sed -n "s/^ *\$this->version *= *'\(.*\)';\$/\1/p" "${ROOT}/plugin/core/modules/modBillmysales.class.php")"
ZIP="${ROOT}/dist/module_billmysales-${VERSION}.zip"
FAILED=0
FROM=0

# --- Helpers -----------------------------------------------------------------

say() { printf '\n==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
fail() { printf '    ✘ %s\n' "$*"; FAILED=1; }
pass() { printf '    ✔ %s\n' "$*"; }
expect() { if [ "$1" = "$2" ]; then pass "$3"; else fail "$3 (got \"$1\", expected \"$2\")"; fi; }

compose() { (cd "${STACK}" && docker compose "$@"); }
# tests/e2e/fixtures.php inside the "dolibarr" container (copy_fixtures()):
# thirdparty/invoice creation, module activation, permissions, Configuration
# values.
fx() { compose exec -T dolibarr php e2e-fixtures.php "$@" 2>> "${E2E}/stack.log" | tail -1; }
# Runs Dolibarr's own scheduled-job CLI once, in the "cron" container (the
# module is mounted there too), exactly as the stack's cron container does
# every CRON_INTERVAL seconds (scripts/cron.sh).
cron() {
    local key
    key="$(compose exec -T cron php -r 'define("NOSESSION", 1); define("NOREQUIREHTML", 1); require "/var/www/dolibarr/htdocs/master.inc.php"; echo getDolGlobalString("CRON_KEY");' 2>> "${E2E}/stack.log")"
    # --force: Dolibarr's own scheduler only lets a job run once per its
    # configured frequency (5 minutes here), regardless of whether this
    # module's queue has anything due; forcing it is what makes the case
    # right after this one due immediately, instead of waiting for real time
    # to pass.
    compose exec -T cron php /var/www/dolibarr/scripts/cron/cron_run_jobs.php "${key}" firstadmin "${CRON_JOB_ID}" --force >> "${E2E}/stack.log" 2>&1
}
received() { find "${E2E}/webhooks" -name '*.json' | wc -l | tr -d ' '; }
respond() { echo "$1" > "${E2E}/respond"; }
port_in_use() { (exec 3<> "/dev/tcp/127.0.0.1/$1") 2> /dev/null; }
env_value() { sed -n "s/^$1=//p" "${STACK}/.env.dev.example" | head -1; }

# Starts a test case: what the receiver got before it isn't checked.
case_start() { printf '\n[%s] %s\n' "$1" "$2"; printf '%02d' "$1" > "${E2E}/case"; FROM="$(received)"; }

# Checks the requests received since case_start (tests/e2e/check.php).
check() {
    docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -v "${ROOT}:/app" -w /app "${TOOLS_IMAGE}" \
        php tests/e2e/check.php var/e2e/webhooks "${FROM}" "$1" || FAILED=1
}

# Expectations of a delivered invoice (check.php's JSON).
invoice_expectations() { # <invoice id> <fk_statut> <paye> [extra JSON members]
    printf '{"count": 1, "secret": "%s", "platform": "%s", "plugin_version": "%s", "source": "%s", "event": "%s", "schema": "tests/e2e/order-schema.json", "invoice_id": %s, "status": %s, "paye": %s%s}' \
        "${SECRET}" "${PLATFORM}" "${VERSION}" "${DOLI_URL}" "${EVENT:-invoice.validated}" "$1" "$2" "$3" "${4:+, $4}"
}

# Copies tests/e2e/fixtures.php into the "dolibarr" container.
copy_fixtures() { compose cp "${ROOT}/tests/e2e/fixtures.php" dolibarr:/var/www/dolibarr/htdocs/e2e-fixtures.php; }

# Sets a Configuration value through fixtures.php (fx set-config), so the
# module's own Settings shape stays the single source of truth for what
# gets stored.
set_config() { fx set-config "$1" "$2" > /dev/null; }

# Creates a thirdparty and a draft invoice (fx create-invoice). Prints the
# invoice id.
create_invoice() { fx create-invoice "$1" "${2:-9990}" | tr -d '\r\n'; }

# Fetches a page as the admin and returns its CSRF token (stays the same
# for the whole session, but re-read before each action to be safe).
admin_token() { admin_get "$1" | grep -o 'token=[0-9a-f]\{10,\}' | head -1 | sed 's/token=//'; }

# GET/POST as the admin (admin_login's cookies).
admin_get() { curl -fsS -c "${E2E}/admin-cookies" -b "${E2E}/admin-cookies" -L "$@"; }
admin_post() { curl -fsS -c "${E2E}/admin-cookies" -b "${E2E}/admin-cookies" -L -X POST "$@"; }

# Logs in to the back office as the development admin.
admin_login() {
    rm -f "${E2E}/admin-cookies"
    local login token
    login="$(admin_get "${DOLI_URL}/index.php")"
    token="$(printf '%s' "${login}" | grep -o 'name="token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')"
    admin_get "${DOLI_URL}/index.php" \
        --data-urlencode "username=$(env_value DOLI_ADMIN_LOGIN)" --data-urlencode "password=$(env_value DOLI_ADMIN_PASSWORD)" \
        --data-urlencode "token=${token}" --data-urlencode "actionlogin=login" > /dev/null
}

# Validates a draft invoice through the real invoice card ("Validar").
validate_invoice() { # <invoice id>
    local token
    token="$(admin_token "${DOLI_URL}/compta/facture/card.php?id=$1")"
    admin_get "${DOLI_URL}/compta/facture/card.php?facid=$1&action=confirm_valid&confirm=yes&token=${token}" > /dev/null
}

# Classifies a validated invoice as paid ("Clasificar 'Pagada'").
pay_invoice() { # <invoice id>
    local token
    token="$(admin_token "${DOLI_URL}/compta/facture/card.php?id=$1")"
    admin_get "${DOLI_URL}/compta/facture/card.php?facid=$1&action=confirm_paid&confirm=yes&token=${token}" > /dev/null
}

# Reopens a paid invoice ("Reabrir"): fires BILL_UNPAYED, an event the
# settings never offer, so it must never notify.
reopen_invoice() { # <invoice id>
    local token
    token="$(admin_token "${DOLI_URL}/compta/facture/card.php?id=$1")"
    admin_get "${DOLI_URL}/compta/facture/card.php?facid=$1&action=reopen&token=${token}" > /dev/null
}

# "Send to BillMySales" (the module's own button on the invoice card).
resend_invoice() { # <invoice id>
    local token
    token="$(admin_token "${DOLI_URL}/compta/facture/card.php?id=$1")"
    admin_get "${DOLI_URL}/compta/facture/card.php?id=$1&action=billmysales_resend&token=${token}" > /dev/null
}

# Enables ("set") or disables ("reset") the module through the real admin
# UI (Home > Setup > Modules).
module_via_web() { # set|reset
    local list url
    list="$(admin_get "${DOLI_URL}/admin/modules.php?search_keyword=billmysales")"
    url="$(printf '%s' "${list}" | grep -o "href=\"[^\"]*action=$1[^\"]*billmysales[^\"]*\"" | head -1 | sed 's/^href="//;s/"$//;s/&amp;/\&/g')"
    [ -n "${url}" ] || { fail "no link to $1 the module"; return; }
    admin_get "${DOLI_URL}${url}" > /dev/null
}

# "compose up -d --wait" can report failure although every long-running
# service settles healthy moments later (a one-shot "setup" container
# exiting, even successfully): start it, then poll the services that
# matter ourselves instead of trusting its exit code.
up_and_wait() {
    compose up -d >> "${E2E}/stack.log" 2>&1
    local i=0
    for service in db dolibarr caddy; do
        i=0
        while [ "$(compose ps "${service}" --format '{{.Health}}')" != healthy ]; do
            i=$((i + 1))
            [ "${i}" -ge 30 ] && die "${service} never became healthy (var/e2e/stack.log)"
            sleep 2
        done
    done
    i=0
    while [ "$(compose ps -a setup --format '{{.State}}')" != exited ]; do
        i=$((i + 1))
        [ "${i}" -ge 30 ] && die "setup never finished (var/e2e/stack.log)"
        sleep 2
    done
}

# Writes the stack's .env: the development template, this project name and,
# with "mount", the module override pointing to plugin/.
stack_env() { # mount|zip
    {
        cat "${STACK}/.env.dev.example"
        echo "COMPOSE_PROJECT_NAME=${PROJECT}"
        if [ "$1" = mount ]; then
            echo "COMPOSE_FILE=compose.yaml:overrides/module.yaml"
            echo "MODULE_PATH=${ROOT}/plugin"
            echo "MODULE_NAME=billmysales"
        fi
    } > "${STACK}/.env"
}

# shellcheck disable=SC2329 # called by the EXIT trap
cleanup() {
    local status=$?
    if [ "${E2E_KEEP:-0}" = 1 ]; then
        say "Stack kept running (E2E_KEEP=1), results in var/e2e; remove with: make e2e-clean"
        return
    fi
    say "Removing the stack and the receiver; results kept in var/e2e (webhooks/, stack.log)"
    [ -f "${STACK}/compose.yaml" ] && compose down -v --remove-orphans > /dev/null 2>&1 || true
    docker rm -f "${RECEIVER}" > /dev/null 2>&1 || true
    rm -rf "${STACK}" "${E2E}/case" "${E2E}/respond" "${E2E}/admin-cookies"
    exit "${status}"
}

# --- Preparation ---------------------------------------------------------------

[ -f "${ZIP}" ] || die "${ZIP} not found (make e2e builds it)"
if docker ps -aq --filter "name=^${RECEIVER}$" | grep . > /dev/null \
    || docker ps -aq --filter "label=com.docker.compose.project=${PROJECT}" | grep . > /dev/null; then
    die "the stack of a previous run is still there: make e2e-clean"
fi
port_in_use "${RECEIVER_PORT}" && die "port ${RECEIVER_PORT} (webhook receiver) is in use"

rm -rf "${E2E}"
mkdir -p "${E2E}/webhooks"
trap cleanup EXIT

say "Cloning ${STACK_REPO} (${STACK_REF})"
git clone -q --depth 1 --branch "${STACK_REF}" "${STACK_REPO}" "${STACK}"
DOLI_URL="$(env_value DOLI_URL)"
for port in "$(env_value HTTP_PORT)" "$(env_value HTTPS_PORT)" "$(env_value MAILPIT_PORT)"; do
    port_in_use "${port}" && die "port ${port} is in use: stop the ${PLATFORM} development stack (docker compose down)"
done

say "Starting the webhook receiver (port ${RECEIVER_PORT})"
docker run -d --name "${RECEIVER}" -u "$(id -u):$(id -g)" -p "${RECEIVER_PORT}:${RECEIVER_PORT}" \
    -v "${ROOT}/tests/e2e/receiver.php:/receiver/index.php:ro" -v "${E2E}:/e2e" \
    "${TOOLS_IMAGE}" php -S "0.0.0.0:${RECEIVER_PORT}" -t /receiver > /dev/null

say "Starting the stack, module mounted from plugin/ (log: var/e2e/stack.log)"
stack_env mount
up_and_wait
copy_fixtures
DOLI_ADMIN_LOGIN="$(env_value DOLI_ADMIN_LOGIN)"

fx enable-module modFacture > /dev/null
fx grant-all-rights "${DOLI_ADMIN_LOGIN}" > /dev/null
fx enable-module modBillmysales > /dev/null
CRON_JOB_ID="$(fx cron-job-id | tr -d '\r\n')"

admin_login
set_config BILLMYSALES_WEBHOOK "${RECEIVER_URL}"
set_config BILLMYSALES_TOKEN "${SECRET}"
set_config BILLMYSALES_ACTIVE 1
set_config BILLMYSALES_NOTIFY_EVENTS '["BILL_VALIDATE","BILL_PAYED"]'

# --- Cases: module mounted -------------------------------------------------------

case_start 1 "Invoice validated (BILL_VALIDATE selected)"
INV="$(create_invoice 'Ana Perez SA')"
validate_invoice "${INV}"
cron
check "$(invoice_expectations "${INV}" 1 0)"

case_start 2 "Invoice classified as paid (BILL_PAYED selected)"
pay_invoice "${INV}"
cron
EVENT=invoice.paid check "$(EVENT=invoice.paid invoice_expectations "${INV}" 2 1)"

case_start 3 "An event not selected (invoice reopened) doesn't notify"
reopen_invoice "${INV}"
expect "$(fx get-invoice-field "${INV}" paye | tr -d '\r\n')" "0" "the invoice was actually reopened (paye back to 0)"
cron
check '{"count": 0}'

case_start 4 "Deliveries deactivated in the settings"
set_config BILLMYSALES_ACTIVE 0
INV4="$(create_invoice 'Marco Diaz SA')"
validate_invoice "${INV4}"
cron
check '{"count": 0}'
set_config BILLMYSALES_ACTIVE 1

case_start 5 "BillMySales answers 503: retried with the same delivery"
respond 503
INV5="$(create_invoice 'Elena Rios SA')"
validate_invoice "${INV5}" # first attempt: 503, retry scheduled a minute out
cron
fx force-retry-now "${INV5}" > /dev/null
respond 200
cron # the retry is due now: delivered
check "$(invoice_expectations "${INV5}" 1 0 '"same_delivery": true' | sed 's/"count": 1/"count": 2/')"

case_start 6 "BillMySales answers 401: not retried"
respond 401
INV6="$(create_invoice 'Pedro Vera SA')"
validate_invoice "${INV6}"
cron
respond 200
cron
check "$(invoice_expectations "${INV6}" 1 0)"

case_start 7 "Sent again from the invoice page (\"Enviar a BillMySales\")"
resend_invoice "${INV6}"
cron
EVENT=invoice.resent check "$(EVENT=invoice.resent invoice_expectations "${INV6}" 1 0)"

case_start 8 "Settings saved through the admin form"
FORM="$(admin_get "${DOLI_URL}/custom/billmysales/admin/setup.php?action=edit")"
TOKEN="$(printf '%s' "${FORM}" | grep -o 'name="token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')"
admin_post "${DOLI_URL}/custom/billmysales/admin/setup.php" \
    --data-urlencode "token=${TOKEN}" --data-urlencode "action=update" \
    --data-urlencode "BILLMYSALES_ACTIVE=1" --data-urlencode "BILLMYSALES_WEBHOOK=${RECEIVER_URL}" \
    --data-urlencode "BILLMYSALES_TOKEN=${ADMIN_SECRET}" \
    --data-urlencode "BILLMYSALES_NOTIFY_EVENTS_BILL_VALIDATE=1" --data-urlencode "BILLMYSALES_NOTIFY_EVENTS_BILL_PAYED=1" \
    > /dev/null
SAVED="$(fx get-config BILLMYSALES_TOKEN | tr -d '\r\n')"
expect "${SAVED}" "${ADMIN_SECRET}" "secret kept as typed"
set_config BILLMYSALES_TOKEN "${SECRET}" # restored for the remaining cases

# Disables the mounted-phase module cleanly (config, tables, the
# MAIN_MODULE_BILLMYSALES const): otherwise, once the mount is removed
# below, Dolibarr would still think the module is enabled but find no
# class file for it, breaking every page.
module_via_web reset

# --- Cases: the built zip ----------------------------------------------------------

say "Installing $(basename "${ZIP}") through the admin's own module installer (no mount)"
stack_env zip
up_and_wait
admin_login
admin_post "${DOLI_URL}/admin/modules.php" \
    -F "token=$(admin_token "${DOLI_URL}/admin/modules.php?mode=deploy")" -F "action=install" -F "mode=deploy" \
    -F "fileinstall=@${ZIP};type=application/zip" > /dev/null
copy_fixtures
fx enable-module modFacture > /dev/null
fx grant-all-rights "${DOLI_ADMIN_LOGIN}" > /dev/null
module_via_web set
CRON_JOB_ID="$(fx cron-job-id | tr -d '\r\n')"
set_config BILLMYSALES_WEBHOOK "${RECEIVER_URL}"
set_config BILLMYSALES_TOKEN "${SECRET}"
set_config BILLMYSALES_ACTIVE 1
set_config BILLMYSALES_NOTIFY_EVENTS '["BILL_VALIDATE","BILL_PAYED"]'

case_start 9 "Invoice validated, module installed from the zip"
INV9="$(create_invoice 'Diego Rojas SA')"
validate_invoice "${INV9}"
cron
check "$(invoice_expectations "${INV9}" 1 0)"

case_start 10 "Uninstall"
module_via_web reset
TABLES="$(fx count-tables | tr -d '\r\n')"
expect "${TABLES}" "0" "the module's tables are gone"
CONFIG_LEFT="$(fx count-config | tr -d '\r\n')"
expect "${CONFIG_LEFT}" "0" "settings removed"

# --- Result ------------------------------------------------------------------------

if [ "${FAILED}" = 0 ]; then
    say "All end-to-end cases passed"
else
    say "Some end-to-end cases FAILED"
fi
exit "${FAILED}"
