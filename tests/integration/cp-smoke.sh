#!/usr/bin/env bash
#
# Publishr control-panel smoke test.
#
# Every screen in the plugin, fetched as a logged-in administrator, checked for a 200 and for the
# absence of a Twig or PHP error in the body. The check suite exercises the services; this is the
# other half — a template that references a variable the controller does not pass fails at *render*
# time and no amount of unit testing finds it.
#
# Run from the site root inside the container:
#
#     ddev exec bash /var/www/craft-publishr/tests/integration/cp-smoke.sh
#
set -uo pipefail

BASE="${BASE:-http://localhost}"
JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT
USER="${CP_USER:-admin}"

pass=0
fail=0

# Getting in without a password.
#
# `users/impersonate` prints a one-hour login URL that Craft honours as a full control-panel
# session. That is deliberate here rather than a convenience: posting real credentials to
# `users/login` two dozen times an hour trips the brute-force protection on any hardened site — on
# the shared test harness that is Garrison — and the refusal comes back as a generic "invalid
# username or password", which sends you hunting a password problem that does not exist. An
# impersonation URL asks the site who it is willing to log in as instead of guessing.
# The command prints a sentence, not a bare URL, so pull the URL out of it.
url=$(cd /var/www/html && php craft users/impersonate "$USER" 2>/dev/null \
    | grep -oE 'https?://[^[:space:]]+token=[A-Za-z0-9_-]+' | head -1)

case "$url" in
    http*) ;;
    *) echo "  ✗ could not get an impersonation URL for $USER"; exit 1 ;;
esac

# The URL Craft prints is the site's primary URL; rewrite the host so the request stays inside the
# container and never touches the ddev router.
path="/${url#*://*/}"

curl -s -c "$JAR" -b "$JAR" -o /dev/null -L "$BASE$path"

# Proved by fetching a control-panel screen that only a signed-in user can see, rather than by
# `users/session-info` — that endpoint answers differently depending on how the request is framed,
# and a login check that is itself fiddly is a login check that lies.
dashboard=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' "$BASE/admin/dashboard")

if [ "$dashboard" != "200" ]; then
    echo "  ✗ the impersonation URL did not produce a session (dashboard answered $dashboard)"
    exit 1
fi

# A breath between requests.
#
# A control-panel request on a site with a hundred plugins is not cheap, and firing two dozen of them
# back to back exhausts PHP-FPM's workers on a modest container — which shows up as a run of 500s in
# the middle of the list that clears on its own. That is the harness falling over, not the plugin,
# and a smoke test that cries wolf is a smoke test people stop reading.
PAUSE="${PAUSE:-1}"

screen() {
    local label="$1" path="$2"
    local body code

    sleep "$PAUSE"

    body=$(curl -s -b "$JAR" -c "$JAR" -w $'\n%{http_code}' "$BASE$path")
    code="${body##*$'\n'}"
    body="${body%$'\n'*}"

    if [ "$code" != "200" ]; then
        echo "  ✗ $label — HTTP $code"
        fail=$((fail + 1))
        return
    fi

    # Craft renders its exception page with a 200 in some configurations, so the status code alone
    # is not proof of anything.
    case "$body" in
        *"Twig\\Error"*|*"TwigError"*|*"Unknown Method"*|*"Variable \""*"does not exist"*|*"exception-nav"*|*"Fatal error"*)
            echo "  ✗ $label — the page rendered an exception"
            fail=$((fail + 1))
            return
            ;;
    esac

    echo "  ✓ $label"
    pass=$((pass + 1))
}

echo "Publishr control panel"

screen "calendar"                "/admin/publishr/calendar"
screen "calendar, a named month" "/admin/publishr/calendar/2026/5"
screen "calendar, every lane"    "/admin/publishr/calendar?lanes=due,publish,expire,review"
screen "calendar, no lanes"      "/admin/publishr/calendar?lanes="
screen "board"                   "/admin/publishr/board"
screen "board, filtered to me"   "/admin/publishr/board?assigneeId=me"
screen "overview"                "/admin/publishr/overview"
screen "overview, overdue only"  "/admin/publishr/overview?state=overdue"
screen "my desk"                 "/admin/publishr/mine"
screen "activity"                "/admin/publishr/activity"
screen "reviews"                 "/admin/publishr/reviews"
screen "report"                  "/admin/publishr/report"
screen "settings"                "/admin/publishr/settings/general"
screen "settings, notifications" "/admin/publishr/settings/notifications"
screen "settings, stages"        "/admin/publishr/settings/stages"
screen "settings, a new stage"   "/admin/publishr/settings/stages/new"
screen "settings, requirements"  "/admin/publishr/settings/gates"
screen "settings, pick a type"   "/admin/publishr/settings/gates/new"
screen "settings, a requirement" "/admin/publishr/settings/gates/new?type=requiredFields"
screen "settings, freshness"     "/admin/publishr/settings/policies"
screen "settings, a new policy"  "/admin/publishr/settings/policies/new"

# The entry editor, where the sidebar panel lives. The ID comes from Craft itself rather than being
# hard-coded, so the test survives the fixture site being rebuilt — and from the console rather than
# by scraping the element index, which is rendered by JavaScript and contains no such link.
entryId=$(cd /var/www/html && php craft publishr/track/first-entry 2>/dev/null | tr -d '[:space:]')

if [ -n "$entryId" ]; then
    screen "an entry, with the panel" "/admin/entries/news/$entryId"
else
    echo "  · no entry found to check the sidebar panel against"
fi

# ---------------------------------------------------------------- the licence boundary
#
# The Pro screens have to be *refused* under Lite, not merely empty. A settings screen that renders
# a governance report on a Lite licence is the one bug in an edition split that nobody notices
# until it is in the wild.

# Craft has no `plugin/switch-edition` console command — editions are switched through the plugins
# service. A script that assumed one would report the *Lite* screens as available under Lite, which
# reads as a licence-enforcement bug in the plugin and is really a typo in the test.
switch_edition() {
    php -r '
        require "/var/www/html/bootstrap.php";
        $app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
        Craft::$app->getPlugins()->switchEdition("publishr", $argv[1]);
    ' "$1" >/dev/null 2>&1
}

current_edition() {
    php -r '
        require "/var/www/html/bootstrap.php";
        $app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
        echo Craft::$app->getPlugins()->getPlugin("publishr")->edition;
    ' 2>/dev/null
}

edition=$(current_edition)

refused() {
    local label="$1" path="$2" code

    sleep "$PAUSE"

    code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' "$BASE$path")

    # 403 is the honest answer; a redirect away from the screen is an acceptable one. A 200 is not.
    if [ "$code" = "200" ]; then
        echo "  ✗ $label — Lite served it (HTTP 200)"
        fail=$((fail + 1))
    else
        echo "  ✓ $label — refused with $code"
        pass=$((pass + 1))
    fi
}

echo
echo "Under a Lite licence"

switch_edition lite

refused "the governance report" "/admin/publishr/report"
refused "freshness reviews"     "/admin/publishr/reviews"

# Lite keeps the calendar, and that is the whole point of the split.
screen "the calendar still works" "/admin/publishr/calendar"
screen "the board still works"    "/admin/publishr/board"

# Put the licence back, whatever it was, before anything else on this shared site trips over it.
switch_edition "${edition:-lite}"

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
