=== Local Reviews ===
Contributors: kronfink
Tags: google reviews, bewertungen, rezensionen, places api, testimonials
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Google-Bewertungen lokal speichern und anzeigen, ergänzt um Bewertungen anderer Plattformen. Kein Nachladen von Google im Frontend.

== Description ==

Local Reviews holt täglich über die Places API (New) Note, Anzahl, Profilfoto und die von Google gelieferten Bewertungen eines Unternehmens und speichert alles in WordPress. Besucher bauen keine Verbindung zu Google auf; auch das Foto liegt lokal.

* Archiv aller Bewertungen, das über die 5 Bewertungen der API hinaus wächst
* Import des vollständigen Bestands aus Outscraper (JSON), Google Takeout (reviews.json) oder CSV, mit Vorschau und Dublettenerkennung
* Bewertungen anderer Plattformen (11880, GoLocal, Facebook, ProvenExpert, MyHammer, Houzz, Trustpilot, Sonstige) per Formular oder CSV
* Einträge ausblenden, manuelle und importierte bearbeiten oder löschen
* Drei Blöcke und drei Shortcodes: Bewertungen mit Kopfbereich, Badge, Button zu Google
* Darstellung global einstellbar und pro Block/Shortcode überschreibbar
* Seiten-Cache (WP Rocket, AccelerateWP, LiteSpeed, W3 Total Cache, WP Super Cache, WP Fastest Cache, Cache Enabler, SiteGround Optimizer, Breeze) wird nur bei echten Änderungen geleert
* Updates direkt aus GitHub-Releases

Ausführliche Anleitung im Backend unter Einstellungen › Local Reviews › Anleitung.

== Installation ==

1. ZIP unter Plugins › Installieren › Plugin hochladen installieren und aktivieren.
2. In Google Cloud die „Places API (New)“ aktivieren und einen API-Key anlegen (IP-beschränkt, nicht auf HTTP-Referrer).
3. Unter Einstellungen › Local Reviews API-Key und Place ID eintragen.
4. Block „Bewertungen“ einfügen oder Shortcode `[google_reviews_lokal]` verwenden.

== Frequently Asked Questions ==

= Warum zeigt das Plugin nicht alle Bewertungen von Google? =

Die Places API liefert pro Abruf höchstens 5 Bewertungen. Das Plugin sammelt sie über die Zeit im Archiv. Den vollständigen Bestand einmalig per Import (Outscraper, Google Takeout oder CSV) übernehmen. Die Statusseite warnt, wenn Google mehr Bewertungen zählt als im Archiv liegen.

= Wie zeige ich die Bewertungen in einem Modal? =

Mit GenerateBlocks Pro: GenerateBlocks › Overlay Panels › Add New, Typ „Standard“, Trigger „Click“, darin den Block „Bewertungen“. Den Badge-Block (Link „Kein Link“) in einen GenerateBlocks-Container legen und dort unter Advanced › Overlay Panel Trigger das Panel wählen.
Ohne GenerateBlocks Pro: dem Bewertungen-Block den HTML-Anker `bewertungen` geben und den Badge auf `#bewertungen` verlinken.

= Gibt das Plugin Schema-Markup (Sterne in Google) aus? =

Nein. Die Funktion `local_reviews_get_summary()` liefert Note und Anzahl für ein SEO-Plugin wie SEOPress oder Rank Math.

= Wo liegt der API-Key? =

In einer eigenen Option ohne Autoload. Er erscheint nie im HTML, auch nicht auf der Einstellungsseite (nur maskiert), und wird im Header statt in der URL an Google übertragen.

= Ich komme von den alten Fluent-Snippets. Was muss ich tun? =

Snippets deaktivieren, Plugin aktivieren – Archiv, Note, Anzahl und Direktlink werden einmalig kopiert, die Shortcodes heißen gleich. Danach API-Key und Place ID eintragen und den alten Outscraper-Export im Modus „Nur zuordnen“ hochladen. Schritt für Schritt in der Anleitung im Backend.

= Was passiert beim Deinstallieren? =

Alle Daten des Plugins (Einstellungen, Key, Archiv, Foto in uploads/local-reviews) werden gelöscht. Daten der alten Snippets bleiben unberührt.

== Changelog ==

= 1.3.0 =
* Neue Einstellung „Logo im Badge“: Google-Schriftzug, kein Logo oder eigenes Bild aus der Mediathek. Pro Badge überschreibbar (Block „Logo“, Shortcode brand="google|none|image").
* Neue Einstellung „Plattform-Hinweis“ (aus / nur andere Plattformen / alle). Blöcke und Shortcodes ohne eigene Angabe folgen ihr.
* „via Google“ ohne Direktlink zur Bewertung verlinkt auf die Bewertungsliste bei Google.

= 1.2.0 =
* Neue Einstellung „Anzahl im Badge“: Anzahl laut Google oder die sichtbaren Bewertungen im Plugin (alle Plattformen, ohne ausgeblendete). Pro Badge überschreibbar (Block bzw. Shortcode-Attribut count).
* Anleitung: show_source="all" zeigt „via Google“ mit Link zur Original-Bewertung.

= 1.1.0 =
* Seiten-Cache wird zusätzlich für W3 Total Cache, WP Super Cache, WP Fastest Cache, Cache Enabler, SiteGround Optimizer und Breeze geleert. Ein Fehler bei einem Cache-Plugin hält die anderen nicht mehr auf.
* Statusseite nennt alle erkannten Cache-Plugins.
* Einstellungen: Feld für das Datumsformat ist breiter.

= 1.0.0 =
* Erste Version: Ablösung der Fluent-Snippets „Google Rezensionen“.
