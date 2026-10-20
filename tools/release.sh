#!/usr/bin/env bash
# Baut die Release-ZIP und legt nach Rückfrage ein GitHub-Release an.
#
# Ablauf: Versionen prüfen → QA → schlanke ZIP (mit vendor/, ohne Entwicklungsdateien)
# → Inhalt anzeigen → Rückfrage → Tag pushen → gh release create.
#
# Aufruf: tools/release.sh            (alles)
#         tools/release.sh --zip-only (nur ZIP bauen, ohne QA und ohne Release)
#
# Die ZIP landet in dist/. Gebaut wird in einem frischen temporären Ordner.
set -euo pipefail
cd "$(dirname "$0")/.."

REPO="henkwhite/local-reviews"
version=$(grep -E "^ \* Version:" local-reviews/local-reviews.php | awk '{print $3}')
const_version=$(grep -E "^const VERSION" local-reviews/local-reviews.php | sed -E "s/.*'(.*)'.*/\1/")
stable=$(grep -E "^Stable tag:" local-reviews/readme.txt | awk '{print $3}')

if [ "$version" != "$const_version" ] || [ "$version" != "$stable" ]; then
	echo "✗ Versionen passen nicht: Header $version, VERSION $const_version, Stable tag $stable"
	exit 1
fi
for f in local-reviews/block/*/block.json local-reviews/block/*/index.asset.php; do
	grep -q "$version" "$f" || { echo "✗ $f hat nicht Version $version"; exit 1; }
done
echo "Version $version"

if [ "${1:-}" != "--zip-only" ]; then
	tools/qa.sh || { echo "✗ QA fehlgeschlagen, kein Release."; exit 1; }
fi

# Saubere Kopie in einem frischen temporären Ordner, vendor/ ohne Dev-Abhängigkeiten.
work=$(mktemp -d)
rsync -a --exclude .DS_Store --exclude vendor local-reviews/ "$work/local-reviews/"
(cd "$work/local-reviews" && composer install --no-dev --optimize-autoloader --no-interaction -q)
zip_name="local-reviews-$version.zip"
mkdir -p dist
(cd "$work" && zip -rqX "$zip_name" local-reviews)
mv -f "$work/$zip_name" "dist/$zip_name"

echo; echo "Inhalt von dist/$zip_name (ohne Bibliothek):"
unzip -l "dist/$zip_name" | awk 'NR>3 {print $4}' | grep -v "^$" | grep -v "/vendor/yahnis-elsts/plugin-update-checker/." | head -60
echo "(plus vendor/yahnis-elsts/plugin-update-checker)"
du -h "dist/$zip_name"

if [ "${1:-}" = "--zip-only" ]; then
	exit 0
fi

echo
read -r -p "Release v$version auf github.com/$REPO veröffentlichen? Das ist öffentlich. [j/N] " answer
if [ "$answer" != "j" ]; then
	echo "Abgebrochen, nichts veröffentlicht."
	exit 0
fi
git tag -a "v$version" -m "Local Reviews $version"
git push origin "v$version"
notes=$(awk "/^= $version =/{f=1;next}/^= /{f=0}f" local-reviews/readme.txt)
gh release create "v$version" "dist/$zip_name" --repo "$REPO" --title "Local Reviews $version" --notes "$notes"
echo "✓ Release v$version veröffentlicht."
