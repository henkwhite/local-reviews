#!/usr/bin/env bash
# Alle Prüfungen: Coding Standards, statische Analyse, Plugin Check, Integrationstests.
# Plugin Check läuft in der Studio-Seite local-reviews-clean, die Tests in local-reviews-ci.
set -uo pipefail
cd "$(dirname "$0")/.."
status=0

echo "▶ PHPCS (WordPress Coding Standards)"
tools/qa/vendor/bin/phpcs --report=summary || status=1

echo; echo "▶ PHPStan"
tools/qa/vendor/bin/phpstan analyse --no-progress --memory-limit=1G || status=1

echo; echo "▶ Plugin Check (Studio: local-reviews-clean)"
# Bewusst ignoriert, weil das Plugin nicht auf wordpress.org erscheint:
# eigener Updater (Entscheidung: Updates aus GitHub-Releases) und deutsche readme.txt.
tools/sync-studio.sh clean >/dev/null
out=$(cd "$HOME/Studio/local-reviews-clean" && studio wp plugin check local-reviews --format=table \
	--ignore-codes=plugin_updater_detected,readme_short_description_non_official_language,readme_description_non_official_language 2>&1 | grep -v "^Deprecated")
if [ -n "$out" ]; then echo "$out"; status=1; else echo "Keine Befunde."; fi

echo; echo "▶ Integrationstests (Studio: local-reviews-ci)"
if [ -x tools/test.sh ]; then
	tools/test.sh || status=1
else
	echo "tools/test.sh fehlt"; status=1
fi

echo
if [ $status -eq 0 ]; then echo "✓ Alle Prüfungen bestanden"; else echo "✗ Prüfungen fehlgeschlagen"; fi
exit $status
