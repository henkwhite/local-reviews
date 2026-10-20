<?php
/**
 * Tab „Anleitung“ der Backend-Seite.
 *
 * Statischer Text. Angaben zu fremden Oberflächen (Google, Outscraper,
 * GenerateBlocks, Plesk) entsprechen dem Stand Oktober 2026 und können sich ändern.
 *
 * @package LocalReviews
 */

defined( 'ABSPATH' ) || exit;

$local_reviews_settings_url = LocalReviews\Admin_Page::url( [ 'tab' => 'settings' ] );
$local_reviews_import_url   = LocalReviews\Admin_Page::url( [ 'tab' => 'import' ] );
$local_reviews_reviews_url  = LocalReviews\Admin_Page::url( [ 'tab' => 'reviews' ] );
?>
<div class="local-reviews-guide">

	<p><strong>Inhalt:</strong>
		<a href="#einrichtung">Einrichtung</a> ·
		<a href="#anzeigen">Anzeigen</a> ·
		<a href="#modal">Als Modal</a> ·
		<a href="#plattformen">Andere Plattformen</a> ·
		<a href="#import">Import</a> ·
		<a href="#umstieg">Umstieg von den Snippets</a> ·
		<a href="#cron">Server-Cron</a> ·
		<a href="#entwickler">Für Entwickler</a> ·
		<a href="#grenzen">Grenzen und Kosten</a>
	</p>

	<h2 id="einrichtung">1. Einrichtung</h2>
	<ol>
		<li><strong>Google-Cloud-Projekt</strong> mit hinterlegtem Abrechnungskonto öffnen (console.cloud.google.com). Unter <em>Google Maps Platform</em> die API <strong>„Places API (New)“</strong> aktivieren.</li>
		<li><strong>API-Key anlegen:</strong> Google Maps Platform › Credentials (Anmeldedaten) › <em>Create credentials › API key</em>.</li>
		<li><strong>Key beschränken</strong> (empfohlen): Key bearbeiten ›
			<em>Application restrictions</em> › <strong>IP addresses</strong> mit der öffentlichen IP des Webservers (keine HTTP-Referrer, der Abruf läuft auf dem Server) ›
			<em>API restrictions</em> › <em>Restrict key</em> › nur „Places API (New)“.
			Hinweis: Mit IP-Beschränkung funktioniert der Abruf von einer lokalen Testumgebung aus nicht.</li>
		<li><strong>Place ID</strong> des Unternehmens suchen: <a href="https://developers.google.com/maps/documentation/javascript/examples/places-placeid-finder" target="_blank" rel="noopener noreferrer">Place ID Finder von Google</a>. Sie beginnt meist mit „ChIJ“.</li>
		<li>Unter <a href="<?php echo esc_url( $local_reviews_settings_url ); ?>">Einstellungen</a> API-Key und Place ID eintragen und speichern. Das Plugin ruft sofort einmal ab und zeigt das Ergebnis.</li>
	</ol>
	<p>Danach holt das Plugin einmal täglich Note, Anzahl, Profilfoto und die von Google gelieferten Bewertungen. Das Foto wird lokal gespeichert, Besucher bauen keine Verbindung zu Google auf.</p>

	<h2 id="anzeigen">2. Anzeigen: Blöcke und Shortcodes</h2>
	<p>Im Block-Editor gibt es drei Blöcke: <strong>Bewertungen</strong>, <strong>Bewertungs-Badge</strong> und <strong>Bewertungs-Button</strong> (Kategorie Widgets). Alle Einstellungen stehen in der Seitenleiste. Dieselben Ausgaben gibt es als Shortcodes, z. B. für Page-Builder oder Theme-Elemente.</p>

	<h3>[google_reviews_lokal] – Kopfbereich und Bewertungen</h3>
	<table class="widefat striped">
		<thead><tr><th>Attribut</th><th>Standard</th><th>Bedeutung</th></tr></thead>
		<tbody>
			<tr><td><code>header</code></td><td>1</td><td>Kopfbereich mit Firmenname, Sternen, Note und Anzahl (0 = aus)</td></tr>
			<tr><td><code>photo</code></td><td>1</td><td>Foto im Kopfbereich</td></tr>
			<tr><td><code>limit</code></td><td>0</td><td>Anzahl Bewertungen, 0 = alle</td></tr>
			<tr><td><code>columns</code> / <code>columns_tablet</code> / <code>columns_mobile</code></td><td>1 / 0 / 1</td><td>Spalten; Tablet 0 = wie Desktop. Tablet bis 1024 px, Smartphone bis 767 px Breite</td></tr>
			<tr><td><code>card_width</code></td><td>400</td><td>Maximale Kartenbreite in Pixeln, 0 = volle Spaltenbreite</td></tr>
			<tr><td><code>min_rating</code></td><td>0</td><td>Nur Bewertungen ab so vielen Sternen</td></tr>
			<tr><td><code>hide_empty</code></td><td>0</td><td>Bewertungen ohne Text ausblenden</td></tr>
			<tr><td><code>excerpt</code></td><td>0</td><td>Text auf so viele Zeichen kürzen, 0 = ungekürzt</td></tr>
			<tr><td><code>source</code></td><td>(leer)</td><td>Nur bestimmte Plattformen, z. B. <code>google,11880</code></td></tr>
			<tr><td><code>show_source</code></td><td>(Einstellung)</td><td>Plattform-Hinweis „via …“: <code>off</code>, <code>foreign</code> (nur andere als Google), <code>all</code> (auch „via Google“). Standard aus Einstellungen › Plattform-Hinweis. Ist ein Link zur Original-Bewertung hinterlegt, ist der Hinweis anklickbar; Google-Bewertungen ohne Direktlink verlinken auf die Bewertungsliste</td></tr>
			<tr><td><code>wrapper_class</code></td><td>(leer)</td><td>Eigene CSS-Klassen am äußeren Element</td></tr>
		</tbody>
	</table>
	<p>Beispiel: <code>[google_reviews_lokal columns="3" columns_tablet="2" card_width="0" limit="6"]</code></p>

	<h3>[google_badge_einzeln] – Badge</h3>
	<table class="widefat striped">
		<thead><tr><th>Attribut</th><th>Standard</th><th>Bedeutung</th></tr></thead>
		<tbody>
			<tr><td><code>brand</code></td><td>(Einstellung)</td><td>Logo: <code>google</code> (Schriftzug), <code>none</code> bzw. <code>0</code> (kein Logo), <code>image</code> (Bild aus Einstellungen › Logo im Badge). Standard aus den Einstellungen</td></tr>
			<tr><td><code>rating</code></td><td>0</td><td>Note als Zahl vor den Sternen</td></tr>
			<tr><td><code>label</code></td><td>Bewertungen</td><td>Text hinter der Anzahl</td></tr>
			<tr><td><code>arrow</code></td><td>1</td><td>Pfeil „›“ am Ende</td></tr>
			<tr><td><code>link</code></td><td>none</td><td><code>none</code>, <code>google</code> (Bewertungsliste) oder <code>custom</code> mit <code>url</code></td></tr>
			<tr><td><code>url</code></td><td>(leer)</td><td>Ziel bei <code>link="custom"</code>, z. B. <code>#bewertungen</code></td></tr>
			<tr><td><code>count</code></td><td>(Einstellung)</td><td><code>google</code> (Anzahl laut Google) oder <code>local</code> (sichtbare Bewertungen im Plugin, alle Plattformen). Standard aus Einstellungen › Anzahl im Badge</td></tr>
			<tr><td><code>wrapper_class</code></td><td>(leer)</td><td>Eigene CSS-Klassen</td></tr>
		</tbody>
	</table>

	<h3>[google_reviews_button] – Button zu Google</h3>
	<table class="widefat striped">
		<thead><tr><th>Attribut</th><th>Standard</th><th>Bedeutung</th></tr></thead>
		<tbody>
			<tr><td><code>text</code></td><td>Alle Bewertungen auf Google lesen</td><td>Beschriftung</td></tr>
			<tr><td><code>target</code></td><td>reviews</td><td><code>reviews</code> (Bewertungen lesen) oder <code>write</code> (Bewertung schreiben)</td></tr>
			<tr><td><code>icon</code></td><td>1</td><td>Google-„G“ vor dem Text</td></tr>
			<tr><td><code>align</code></td><td>center</td><td><code>left</code>, <code>center</code>, <code>right</code></td></tr>
			<tr><td><code>style</code></td><td>google</td><td><code>google</code> (weiß mit Schatten), <code>theme</code> (Button-Stil des Themes, Klasse <code>wp-element-button</code>), <code>none</code> (nur eigene Klassen)</td></tr>
			<tr><td><code>class</code></td><td>(leer)</td><td>Eigene Klassen am Link, z. B. Global Styles von GenerateBlocks. Im Block: Feld „Button-Klassen“</td></tr>
			<tr><td><code>wrapper_class</code></td><td>(leer)</td><td>Eigene Klassen am äußeren Element</td></tr>
		</tbody>
	</table>

	<h2 id="modal">3. Bewertungen als Modal (Popup)</h2>
	<p>Das Plugin bringt kein eigenes Modal mit. So geht es mit Bordmitteln:</p>
	<h3>Mit GenerateBlocks Pro (Overlay Panels, ab Version 2.3)</h3>
	<ol>
		<li><strong>GenerateBlocks › Overlay Panels › Add New</strong>.</li>
		<li>Rechts im Tab „Overlay Panel“: <strong>Overlay Panel Type</strong> = <em>Standard</em> (zentriertes Popup), <strong>Trigger Type</strong> = <em>Click</em>.</li>
		<li>In das Panel den Block <strong>Bewertungen</strong> einfügen (oder den Shortcode <code>[google_reviews_lokal]</code>) und darunter den <strong>Bewertungs-Button</strong>. Veröffentlichen.</li>
		<li>Auf der Seite den <strong>Bewertungs-Badge</strong> mit Link „Kein Link“ in einen GenerateBlocks-Container oder -Button legen. Diesen Block auswählen › Seitenleiste <strong>Advanced</strong> › <strong>Overlay Panel Trigger</strong> › das neue Panel wählen. Eine eventuelle URL entfernen bzw. auf <code>#</code> setzen.</li>
	</ol>
	<p class="description">Klickpfade laut GeneratePress-Blog und Support-Forum, Stand Oktober 2026, nicht selbst durchgeklickt. Ein Trigger lässt sich nur an einen Block hängen, nicht an ein einzelnes Wort.</p>
	<h3>Ohne GenerateBlocks Pro: Sprungmarke</h3>
	<ol>
		<li>Den Block <strong>Bewertungen</strong> auf der Seite (oder einer eigenen Seite) einfügen und in der Seitenleiste unter <em>Erweitert › HTML-Anker</em> <code>bewertungen</code> eintragen.</li>
		<li>Beim Badge Link = „Eigene URL oder Anker“ und <code>#bewertungen</code> (gleiche Seite) bzw. <code>/kundenstimmen/#bewertungen</code> eintragen.</li>
	</ol>

	<h2 id="plattformen">4. Bewertungen anderer Plattformen</h2>
	<p>Unter <a href="<?php echo esc_url( $local_reviews_reviews_url ); ?>">Bewertungen › Bewertung hinzufügen</a> lassen sich Bewertungen von 11880, GoLocal, Facebook, ProvenExpert, MyHammer, Houzz, Trustpilot oder einer beliebigen anderen Plattform („Sonstige“ mit eigenem Namen) eintragen. Mit Link zur Original-Bewertung wird der Hinweis „via 11880“ im Grid zum Link, damit Besucher die Bewertung prüfen können.</p>
	<ul class="ul-disc">
		<li><strong>Ausblenden</strong> geht bei allen Einträgen und bleibt auch bei späteren Abrufen bestehen.</li>
		<li><strong>Bearbeiten und Löschen</strong> geht nur bei manuellen und importierten Einträgen. Einträge aus dem Google-Abruf würde der nächste Abruf wiederherstellen, deshalb nur ausblenden.</li>
		<li>Der Kopfbereich zeigt immer Note und Anzahl laut Google, unabhängig von manuellen Einträgen.</li>
		<li>Mögliche Dubletten (gleicher Tag ±1, gleicher Textanfang) markiert die Tabelle. Gelöscht wird nie automatisch.</li>
	</ul>

	<h2 id="import">5. Alle Google-Bewertungen importieren</h2>
	<p>Google liefert über die Places API nur <strong>5 Bewertungen</strong> (nach Relevanz, nicht nach Datum). Für den vollständigen Bestand gibt es drei Wege. Jeder Import zeigt vor dem Speichern eine Vorschau; schon vorhandene Bewertungen werden erkannt und nicht doppelt angelegt.</p>

	<p class="description">Achtung: Im Modus „Importieren“ überschreibt eine erneut hochgeladene Datei Text, Sterne und Link bereits importierter oder manueller Einträge mit den Werten aus der Datei. Eigene Korrekturen an solchen Einträgen also nicht erneut importieren.</p>

	<h3>a) Scraper, z. B. Outscraper (getestet)</h3>
	<ol>
		<li>Auf outscraper.com ein Konto anlegen und anmelden. Laut Preisseite sind die ersten 500 Bewertungen pro Monat kostenlos.</li>
		<li>Im Menü <em>Google Services</em> den Dienst <strong>Google Maps Reviews Scraper</strong> öffnen.</li>
		<li>Als Suche den Google-Maps-Link des Unternehmens oder die Place ID eintragen. <em>Reviews limit per place</em> hoch genug setzen (mehr als die Anzahl bei Google), <em>Sorting</em> = Newest.</li>
		<li>Als Dateiformat <strong>JSON</strong> wählen, falls angeboten, und die Datei herunterladen.</li>
		<li>Unter <a href="<?php echo esc_url( $local_reviews_import_url ); ?>">Import</a> hochladen, Vorschau prüfen, speichern.</li>
	</ol>
	<p class="description">Getestet mit einer Outscraper-JSON-Datei vom September 2026. Ob die Web-App heute JSON direkt anbietet, ist nicht geprüft; die Quellen widersprechen sich. Bietet sie nur CSV/Excel an, die Datei in die CSV-Spalten unten umbauen.</p>

	<h3>b) Google Takeout (Inhaber oder Verwalter des Unternehmensprofils)</h3>
	<ol>
		<li>takeout.google.com mit dem Konto öffnen, das das Unternehmensprofil verwaltet.</li>
		<li>„Alle abwählen“, dann nur <strong>Google Business Profile / Unternehmensprofil</strong> ankreuzen › Nächster Schritt › Einmal exportieren › Export erstellen.</li>
		<li>Auf die E-Mail warten (Minuten bis Tage), ZIP herunterladen und entpacken.</li>
		<li>Im Ordner <code>Google Business Profile/account-…/location-…/</code> die Datei <strong>reviews.json</strong> hochladen (nicht die ZIP). Bei mehreren Standorten die Datei des richtigen Standorts nehmen.</li>
	</ol>
	<p class="description"><strong>Nicht mit echter Datei getestet.</strong> Der Import ist nach dem dokumentierten Bewertungsformat der Business Profile API gebaut (<code>reviewer.displayName</code>, <code>starRating</code>, <code>comment</code>, <code>createTime</code>, <code>name</code>). Erkennt das Plugin die Datei nicht, kommt „Format nicht erkannt“, es wird nichts gespeichert. Anzahl nach dem Import mit dem Google-Profil vergleichen.</p>

	<h3>c) CSV von Hand</h3>
	<p>UTF-8 (Excel-Export in Windows-1252 wird auch erkannt), Trennzeichen Komma oder Semikolon, erste Zeile mit den Spaltennamen:</p>
	<pre><code>source,name,rating,date,text,url
google,Max Mustermann,5,14.03.2025,"Sehr zufrieden, gerne wieder.",
11880,Erika Beispiel,4,2025-06-01,Gute Beratung.,https://www.11880.com/…
Werkenntdenbesten,Hans Muster,5,01.02.2026,Top!,</code></pre>
	<p class="description"><code>source</code>: Kürzel oder Name der Plattform, unbekannte Namen werden „Sonstige“ mit diesem Namen. <code>rating</code>: 1–5. <code>date</code>: TT.MM.JJJJ oder JJJJ-MM-TT. <code>url</code> ist optional.</p>

	<h2 id="umstieg">6. Umstieg von den alten Fluent-Snippets</h2>
	<ol>
		<li>Datenbank sichern (z. B. Plesk-Backup).</li>
		<li>In Fluent Snippets die Snippets für Google-Rezensionen deaktivieren: Hauptsnippet (Abruf und Grid), Badge, Button und Backend-Seite. Ein Snippet für die Weiterleitung zum Bewertungsformular kann aktiv bleiben.</li>
		<li>Plugin aktivieren. Es übernimmt einmalig Archiv, Note, Anzahl und den Direktlink. Die alten Daten bleiben als Rückfallebene liegen. Solange die Snippets noch aktiv sind, zeigt das Backend eine rote Warnung.</li>
		<li>Unter <a href="<?php echo esc_url( $local_reviews_settings_url ); ?>">Einstellungen</a> API-Key, Place ID und Firmenname eintragen. Der Key lässt sich nicht übernehmen, weil er im Snippet-Code stand.</li>
		<li>Den alten Outscraper-Export unter <a href="<?php echo esc_url( $local_reviews_import_url ); ?>">Import</a> im Modus <strong>„Nur zuordnen“</strong> hochladen. Die übernommenen Einträge werden dann als „Import“ geführt und lassen sich bearbeiten.</li>
		<li>Unter <a href="<?php echo esc_url( $local_reviews_reviews_url ); ?>">Bewertungen</a> mögliche Dubletten prüfen und den Eintrag aus dem Google-Abruf ausblenden.</li>
		<li>Seiten mit den Shortcodes ausgeloggt prüfen. Wenn alles passt: Status › <strong>Altdaten löschen</strong>.</li>
	</ol>
	<p class="description">Die Shortcode-Namen bleiben gleich, Seiten müssen nicht angepasst werden. Die CSS-Klassen ändern sich (neu: <code>lr-*</code>); eigenes CSS auf die alten Klassen muss umgestellt werden.</p>

	<h2 id="cron">7. Server-Cron einrichten</h2>
	<p>WordPress startet geplante Aufgaben nur bei Seitenaufrufen, die PHP erreichen. Mit Seiten-Cache passiert das selten. Das Plugin holt einen ausgefallenen Abruf beim nächsten Backend-Besuch nach; zuverlässiger ist ein echter Server-Cron.</p>
	<h3>Plesk</h3>
	<ol>
		<li><strong>Websites &amp; Domains › Geplante Aufgaben › Aufgabe hinzufügen</strong>.</li>
		<li>Aufgabentyp <strong>URL abrufen</strong>, URL: <code><?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?></code></li>
		<li>Ausführen alle 15 Minuten (z. B. Cron-Stil <code>*/15 * * * *</code>), mit „Jetzt ausführen“ testen, speichern.</li>
		<li>In der <code>wp-config.php</code> oberhalb von „That's all, stop editing!“ ergänzen: <code>define( 'DISABLE_WP_CRON', true );</code></li>
	</ol>
	<p class="description">Alternativ im WP Toolkit von Plesk auf der Karte der Installation den Schalter „Take over wp-cron.php“ aktivieren; dann legt Plesk die Aufgabe selbst an (Standard alle 30 Minuten). Die Statusseite zeigt, ob <code>DISABLE_WP_CRON</code> gesetzt ist.</p>

	<h2 id="entwickler">8. Für Entwickler</h2>
	<table class="widefat striped">
		<tbody>
			<tr><td><code>local_reviews_get_summary()</code></td><td>Liefert <code>[ 'rating' =&gt; 5.0, 'count' =&gt; 83 ]</code> laut Google, z. B. für Schema-Markup in SEOPress oder Rank Math. Das Plugin selbst gibt kein Schema aus. Vor der Ausgabe <code>count &gt; 0</code> prüfen, ohne Abruf liefert die Funktion 0/0.</td></tr>
			<tr><td><code>local_reviews_updated</code> (Action)</td><td>Feuert, wenn sich angezeigte Daten geändert haben. Für eigene Cache-Lösungen. Automatisch geleert werden WP Rocket/AccelerateWP, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, Cache Enabler, SiteGround Optimizer und Breeze.</td></tr>
			<tr><td><code>kf_google_reviews_updated</code> (Action)</td><td>Veraltet, feuert nur zur Kompatibilität mit den alten Snippets. Bitte <code>local_reviews_updated</code> nutzen.</td></tr>
			<tr><td><code>local_reviews_sources</code> (Filter)</td><td>Plattformliste ändern: <code>add_filter( 'local_reviews_sources', fn( $s ) =&gt; $s + [ 'werkenntdenbesten' =&gt; 'Werkenntdenbesten' ] );</code> – mit <code>+</code> oder <code>$s['kuerzel'] = …</code> ergänzen, nicht mit <code>array_merge()</code> (das macht aus dem Kürzel 11880 eine 0).</td></tr>
			<tr><td>CSS</td><td>Klassen <code>lr-header</code>, <code>lr-reviews__grid</code>, <code>lr-card</code>, <code>lr-card__name|stars|text|date</code>, <code>lr-source lr-source--{plattform}</code>, <code>lr-badge</code>, <code>lr-button lr-button--google</code>. Variablen <code>--lr-star-color</code>, <code>--lr-font</code>, <code>--lr-card-width</code>.</td></tr>
		</tbody>
	</table>

	<h2 id="grenzen">9. Grenzen und Kosten</h2>
	<ul class="ul-disc">
		<li>Die Places API liefert höchstens 5 Bewertungen pro Abruf. Neue Bewertungen erscheinen nur, wenn Google sie unter diese 5 nimmt. Die Statusseite warnt, wenn Google mehr Bewertungen zählt, als im Archiv liegen; dann per Import nachholen.</li>
		<li>Ein Abruf mit Bewertungen fällt bei Google in die SKU „Place Details Enterprise + Atmosphere“. Laut Preisliste (Stand 2026) sind 1.000 Aufrufe pro Monat kostenlos; das Plugin ruft einmal täglich ab, plus Klicks auf „Jetzt aktualisieren“.</li>
		<li>Das Profilfoto wird höchstens 7 Tage alt, Google erlaubt das Zwischenspeichern von Fotos bis 30 Tage.</li>
	</ul>
</div>
