#!/usr/bin/env bash
# Kopiert das Plugin in die Studio-Testseiten (ohne Entwicklungsdateien).
# Aufruf: tools/sync-studio.sh [test|clean|all]
set -euo pipefail
cd "$(dirname "$0")/.."
target="${1:-all}"
for site in local-reviews-test local-reviews-clean; do
	case "$target" in
		test) [ "$site" = local-reviews-test ] || continue ;;
		clean) [ "$site" = local-reviews-clean ] || continue ;;
	esac
	dest="$HOME/Studio/$site/wp-content/plugins/local-reviews/"
	mkdir -p "$dest"
	rsync -a --delete --exclude .DS_Store local-reviews/ "$dest"
	echo "→ $site"
done
