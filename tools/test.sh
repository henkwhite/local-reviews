#!/usr/bin/env bash
# Integrationstests gegen die Studio-Wegwerfseite local-reviews-ci.
# Kopiert Plugin und Testskript in die Seite und führt die Tests per WP-CLI aus.
# Google wird nie kontaktiert: alle HTTP-Aufrufe werden im Test über pre_http_request simuliert.
# Exit-Code 1, wenn mindestens ein Test fehlschlägt.
set -euo pipefail
cd "$(dirname "$0")/.."

SITE="$HOME/Studio/local-reviews-ci"
if [ ! -d "$SITE/wp-content" ]; then
	echo "Studio-Seite fehlt. Anlegen mit:"
	echo "  studio site create --path ~/Studio/local-reviews-ci --name local-reviews-ci --php 8.2 --start"
	exit 1
fi

mkdir -p "$SITE/wp-content/plugins/local-reviews/" "$SITE/wp-content/lr-tests/"
rsync -a --delete --exclude composer.json --exclude composer.lock --exclude .DS_Store local-reviews/ "$SITE/wp-content/plugins/local-reviews/"
rsync -a --delete --exclude .DS_Store tools/tests/ "$SITE/wp-content/lr-tests/"

cd "$SITE"
studio wp plugin is-active local-reviews >/dev/null 2>&1 || studio wp plugin activate local-reviews
exec studio wp eval-file wp-content/lr-tests/run-tests.php
