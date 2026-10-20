# Local Reviews

WordPress-Plugin: Google-Bewertungen über die Places API (New) lokal speichern und anzeigen, ergänzt um Bewertungen anderer Plattformen (11880 u. a.). Besucher bauen keine Verbindung zu Google auf.

- Plugin-Code: [`local-reviews/`](local-reviews/) – Beschreibung, FAQ und Changelog in [`local-reviews/readme.txt`](local-reviews/readme.txt)
- Ausführliche Anleitung: im Backend unter Einstellungen › Local Reviews › Anleitung

## Installation

Die ZIP aus dem [neuesten Release](https://github.com/henkwhite/local-reviews/releases/latest) unter Plugins › Installieren › Plugin hochladen installieren. Updates kommen danach automatisch aus den GitHub-Releases (plugin-update-checker).

Nicht den automatischen Quellcode-Download von GitHub verwenden: Ihm fehlt `vendor/`.

## Entwicklung

```bash
# Prüfwerkzeuge installieren (einmalig)
(cd tools/qa && composer install)
(cd local-reviews && composer install --no-dev)

# Plugin in die Studio-Testseiten kopieren
tools/sync-studio.sh

# PHPCS, PHPStan, Plugin Check, Integrationstests
tools/qa.sh

# Release-ZIP bauen (dist/) und nach Rückfrage veröffentlichen
tools/release.sh
tools/release.sh --zip-only
```

Testumgebung: WordPress Studio mit den Seiten `local-reviews-test` (GeneratePress + GenerateBlocks Pro, Daten einer Live-Seite), `local-reviews-clean` (Twenty Twenty-Five, ohne Daten, Plugin Check) und `local-reviews-ci` (Integrationstests mit simulierten API-Antworten).

## Lizenz

GPL-2.0-or-later. Enthält [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) (MIT).
