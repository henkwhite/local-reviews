<?php
/**
 * Integrationstests für Local Reviews. Aufruf: tools/test.sh
 *
 * Läuft per „studio wp eval-file“ in der Wegwerfseite local-reviews-ci.
 * Google wird nicht wirklich kontaktiert: alle HTTP-Aufrufe werden über
 * pre_http_request abgefangen, aufgezeichnet und mit vorbereiteten Antworten
 * beantwortet. Am Ende wird uninstall.php ausgeführt (Daten des Plugins weg).
 */

use LocalReviews\Cache;
use LocalReviews\Fetcher;
use LocalReviews\Importer;
use LocalReviews\Migration;
use LocalReviews\Photo;
use LocalReviews\Profile;
use LocalReviews\Settings;
use LocalReviews\Sources;
use LocalReviews\Store;
use LocalReviews\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Bitte über WP-CLI starten: studio wp eval-file wp-content/lr-tests/run-tests.php\n" );
}

const T_KEY      = 'AIzaTEST_dummy_key_1234567890';
const T_PLACE    = 'ChIJTEST123placeid';
const T_REVIEWS  = 'https://www.google.com/maps/place//data=!4m4!3m3!1sTEST!9m1!1b1';
const T_WRITE    = 'https://www.google.com/maps/place//data=!4m3!3m2!1sTEST!12e1';
const T_PHOTO    = 'places/ChIJTEST123placeid/photos/AbCdEf_123-xyz';
const T_PHOTOURI = 'https://lh3.googleusercontent.com/places/test-photo=s300';

$GLOBALS['t_pass']       = 0;
$GLOBALS['t_fail']       = 0;
$GLOBALS['http_log']     = [];
$GLOBALS['http_other']   = [];
$GLOBALS['hook_count']   = [ 'local_reviews_updated' => 0, 'kf_google_reviews_updated' => 0 ];

/* ------------------------------------------------------------------ Helfer */

function ok( bool $cond, string $name, string $detail = '' ): void {
	if ( $cond ) {
		$GLOBALS['t_pass']++;
		echo "  ✓ $name\n";
	} else {
		$GLOBALS['t_fail']++;
		echo "  ✗ $name" . ( '' !== $detail ? "  ← $detail" : '' ) . "\n";
	}
}

function section( string $title ): void {
	echo "\n$title\n";
}

/** Kurzform für Detailausgaben. */
function dump( mixed $v ): string {
	return mb_substr( (string) wp_json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 0, 400 );
}

/** Kleines echtes JPEG als String. */
function tiny_jpeg(): string {
	$im = imagecreatetruecolor( 4, 4 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 200, 30, 30 ) );
	ob_start();
	imagejpeg( $im );
	return (string) ob_get_clean();
}

function uploads_dir( string $sub ): string {
	$u = wp_upload_dir( null, false );
	return trailingslashit( $u['basedir'] ) . $sub;
}

function rrmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $f ) {
		if ( '.' === $f || '..' === $f ) {
			continue;
		}
		$p = $dir . '/' . $f;
		is_dir( $p ) ? rrmdir( $p ) : unlink( $p );
	}
	rmdir( $dir );
}

/** Alle Optionsnamen direkt aus der Datenbank (ohne Objekt-Cache). */
function db_option_names(): array {
	global $wpdb;
	return (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options}" );
}

function db_autoload( string $option ): string {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );
}

/** Setzt die Seite auf einen sauberen Stand mit Dummy-Key und Place ID. */
function reset_state( bool $configured = true ): void {
	foreach ( db_option_names() as $name ) {
		if ( str_starts_with( $name, 'local_reviews_' ) || str_starts_with( $name, '_transient_local_reviews_' ) || str_starts_with( $name, '_transient_timeout_local_reviews_' ) ) {
			delete_option( $name );
		}
	}
	foreach ( Migration::LEGACY_OPTIONS as $o ) {
		delete_option( $o );
	}
	delete_transient( Migration::LEGACY_TRANSIENT );
	wp_cache_flush();
	Store::flush();
	rrmdir( uploads_dir( 'local-reviews' ) );
	rrmdir( uploads_dir( 'google-profil' ) );
	remove_all_filters( 'local_reviews_sources' );
	if ( $configured ) {
		Settings::set_api_key( T_KEY );
		Settings::update( [ 'place_id' => T_PLACE, 'language' => 'de', 'date_format' => 'd.m.Y' ] );
	}
	$GLOBALS['http_log']   = [];
	$GLOBALS['http_other'] = [];
	set_mock();
}

/** Eintrag direkt ins Archiv schreiben. */
function seed( array $entries ): void {
	$all = [];
	foreach ( $entries as $e ) {
		$all[ $e['id'] ] = $e;
	}
	Store::save_all( $all );
}

function api_review( string $id, string $name, int $rating, string $text, string $time ): array {
	return [
		'name'                           => 'places/' . T_PLACE . '/reviews/' . $id,
		'rating'                         => $rating,
		'text'                           => [ 'text' => $text, 'languageCode' => 'de' ],
		'originalText'                   => [ 'text' => $text, 'languageCode' => 'de' ],
		'authorAttribution'              => [ 'displayName' => $name, 'uri' => 'https://www.google.com/maps/contrib/1', 'photoUri' => 'https://lh3.googleusercontent.com/a/x' ],
		'publishTime'                    => $time,
		'relativePublishTimeDescription' => 'vor einem Monat',
		'googleMapsUri'                  => 'https://www.google.com/maps/reviews/data=!4m8!14m7!1m6!2m5!1s' . $id,
	];
}

function default_reviews(): array {
	return [
		api_review( 'R1', 'Anna Neu', 5, 'Super Service, gerne wieder!', '2024-06-01T09:00:00Z' ),
		api_review( 'R2', 'Bernd Beispiel', 4, 'Gute Arbeit, kleine Verzögerung.', '2024-05-20T09:00:00Z' ),
		api_review( 'R3', 'Carla Kurz', 5, '', '2024-05-10T09:00:00Z' ),
	];
}

function place_body( array $over = [] ): string {
	return (string) wp_json_encode(
		array_merge(
			[
				'displayName'     => [ 'text' => 'Testfirma GmbH', 'languageCode' => 'de' ],
				'rating'          => 4.8,
				'userRatingCount' => 83,
				'googleMapsLinks' => [ 'reviewsUri' => T_REVIEWS, 'writeAReviewUri' => T_WRITE ],
				'photos'          => [ [ 'name' => T_PHOTO, 'widthPx' => 800, 'heightPx' => 600 ] ],
				'reviews'         => default_reviews(),
			],
			$over
		)
	);
}

/** Antworten des simulierten Google. */
function set_mock( array $over = [] ): void {
	$GLOBALS['mock'] = array_merge(
		[
			'place'    => [ 200, place_body() ],
			'media'    => [ 200, (string) wp_json_encode( [ 'name' => T_PHOTO . '/media', 'photoUri' => T_PHOTOURI ] ) ],
			'download' => [ 200, tiny_jpeg() ],
		],
		$over
	);
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( 'places.googleapis.com' === $host ) {
			$kind = str_contains( (string) wp_parse_url( $url, PHP_URL_PATH ), '/media' ) ? 'media' : 'place';
		} elseif ( 'lh3.googleusercontent.com' === $host ) {
			$kind = 'download';
		} else {
			// Alles andere (z. B. Update-Prüfungen) wird blockiert, aber nicht als Google-Aufruf gezählt.
			$GLOBALS['http_other'][] = $url;
			return new WP_Error( 'lr_test_blocked', 'Test: externer Aufruf blockiert' );
		}
		$GLOBALS['http_log'][] = [ 'kind' => $kind, 'url' => $url, 'args' => $args ];
		[ $code, $body ] = $GLOBALS['mock'][ $kind ];
		return [
			'headers'  => [],
			'body'     => $body,
			'response' => [ 'code' => $code, 'message' => '' ],
			'cookies'  => [],
			'filename' => null,
		];
	},
	10,
	3
);

foreach ( array_keys( $GLOBALS['hook_count'] ) as $hook ) {
	add_action(
		$hook,
		static function () use ( $hook ) {
			$GLOBALS['hook_count'][ $hook ]++;
		}
	);
}

function hooks_reset(): void {
	foreach ( $GLOBALS['hook_count'] as $k => $v ) {
		$GLOBALS['hook_count'][ $k ] = 0;
	}
}

function http_calls( string $kind ): array {
	return array_values( array_filter( $GLOBALS['http_log'], fn( $c ) => $c['kind'] === $kind ) );
}

/** Kurzer Eintrag für Store-Tests. */
function entry( string $id, array $f = [] ): array {
	return array_merge(
		[
			'id'        => $id,
			'source'    => 'google',
			'origin'    => 'import',
			'name'      => 'Name ' . $id,
			'rating'    => 5,
			'text'      => 'Text von ' . $id,
			'timestamp' => strtotime( '2024-01-15 12:00:00 UTC' ),
			'url'       => '',
			'hidden'    => false,
		],
		$f
	);
}

echo "Local Reviews – Integrationstests\n";
echo 'WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION . ', Plugin ' . LocalReviews\VERSION . "\n";

/* ============================================================ 1. Migration */

section( '1. Migration aus den alten Snippets' );
reset_state( false );

$ts_a  = strtotime( '2023-03-01 10:00:00 UTC' );
$ts_b  = strtotime( '2023-03-02 10:00:00 UTC' );
$key_a = md5( 'Anna Alt' . substr( 'Sehr gut<br>gemacht, danke!', 0, 50 ) );
$key_b = 'legacykey_bernd_0001';
update_option(
	'mein_google_reviews_archiv',
	[
		$key_a => [ 'name' => 'Anna Alt', 'text' => 'Sehr gut<br>gemacht, danke!', 'rating' => 5, 'date' => '01.03.2023', 'timestamp' => $ts_a ],
		$key_b => [ 'name' => 'Bernd Alt', 'text' => 'Zeile eins<br />Zeile zwei', 'rating' => '4', 'date' => '02.03.2023', 'timestamp' => $ts_b ],
	]
);
update_option( 'google_profil_global_rating', '4.75' );
update_option( 'google_profil_global_count', '83' );
update_option( 'google_profil_photo_url', 'http://localhost/wp-content/uploads/google-profil/profil.jpg' );
update_option( 'google_profil_last_error', 'Alter Fehler vom Snippet' );
set_transient( Migration::LEGACY_TRANSIENT, 'https://www.google.com/maps/place//data=!4m4!3m3!1sALT', DAY_IN_SECONDS );
$legacy_before = get_option( 'mein_google_reviews_archiv' );

Migration::maybe_run();
Store::flush();
$all = Store::all();

ok( 2 === count( $all ) && isset( $all[ $key_a ], $all[ $key_b ] ), 'alte Schlüssel bleiben die IDs', dump( array_keys( $all ) ) );
ok( 'google' === ( $all[ $key_a ]['source'] ?? '' ) && 'google' === ( $all[ $key_b ]['source'] ?? '' ), 'source = google' );
ok( 'api' === ( $all[ $key_a ]['origin'] ?? '' ) && 'api' === ( $all[ $key_b ]['origin'] ?? '' ), 'origin = api' );
ok( "Sehr gut\ngemacht, danke!" === ( $all[ $key_a ]['text'] ?? '' ) && "Zeile eins\nZeile zwei" === ( $all[ $key_b ]['text'] ?? '' ), 'Text ohne <br>, als Zeilenumbruch', dump( array_column( $all, 'text' ) ) );
ok( $ts_a === ( $all[ $key_a ]['timestamp'] ?? 0 ) && 4 === ( $all[ $key_b ]['rating'] ?? 0 ), 'Zeitstempel und Sterne übernommen' );
ok( ! isset( $all[ $key_a ]['date'] ), 'Feld date entfällt' );
$p = Profile::get();
ok( 4.8 === $p['rating'] && 83 === $p['count'], 'Profil: Note (gerundet) und Anzahl übernommen', dump( $p ) );
ok( str_contains( $p['reviews_uri'], '1sALT' ), 'Direktlink aus Transient als reviews_uri übernommen', $p['reviews_uri'] );
ok( 'Alter Fehler vom Snippet' === Profile::status()['last_error'], 'letzter Fehler übernommen' );
ok( '' === $p['photo_url'] && str_contains( Profile::photo_url(), 'google-profil/profil.jpg' ), 'Foto nicht kopiert, altes Foto bis zum ersten Abruf als Rückfall' );
ok( 'd.m.Y' === Settings::get( 'date_format' ), 'date_format = d.m.Y' );
ok( (bool) get_option( 'local_reviews_migrated' ), 'Flag local_reviews_migrated gesetzt' );
ok( in_array( db_autoload( 'local_reviews_archive' ), [ 'no', 'off' ], true ), 'Archiv-Option ohne Autoload', db_autoload( 'local_reviews_archive' ) );
ok( $legacy_before === get_option( 'mein_google_reviews_archiv' ), 'altes Archiv unverändert vorhanden' );
ok( '4.75' === get_option( 'google_profil_global_rating' ) && '83' === get_option( 'google_profil_global_count' ) && false !== get_option( 'google_profil_photo_url' ) && false !== get_option( 'google_profil_last_error' ), 'alte Profil-Optionen bleiben bestehen' );
ok( false !== get_transient( Migration::LEGACY_TRANSIENT ), 'alter Transient bleibt bestehen' );

$snap = get_option( 'local_reviews_archive' );
update_option( 'mein_google_reviews_archiv', [ 'neu' => [ 'name' => 'X', 'text' => 'Y', 'rating' => 1, 'timestamp' => $ts_a ] ] );
Migration::maybe_run();
Store::flush();
ok( $snap === get_option( 'local_reviews_archive' ), 'zweiter Lauf ändert nichts' );
delete_option( 'local_reviews_migrated' );
Migration::maybe_run();
Store::flush();
ok( $snap === get_option( 'local_reviews_archive' ), 'ohne Flag, aber mit gefülltem Archiv: nichts überschrieben' );

reset_state( false );
Migration::maybe_run();
ok( false === get_option( 'local_reviews_archive', false ) && ! get_option( 'local_reviews_migrated' ), 'ohne Altdaten: kein Archiv angelegt, Flag bleibt offen (später eingespielte Altdaten werden noch übernommen)' );
update_option( 'mein_google_reviews_archiv', [ 'spaet' => [ 'name' => 'Spät', 'text' => 'Nachgereicht', 'rating' => 5, 'timestamp' => $ts_a ] ] );
Migration::maybe_run();
Store::flush();
ok( null !== Store::get( 'spaet' ) && (bool) get_option( 'local_reviews_migrated' ), 'später eingespielte Altdaten werden übernommen, danach Flag gesetzt' );
ok( Migration::legacy_is_copied(), 'legacy_is_copied nach Migration' );
Store::save_all( [] );
ok( ! Migration::legacy_is_copied(), 'legacy_is_copied falsch, wenn Einträge fehlen' );

/* ============================================================== 2. Fetcher */

section( '2. Abruf über die (simulierte) Places API' );
reset_state( false );
$r = Fetcher::run();
ok( is_wp_error( $r ) && 'local_reviews_not_configured' === $r->get_error_code() && [] === $GLOBALS['http_log'], 'ohne Key/Place ID: Fehler, kein HTTP-Aufruf' );

reset_state();
$r = Fetcher::run();
ok( true === $r, 'Abruf erfolgreich', is_wp_error( $r ) ? $r->get_error_message() : '' );
$place_calls = http_calls( 'place' );
ok( 1 === count( $place_calls ), 'genau ein Places-Aufruf' );
$c = $place_calls[0] ?? [ 'url' => '', 'args' => [ 'headers' => [] ] ];
ok( T_KEY === ( $c['args']['headers']['X-Goog-Api-Key'] ?? '' ) && ! str_contains( $c['url'], T_KEY ), 'Key im Header, nicht in der URL' );
ok( str_contains( $c['url'], '/v1/places/' . T_PLACE ) && str_contains( $c['url'], 'languageCode=de' ), 'URL mit Place ID und Sprache', $c['url'] );
ok( str_contains( (string) ( $c['args']['headers']['X-Goog-FieldMask'] ?? '' ), 'reviews' ), 'FieldMask gesetzt' );

$all = Store::all();
ok( 3 === count( $all ), 'drei neue Rezensionen angelegt', (string) count( $all ) );
$id_anna = Text::legacy_id( 'Anna Neu', 'Super Service, gerne wieder!' );
ok( isset( $all[ $id_anna ] ), 'ID = md5(Name + Textanfang)' );
$a = $all[ $id_anna ] ?? [];
ok( 'google' === ( $a['source'] ?? '' ) && 'api' === ( $a['origin'] ?? '' ) && 5 === ( $a['rating'] ?? 0 ), 'source google, origin api, Sterne' );
ok( strtotime( '2024-06-01T09:00:00Z' ) === ( $a['timestamp'] ?? 0 ), 'publishTime als Zeitstempel' );
ok( str_ends_with( (string) ( $a['external_id'] ?? '' ), '/reviews/R1' ) && str_contains( (string) ( $a['url'] ?? '' ), '1sR1' ), 'external_id und URL gespeichert' );

$p = Profile::get();
ok( 4.8 === $p['rating'] && 83 === $p['count'], 'Profil: Note und Anzahl', dump( $p ) );
ok( 'Testfirma GmbH' === $p['name'] && 'Testfirma GmbH' === Profile::company_name(), 'Profil: Name aus displayName' );
ok( esc_url_raw( T_REVIEWS ) === $p['reviews_uri'] && esc_url_raw( T_WRITE ) === $p['write_uri'], 'Profil: reviewsUri und writeAReviewUri' );
ok( 1 === count( http_calls( 'media' ) ) && 1 === count( http_calls( 'download' ) ), 'Foto: Media-Endpunkt und Download je einmal' );
$media = http_calls( 'media' )[0]['url'] ?? '';
ok( str_contains( $media, T_PHOTO . '/media' ) && ! str_contains( $media, T_KEY ), 'Foto-URL aus photos[0], Key nicht in der URL' );
$photo_file = uploads_dir( 'local-reviews' ) . '/profile.jpg';
ok( file_exists( $photo_file ) && false !== @getimagesize( $photo_file ), 'Foto als echte JPEG-Datei in uploads/local-reviews/ gespeichert' );
ok( str_contains( $p['photo_url'], '/uploads/local-reviews/profile.jpg' ) && $p['photo_time'] > 0, 'photo_url zeigt auf lokale Datei', $p['photo_url'] );
$st = Profile::status();
ok( '' === $st['last_error'] && $st['last_success'] > 0, 'Status: kein Fehler, letzter Erfolg gesetzt' );
ok( [] === array_filter( $GLOBALS['http_log'], fn( $c ) => ! in_array( wp_parse_url( $c['url'], PHP_URL_HOST ), [ 'places.googleapis.com', 'lh3.googleusercontent.com' ], true ) ), 'keine anderen Google-Hosts angefragt' );

// Zweiter Abruf: keine Dubletten, ausgeblendete bleiben ausgeblendet, Foto nicht erneut.
Store::set_hidden( $id_anna, true );
$GLOBALS['http_log'] = [];
Fetcher::run();
Store::flush();
$all = Store::all();
ok( 3 === count( $all ), 'zweiter Abruf legt nichts doppelt an', (string) count( $all ) );
ok( true === ( $all[ $id_anna ]['hidden'] ?? null ), 'ausgeblendeter Eintrag bleibt ausgeblendet' );
ok( 0 === count( http_calls( 'media' ) ), 'Foto wird innerhalb von 7 Tagen nicht neu geladen' );

// Vorhandener Eintrag mit gleicher md5-ID (z. B. aus Migration) wird nicht verändert.
reset_state();
seed( [ entry( $id_anna, [ 'name' => 'Anna Neu', 'text' => 'Super Service, gerne wieder!', 'origin' => 'api', 'rating' => 3, 'hidden' => true, 'timestamp' => strtotime( '2024-06-01T09:00:00Z' ) ] ) ] );
Fetcher::run();
Store::flush();
$all = Store::all();
ok( 3 === count( $all ) && 3 === ( $all[ $id_anna ]['rating'] ?? 0 ) && true === ( $all[ $id_anna ]['hidden'] ?? null ), 'vorhandene ID: nicht doppelt, nicht überschrieben, hidden bleibt' );

// Mögliche Dublette mit gleichem Namen, aber anderen Anführungszeichen.
reset_state();
$imp_text = '„Tolle Arbeit“, sehr zuverlässig und pünktlich. Gerne wieder!';
$api_text = '"Tolle Arbeit", sehr zuverlässig und pünktlich. Gerne wieder!';
seed( [ entry( 'imp_erika', [ 'name' => 'Erika Muster', 'text' => $imp_text, 'origin' => 'import', 'timestamp' => strtotime( '2024-04-10 15:00:00 UTC' ) ] ) ] );
set_mock( [ 'place' => [ 200, place_body( [ 'reviews' => [ api_review( 'R9', 'Erika Muster', 5, $api_text, '2024-04-10T09:00:00Z' ) ] ] ) ] ] );
Fetcher::run();
Store::flush();
ok( 1 === count( Store::all() ), 'mögliche Dublette mit gleichem Namen wird nicht neu angelegt', dump( array_keys( Store::all() ) ) );

// Gleicher Textanfang, aber anderer Name: wird angelegt.
reset_state();
seed( [ entry( 'imp_erika', [ 'name' => 'Erika Muster', 'text' => $imp_text, 'origin' => 'import', 'timestamp' => strtotime( '2024-04-10 15:00:00 UTC' ) ] ) ] );
set_mock( [ 'place' => [ 200, place_body( [ 'reviews' => [ api_review( 'R9', 'Jemand Anderes', 5, $api_text, '2024-04-10T09:00:00Z' ) ] ] ) ] ] );
Fetcher::run();
Store::flush();
ok( 2 === count( Store::all() ), 'gleicher Textanfang, anderer Name: wird angelegt' );

// Fehlerfall HTTP 403, die Meldung von Google enthält den Key.
reset_state();
Fetcher::run();
$archive_before = get_option( 'local_reviews_archive' );
$profile_before = Profile::get();
$success_before = Profile::status()['last_success'];
set_mock( [ 'place' => [ 403, (string) wp_json_encode( [ 'error' => [ 'code' => 403, 'message' => 'Requests from this API key ' . T_KEY . ' are blocked.', 'status' => 'PERMISSION_DENIED' ] ] ) ] ] );
$r  = Fetcher::run();
$st = Profile::status();
ok( is_wp_error( $r ), 'HTTP 403: Abruf liefert Fehler' );
ok( str_contains( $st['last_error'], 'HTTP 403' ) && str_contains( $st['last_error'], 'are blocked' ), 'last_error mit HTTP-Code und Google-Meldung', $st['last_error'] );
ok( ! str_contains( $st['last_error'], T_KEY ) && ! str_contains( $st['last_error'], 'TEST_dummy' ), 'Key nicht im gespeicherten Fehlertext', $st['last_error'] );
ok( $st['last_error_time'] > 0 && $success_before === $st['last_success'], 'Fehlerzeit gesetzt, letzter Erfolg unverändert' );
Store::flush();
ok( $archive_before === get_option( 'local_reviews_archive' ) && $profile_before === Profile::get(), 'Archiv und Profil bleiben beim Fehler unverändert' );

// Ungültiges JSON.
set_mock( [ 'place' => [ 200, 'kein json' ] ] );
$r = Fetcher::run();
ok( is_wp_error( $r ) && '' !== Profile::status()['last_error'], 'ungültiges JSON: Fehler wird gemerkt' );

// Fotofehler: Abruf läuft weiter, Key darf auch hier nicht im Fehlertext stehen.
reset_state();
set_mock( [ 'media' => [ 403, '{"error":{"code":403,"message":"API key ' . T_KEY . ' not authorized for photos"}}' ] ] );
$r  = Fetcher::run();
$st = Profile::status();
ok( true === $r && 3 === count( Store::all() ), 'Fotofehler bricht den Abruf nicht ab' );
ok( str_contains( $st['last_error'], 'Foto' ), 'Fotofehler landet unter last_error', $st['last_error'] );
ok( ! str_contains( $st['last_error'], T_KEY ), 'Key nicht im Fotofehler-Text', $st['last_error'] );

// Kein gültiges Bild.
reset_state();
set_mock( [ 'download' => [ 200, '<html>kein Bild</html>' ] ] );
Fetcher::run();
ok( ! file_exists( uploads_dir( 'local-reviews' ) . '/profile.jpg' ) && '' === Profile::get()['photo_url'], 'ungültige Bilddaten werden nicht gespeichert' );

// photoUri von fremdem Host wird nicht geladen.
reset_state();
set_mock( [ 'media' => [ 200, (string) wp_json_encode( [ 'photoUri' => 'https://evil.example.com/x.jpg' ] ) ] ] );
$r = Fetcher::run();
ok( true === $r && 0 === count( http_calls( 'download' ) ) && [] === $GLOBALS['http_other'] && '' === Profile::get()['photo_url'], 'photoUri außerhalb googleusercontent/ggpht wird nicht geladen' );
ok( 0 === ( http_calls( 'place' )[0]['args']['redirection'] ?? -1 ) && 0 === ( http_calls( 'media' )[0]['args']['redirection'] ?? -1 ), 'API-Anfragen mit redirection = 0' );

// Mediathek als Fotoquelle: kein Foto-Abruf.
reset_state();
Settings::update( [ 'photo_source' => 'media' ] );
Fetcher::run();
ok( 0 === count( http_calls( 'media' ) ), 'photo_source = media: kein Foto-Abruf bei Google' );

/* ================================================================= 3. Cache */

section( '3. Cache-Hooks nur bei sichtbarer Änderung' );
reset_state();
hooks_reset();
Fetcher::run();
ok( 1 === $GLOBALS['hook_count']['local_reviews_updated'], 'erster Abruf: local_reviews_updated feuert', dump( $GLOBALS['hook_count'] ) );
ok( 1 === $GLOBALS['hook_count']['kf_google_reviews_updated'], 'kf_google_reviews_updated feuert mit' );
hooks_reset();
Fetcher::run();
ok( 0 === $GLOBALS['hook_count']['local_reviews_updated'] && 0 === $GLOBALS['hook_count']['kf_google_reviews_updated'], 'zweiter identischer Abruf: kein Feuern', dump( $GLOBALS['hook_count'] ) );
hooks_reset();
set_mock( [ 'place' => [ 200, place_body( [ 'userRatingCount' => 84 ] ) ] ] );
Fetcher::run();
ok( 1 === $GLOBALS['hook_count']['local_reviews_updated'], 'geänderte Anzahl: feuert' );
hooks_reset();
$reviews   = default_reviews();
$reviews[] = api_review( 'R4', 'Dora Dazu', 5, 'Neue Bewertung.', '2024-07-01T09:00:00Z' );
set_mock( [ 'place' => [ 200, place_body( [ 'userRatingCount' => 84, 'reviews' => $reviews ] ) ] ] );
Fetcher::run();
ok( 1 === $GLOBALS['hook_count']['local_reviews_updated'], 'neue Rezension: feuert' );
hooks_reset();
set_mock( [ 'place' => [ 403, '{"error":{"message":"x"}}' ] ] );
Fetcher::run();
ok( 0 === $GLOBALS['hook_count']['local_reviews_updated'], 'Fehlerfall: kein Feuern' );
hooks_reset();
$before = Cache::snapshot();
Store::set_hidden( Text::legacy_id( 'Dora Dazu', 'Neue Bewertung.' ), true );
ok( true === Cache::purge_if_changed( $before ) && 1 === $GLOBALS['hook_count']['local_reviews_updated'], 'Ausblenden ändert die Prüfsumme (purge_if_changed)' );
hooks_reset();
$before = Cache::snapshot();
ok( false === Cache::purge_if_changed( $before ) && 0 === $GLOBALS['hook_count']['local_reviews_updated'], 'ohne Änderung: purge_if_changed feuert nicht' );

/* ============================================================== 4. Importer */

// Weitere Cache-Plugins (1.1.0): Testfunktionen simulieren W3TC und Breeze, eines wirft einen Fehler.
if ( ! function_exists( 'w3tc_flush_all' ) ) {
	function w3tc_flush_all(): void { // phpcs:ignore
		$GLOBALS['lr_test_w3tc'] = ( $GLOBALS['lr_test_w3tc'] ?? 0 ) + 1;
		throw new RuntimeException( 'W3TC kaputt' );
	}
}
if ( ! function_exists( 'breeze_clear_all_cache' ) ) {
	function breeze_clear_all_cache(): void { // phpcs:ignore
		$GLOBALS['lr_test_breeze'] = ( $GLOBALS['lr_test_breeze'] ?? 0 ) + 1;
	}
}
Profile::update_status( [ 'last_error' => '' ] );
Cache::purge();
ok( 1 === ( $GLOBALS['lr_test_w3tc'] ?? 0 ) && 1 === ( $GLOBALS['lr_test_breeze'] ?? 0 ), 'W3 Total Cache und Breeze werden geleert' );
ok( str_contains( Profile::status()['last_error'], 'W3 Total Cache' ), 'Fehler eines Cache-Plugins landet unter „Letzter Fehler“ und hält die anderen nicht auf', Profile::status()['last_error'] );
ok( str_contains( Cache::detected(), 'W3 Total Cache' ) && str_contains( Cache::detected(), 'Breeze' ), 'Statusseite nennt erkannte Cache-Plugins', Cache::detected() );

section( '4. Import' );
reset_state();

// CSV mit Komma, mehrzeiligem Feld, Plattformen und ungültigen Zeilen.
$csv = "source,name,rating,date,text,url\n"
	. "Google,Gustav Gans,5,01.05.2024,\"Erste Zeile\nZweite Zeile, mit Komma\",https://example.com/g1\n"
	. "11880,Elf Achtzig,4,2024-05-02,Über 11880 bewertet,https://www.11880.com/bewertung/1\n"
	. "Werkenntdenbesten,Wer Kennt,5,03.05.2024,Sonstige Plattform,\n"
	. "Sonstige,Ohne Label,3,04.05.2024,Plattform Sonstige,\n"
	. "Google,,5,01.05.2024,Name fehlt,\n"
	. "Google,Sterne Falsch,7,01.05.2024,Sieben Sterne,\n"
	. "Google,Datum Kaputt,5,irgendwann,Kein Datum,\n"
	. "\n";
$parsed = Importer::parse( $csv, 'bewertungen.csv' );
ok( is_array( $parsed ) && 'csv' === $parsed['format'], 'CSV mit Komma erkannt' );
$rows = is_array( $parsed ) ? $parsed['rows'] : [];
ok( 7 === count( $rows ), '7 Zeilen gelesen (Leerzeile übersprungen)', (string) count( $rows ) );
ok( "Erste Zeile\nZweite Zeile, mit Komma" === ( $rows[0]['text'] ?? '' ), 'mehrzeiliges Feld mit Komma', dump( $rows[0]['text'] ?? '' ) );
ok( 'google' === ( $rows[0]['source'] ?? '' ) && '11880' === ( $rows[1]['source'] ?? '' ), 'Plattform Google und 11880', dump( array_column( $rows, 'source' ) ) );
ok( 'other' === ( $rows[2]['source'] ?? '' ) && 'Werkenntdenbesten' === ( $rows[2]['source_label'] ?? '' ), '„Werkenntdenbesten“ → other mit Freitext' );
ok( 'other' === ( $rows[3]['source'] ?? '' ) && '' === ( $rows[3]['source_label'] ?? 'x' ), '„Sonstige“ → other ohne Freitext' );
$noon = static fn( string $ymd ) => ( new DateTimeImmutable( $ymd . ' 12:00', wp_timezone() ) )->getTimestamp();
ok( $noon( '2024-05-01' ) === ( $rows[0]['timestamp'] ?? 0 ) && $noon( '2024-05-02' ) === ( $rows[1]['timestamp'] ?? 0 ), 'Datum TT.MM.JJJJ und JJJJ-MM-TT' );
ok( '' !== ( $rows[4]['error'] ?? '' ) && '' !== ( $rows[5]['error'] ?? '' ) && '' !== ( $rows[6]['error'] ?? '' ), 'ungültige Zeilen (Name, Sterne, Datum) markiert' );
ok( '' === ( $rows[0]['error'] ?? 'x' ) && '' === ( $rows[1]['error'] ?? 'x' ), 'gültige Zeilen ohne Fehler' );
ok( 3 === ( $rows[1]['line'] ?? 0 ), 'Zeilennummer mitgeführt' );

// Semikolon + Windows-1252.
$csv_w = mb_convert_encoding( "source;name;rating;date;text;url\r\nGoogle;Jürgen Müller;5;05.05.2024;\"Schöne Grüße; alles prima\";\r\n11880;Öztürk;4;2024-05-06;Straße fertig;\r\n", 'Windows-1252', 'UTF-8' );
ok( ! mb_check_encoding( $csv_w, 'UTF-8' ), 'Testdatei ist wirklich Windows-1252' );
$parsed = Importer::parse( $csv_w, 'excel.CSV' );
$rows_w = is_array( $parsed ) ? $parsed['rows'] : [];
ok( 2 === count( $rows_w ), 'CSV mit Semikolon gelesen', (string) count( $rows_w ) );
ok( 'Jürgen Müller' === ( $rows_w[0]['name'] ?? '' ) && 'Schöne Grüße; alles prima' === ( $rows_w[0]['text'] ?? '' ), 'Windows-1252 nach UTF-8 umgewandelt, Semikolon im Feld', dump( $rows_w[0] ?? [] ) );
ok( '11880' === ( $rows_w[1]['source'] ?? '' ) && 'Öztürk' === ( $rows_w[1]['name'] ?? '' ), 'zweite Zeile korrekt' );

// UTF-8 mit BOM.
$parsed = Importer::parse( "\xEF\xBB\xBFsource,name,rating,date,text,url\nGoogle,Bom Test,5,01.01.2024,x,\n", 'bom.csv' );
ok( is_array( $parsed ) && 'Bom Test' === ( $parsed['rows'][0]['name'] ?? '' ) && '' === $parsed['rows'][0]['error'], 'UTF-8-BOM wird entfernt' );

// Unvollständige Kopfzeile.
$parsed = Importer::parse( "name,text\nA,B\n", 'falsch.csv' );
ok( is_wp_error( $parsed ), 'CSV mit falscher Kopfzeile: Format nicht erkannt (WP_Error)' );
ok( 0 === Text::parse_date( '2024-02-30' ) && 0 < Text::parse_date( '29.02.2024' ), 'parse_date: 2024-02-30 ungültig, 29.02.2024 (Schaltjahr) gültig' );
ok( 0 === Text::parse_date( '31.02.2024 10:00' ), 'parse_date: ungültiges Datum mit Uhrzeit → 0' );

// Outscraper.
$outscraper = (string) wp_json_encode(
	[
		[ 'author_title' => 'Olga Out', 'review_rating' => 5, 'review_text' => 'Text<br>Zeile', 'review_timestamp' => 1700000000, 'review_datetime_utc' => '11/14/2023 22:13:20', 'review_link' => 'https://www.google.com/maps/reviews/data=!4m8!14m7!1m6!2m5!1sOLGA', 'review_id' => 'Ci9DQUlRQUNvZENodHljRjlvT2s5aFRUVTFOMk5SUlhSaVRVaDNaR1JTYW1nMFlXYxAB', 'author_image' => 'https://lh3.googleusercontent.com/x', 'owner_answer' => 'Danke!' ],
		[ 'author_title' => 'Otto Ohnezeit', 'review_rating' => 4, 'review_text' => null, 'review_timestamp' => 0, 'review_datetime_utc' => '11/15/2023 10:00:00', 'review_link' => '', 'review_id' => 'OTTO1' ],
	]
);
$parsed = Importer::parse( $outscraper, 'alte-bewertungen.json' );
ok( is_array( $parsed ) && 'outscraper' === $parsed['format'], 'Outscraper-JSON erkannt', is_wp_error( $parsed ) ? $parsed->get_error_message() : '' );
$o = is_array( $parsed ) ? $parsed['rows'] : [];
ok( 'Olga Out' === ( $o[0]['name'] ?? '' ) && 5 === ( $o[0]['rating'] ?? 0 ) && "Text\nZeile" === ( $o[0]['text'] ?? '' ), 'Name, Sterne, Text mit <br> → Umbruch' );
ok( 1700000000 === ( $o[0]['timestamp'] ?? 0 ) && str_contains( (string) ( $o[0]['url'] ?? '' ), '1sOLGA' ) && str_starts_with( (string) ( $o[0]['external_id'] ?? '' ), 'Ci9DQUlR' ), 'Zeitstempel, Link, review_id' );
ok( 'google' === ( $o[0]['source'] ?? '' ), 'Outscraper: source = google' );
ok( Text::legacy_id( 'Olga Out', 'Text<br>Zeile' ) === ( $o[0]['id'] ?? '' ) && Text::legacy_id( 'Olga Out', "Text\nZeile" ) === ( $o[0]['id_alt'] ?? '' ), 'ID aus Rohtext, id_alt aus bereinigtem Text' );
ok( 1700042400 === ( $o[1]['timestamp'] ?? 0 ) && '' === ( $o[1]['text'] ?? 'x' ) && '' === ( $o[1]['error'] ?? 'x' ), 'ohne review_timestamp: review_datetime_utc (UTC) genutzt', dump( $o[1] ?? [] ) );
ok( ! array_key_exists( 'author_image', $o[0] ?? [] ) && ! str_contains( dump( $o[0] ?? [] ), 'Danke!' ), 'author_image und owner_answer nicht übernommen' );

// Takeout.
$takeout = (string) wp_json_encode(
	[
		'reviews' => [
			[ 'name' => 'accounts/1/locations/2/reviews/3', 'reviewer' => [ 'displayName' => 'Tanja Take' ], 'starRating' => 'FOUR', 'comment' => 'Alles bestens.', 'createTime' => '2024-05-01T10:00:00Z' ],
			[ 'name' => 'accounts/1/locations/2/reviews/4', 'reviewer' => [ 'displayName' => 'John Doe' ], 'starRating' => 'FIVE', 'comment' => '(Translated by Google) Great work (Original) Tolle Arbeit, danke', 'createTime' => '2024-05-02T10:00:00Z' ],
			[ 'name' => 'accounts/1/locations/2/reviews/5', 'reviewer' => [ 'displayName' => 'Jane' ], 'starRating' => 'ONE', 'comment' => 'Sehr schlecht (Translated by Google) Very bad', 'createTime' => '2024-05-03T10:00:00Z' ],
		],
	]
);
$parsed = Importer::parse( $takeout, 'reviews.json' );
ok( is_array( $parsed ) && 'takeout' === $parsed['format'], 'Takeout-JSON erkannt', is_wp_error( $parsed ) ? $parsed->get_error_message() : dump( $parsed['format'] ?? '' ) );
$t = is_array( $parsed ) ? $parsed['rows'] : [];
ok( 'Tanja Take' === ( $t[0]['name'] ?? '' ) && 4 === ( $t[0]['rating'] ?? 0 ) && 'Alles bestens.' === ( $t[0]['text'] ?? '' ), 'Takeout: Name, FOUR → 4, Kommentar' );
ok( strtotime( '2024-05-01T10:00:00Z' ) === ( $t[0]['timestamp'] ?? 0 ) && 'accounts/1/locations/2/reviews/3' === ( $t[0]['external_id'] ?? '' ), 'Takeout: createTime und name → external_id' );
ok( 'Tolle Arbeit, danke' === ( $t[1]['text'] ?? '' ), '„(Translated by Google) … (Original) …“ → nur Original', dump( $t[1]['text'] ?? '' ) );
ok( 'Sehr schlecht' === ( $t[2]['text'] ?? '' ) && 1 === ( $t[2]['rating'] ?? 0 ), '„Original (Translated by Google) …“ → nur Original' );

// Unbekannte Formate.
ok( is_wp_error( Importer::parse( '{"foo":1}', 'x.json' ) ), 'unbekannte JSON-Struktur → WP_Error' );
ok( is_wp_error( Importer::parse( '[{"a":1}]', 'x.json' ) ), 'JSON-Liste ohne bekannte Felder → WP_Error' );
ok( is_wp_error( Importer::parse( 'kein json', 'x.json' ) ), 'ungültiges JSON → WP_Error' );
ok( is_wp_error( Importer::parse( 'a,b', 'x.txt' ) ), 'unbekannte Endung → WP_Error' );
$err = Importer::parse( '{"foo":1}', 'x.json' );
ok( is_wp_error( $err ) && str_contains( $err->get_error_message(), 'Format nicht erkannt' ), 'Meldung „Format nicht erkannt“' );

// classify().
reset_state();
$ts_ex = $noon( '2024-05-01' );
seed(
	[
		entry( Text::legacy_id( 'Gustav Gans', 'Vorhanden per md5' ), [ 'name' => 'Gustav Gans', 'text' => 'Vorhanden per md5', 'origin' => 'import', 'hidden' => true, 'rating' => 2, 'timestamp' => $ts_ex ] ),
		entry( 'ext_target', [ 'name' => 'Xaver', 'text' => 'Über externe ID', 'external_id' => 'EXT-1', 'origin' => 'import', 'timestamp' => $ts_ex ] ),
		entry( Text::legacy_id( 'Api Person', 'Von der API geholt' ), [ 'name' => 'Api Person', 'text' => 'Von der API geholt', 'origin' => 'api', 'external_id' => '', 'rating' => 5, 'timestamp' => $ts_ex ] ),
		entry( 'dup_target', [ 'name' => 'Paula Possible', 'text' => '„Wirklich hervorragende Beratung“ und Umsetzung', 'origin' => 'api', 'timestamp' => $ts_ex ] ),
	]
);
$crow = static function ( array $f ) use ( $ts_ex ): array {
	return array_merge( [ 'external_id' => '', 'source' => 'google', 'source_label' => '', 'rating' => 5, 'timestamp' => $ts_ex, 'url' => '', 'error' => '' ], $f, [ 'id' => Text::legacy_id( $f['name'], $f['raw'] ?? $f['text'] ), 'id_alt' => Text::legacy_id( $f['name'], $f['text'] ) ] );
};
$rows = [
	0 => $crow( [ 'name' => 'Neu Person', 'text' => 'Ganz neu' ] ),
	1 => $crow( [ 'name' => 'Gustav Gans', 'text' => 'Vorhanden per md5', 'rating' => 4, 'url' => 'https://example.com/gustav' ] ),
	2 => $crow( [ 'name' => 'Xaver Anders', 'text' => 'Anderer Text', 'external_id' => 'EXT-1' ] ),
	3 => $crow( [ 'name' => 'Paul P.', 'text' => '"Wirklich hervorragende Beratung" und mehr', 'timestamp' => $ts_ex + DAY_IN_SECONDS ] ),
	4 => $crow( [ 'name' => '', 'text' => 'x', 'error' => 'Name fehlt' ] ),
	5 => $crow( [ 'name' => 'Neu Person', 'text' => 'Ganz neu' ] ),
	6 => $crow( [ 'name' => 'Api Person', 'text' => 'Von der API geholt', 'rating' => 1 ] ),
	7 => $crow( [ 'name' => 'Br Person', 'raw' => 'Zeile<br>zwei', 'text' => "Zeile\nzwei" ] ),
];
$alt_id = Text::legacy_id( 'Br Person', "Zeile\nzwei" );
$all    = Store::all();
$all[ $alt_id ] = entry( $alt_id, [ 'name' => 'Br Person', 'text' => "Zeile\nzwei", 'timestamp' => $ts_ex - 20 * DAY_IN_SECONDS ] );
Store::save_all( $all );
$cl     = Importer::classify( $rows );
$status = array_column( $cl, 'status' );
ok( 'new' === $status[0], 'classify: new', dump( $status ) );
ok( 'existing' === $status[1] && Text::legacy_id( 'Gustav Gans', 'Vorhanden per md5' ) === $cl[1]['target'], 'classify: existing über md5' );
ok( 'existing' === $status[2] && 'ext_target' === $cl[2]['target'], 'classify: existing über external_id' );
ok( 'possible' === $status[3] && 'dup_target' === $cl[3]['target'], 'classify: possible (±1 Tag, andere Anführungszeichen)' );
ok( 'invalid' === $status[4], 'classify: invalid' );
ok( 'repeat' === $status[5], 'classify: repeat (doppelt in der Datei)' );
ok( 'existing' === $status[6] && Text::legacy_id( 'Api Person', 'Von der API geholt' ) === $cl[6]['target'], 'classify: API-Eintrag als existing erkannt' );
ok( 'existing' === $status[7] && $alt_id === $cl[7]['target'], 'classify: existing über id_alt (bereinigter Text)' );

// apply() im Modus import.
hooks_reset();
$res = Importer::apply( $cl, 'import', [] );
Store::flush();
$all = Store::all();
ok( 1 === $res['added'], 'import: eine neue Zeile angelegt', dump( $res ) );
$new_id = $rows[0]['id'];
ok( isset( $all[ $new_id ] ) && 'import' === $all[ $new_id ]['origin'] && false === $all[ $new_id ]['hidden'], 'neuer Eintrag: origin import, sichtbar' );
$g = $all[ Text::legacy_id( 'Gustav Gans', 'Vorhanden per md5' ) ];
ok( 4 === $g['rating'] && 'https://example.com/gustav' === $g['url'], 'vorhandener Import-Eintrag: Sterne und URL aktualisiert' );
ok( true === $g['hidden'] && 'import' === $g['origin'] && 'google' === $g['source'], 'vorhandener Eintrag: hidden/origin/source bleiben' );
$api_e = $all[ Text::legacy_id( 'Api Person', 'Von der API geholt' ) ] ?? [];
ok( 5 === ( $api_e['rating'] ?? 0 ) && 'Von der API geholt' === ( $api_e['text'] ?? '' ) && 'api' === ( $api_e['origin'] ?? '' ), 'API-Eintrag wird im Import nie überschrieben' );
ok( 'Paula Possible' === $all['dup_target']['name'] && ! isset( $all[ $rows[3]['id'] ] ), 'mögliche Dublette ohne Haken: nicht angelegt' );
ok( $res['updated'] >= 1 && 1 === $GLOBALS['hook_count']['local_reviews_updated'], 'Import mit Änderung: Cache-Hook feuert' );
hooks_reset();
$res2 = Importer::apply( Importer::classify( [ $rows[0] ] ), 'import', [] );
ok( 0 === $res2['added'] && 0 === $GLOBALS['hook_count']['local_reviews_updated'], 'erneuter Import ohne Änderung: nichts angelegt, kein Hook', dump( $res2 ) );

// Angehakte mögliche Dublette wird angelegt.
$cl  = Importer::classify( [ 3 => $rows[3] ] );
$res = Importer::apply( $cl, 'import', [ 3 ] );
Store::flush();
ok( 1 === $res['added'] && isset( Store::all()[ $rows[3]['id'] ] ), 'angehakte mögliche Dublette wird importiert', dump( $res ) );

// apply() im Modus assign.
reset_state();
seed(
	[
		entry( 'api_a', [ 'name' => 'Olga Out', 'text' => "Text\nZeile", 'origin' => 'api', 'url' => '', 'external_id' => '', 'hidden' => true, 'timestamp' => 1700000000 ] ),
		entry( 'api_b', [ 'name' => 'Paula', 'text' => '„Wirklich hervorragende Beratung“ toll', 'origin' => 'api', 'timestamp' => $ts_ex ] ),
		entry( 'man_c', [ 'name' => 'Manu', 'text' => 'Manuell', 'origin' => 'manual', 'timestamp' => $ts_ex ] ),
	]
);
$o_rows    = Importer::parse( $outscraper, 'alte-bewertungen.json' )['rows'];
$o_rows[0]['id'] = 'api_a'; // Treffer simulieren wie bei identischem md5.
$assign_rows = [
	0 => $o_rows[0],
	1 => $crow( [ 'name' => 'Paul', 'text' => '"Wirklich hervorragende Beratung" super', 'external_id' => 'EXT-P' ] ),
	2 => $crow( [ 'name' => 'Ganz Neu', 'text' => 'Nicht im Archiv' ] ),
	3 => $crow( [ 'name' => 'Manu', 'text' => 'Manuell', 'external_id' => 'EXT-M' ] ),
];
$assign_rows[3]['id'] = 'man_c';
$cl  = Importer::classify( $assign_rows );
ok( [ 'existing', 'possible', 'new', 'existing' ] === array_column( $cl, 'status' ), 'assign: Klassifizierung wie erwartet', dump( array_column( $cl, 'status' ) ) );
$count_before = count( Store::all() );
$res = Importer::apply( $cl, 'assign', [] );
Store::flush();
$all = Store::all();
ok( $count_before === count( $all ) && 0 === $res['added'], 'assign legt nichts an', dump( $res ) );
ok( 'import' === $all['api_a']['origin'] && str_starts_with( $all['api_a']['external_id'], 'Ci9DQUlR' ), 'assign: Treffer wird import, external_id gesetzt' );
ok( str_contains( $all['api_a']['url'], '1sOLGA' ) && true === $all['api_a']['hidden'], 'assign: URL ergänzt, hidden bleibt' );
ok( 'api' === $all['api_b']['origin'], 'assign: mögliche Dublette ohne Haken bleibt api' );
ok( 'manual' === $all['man_c']['origin'] && 'EXT-M' === $all['man_c']['external_id'], 'assign: manual bleibt manual' );
$res = Importer::apply( $cl, 'assign', [ 1 ] );
Store::flush();
ok( 'import' === Store::get( 'api_b' )['origin'] && 'EXT-P' === Store::get( 'api_b' )['external_id'], 'assign: angehakte mögliche Dublette wird zugeordnet' );

/* ================================================================= 5. Store */

section( '5. Store' );
reset_state();
$d = static fn( string $ymd ) => strtotime( $ymd . ' 12:00:00 UTC' );
seed(
	[
		entry( 'g1', [ 'rating' => 5, 'timestamp' => $d( '2024-03-01' ), 'text' => 'Google eins' ] ),
		entry( 'g2', [ 'rating' => 3, 'timestamp' => $d( '2024-01-01' ), 'text' => '', 'origin' => 'api' ] ),
		entry( 'e1', [ 'source' => '11880', 'rating' => 4, 'timestamp' => $d( '2024-02-01' ), 'text' => 'Elf', 'origin' => 'manual' ] ),
		entry( 'f1', [ 'source' => 'facebook', 'rating' => 5, 'timestamp' => $d( '2024-04-01' ), 'text' => 'Facebook' ] ),
		entry( 'h1', [ 'rating' => 5, 'timestamp' => $d( '2024-05-01' ), 'text' => 'versteckt', 'hidden' => true ] ),
	]
);
$ids = static fn( array $list ) => array_column( $list, 'id' );
ok( [ 'f1', 'g1', 'e1', 'g2' ] === $ids( Store::query( [ 'sort' => 'newest' ] ) ), 'query newest, ohne ausgeblendete', dump( $ids( Store::query( [ 'sort' => 'newest' ] ) ) ) );
ok( [ 'g2', 'e1', 'g1', 'f1' ] === $ids( Store::query( [ 'sort' => 'oldest' ] ) ), 'query oldest' );
ok( [ 'f1', 'g1', 'e1', 'g2' ] === $ids( Store::query( [ 'sort' => 'rating' ] ) ), 'query rating (bei Gleichstand neueste zuerst)' );
ok( [ 'e1' ] === $ids( Store::query( [ 'sources' => [ '11880' ] ] ) ), 'query sources [11880]' );
ok( [ 'f1', 'e1' ] === $ids( Store::query( [ 'sources' => [ '11880', 'facebook' ], 'sort' => 'newest' ] ) ), 'query mehrere Plattformen' );
ok( [ 'f1', 'g1' ] === $ids( Store::query( [ 'min_rating' => 5, 'sort' => 'newest' ] ) ), 'query min_rating 5' );
ok( ! in_array( 'g2', $ids( Store::query( [ 'hide_empty' => true ] ) ), true ), 'query hide_empty' );
ok( [ 'f1', 'g1' ] === $ids( Store::query( [ 'limit' => 2, 'sort' => 'newest' ] ) ), 'query limit 2' );
Settings::update( [ 'sort' => 'oldest' ] );
ok( 'g2' === ( $ids( Store::query() )[0] ?? '' ), 'query ohne sort nutzt Einstellung' );
Settings::update( [ 'sort' => 'newest' ] );

ok( [ 'total' => 5, 'google' => 3, 'hidden' => 1 ] === Store::counts(), 'counts', dump( Store::counts() ) );
ok( true === Store::set_hidden( 'g1', true ) && true === Store::get( 'g1' )['hidden'], 'set_hidden(true)' );
Store::flush();
ok( true === Store::get( 'g1' )['hidden'], 'set_hidden dauerhaft gespeichert' );
ok( true === Store::set_hidden( 'g1', false ) && false === Store::get( 'g1' )['hidden'], 'set_hidden(false)' );
ok( false === Store::set_hidden( 'gibtsnicht', true ), 'set_hidden für unbekannte ID: false' );
ok( false === Store::delete( 'g2' ) && null !== Store::get( 'g2' ), 'delete: API-Eintrag wird nicht gelöscht' );
ok( true === Store::delete( 'g1' ) && null === Store::get( 'g1' ), 'delete: Import-Eintrag gelöscht' );
ok( true === Store::delete( 'e1' ) && null === Store::get( 'e1' ), 'delete: manueller Eintrag gelöscht' );
ok( false === Store::delete( 'gibtsnicht' ), 'delete: unbekannte ID → false' );
Store::flush();
ok( null === Store::get( 'g1' ) && null !== Store::get( 'g2' ), 'Löschen dauerhaft gespeichert' );
ok( 'f1' === Store::find_by_external_id( '' ) ? false : true, 'find_by_external_id(\'\') → null' );

reset_state();
seed(
	[
		entry( 'd1', [ 'name' => 'Max', 'text' => '„Schnelle und saubere Arbeit“, Danke an das Team', 'timestamp' => $d( '2024-02-01' ) ] ),
		entry( 'd2', [ 'name' => 'Max M.', 'text' => '"Schnelle und saubere Arbeit", Danke!', 'timestamp' => $d( '2024-02-02' ) ] ),
		entry( 'd3', [ 'name' => 'Max', 'text' => '"Schnelle und saubere Arbeit", Danke!', 'timestamp' => $d( '2024-02-05' ) ] ),
		entry( 'd4', [ 'name' => 'Leer', 'text' => '', 'timestamp' => $d( '2024-02-01' ) ] ),
		entry( 'd5', [ 'name' => 'Leer', 'text' => '', 'timestamp' => $d( '2024-02-01' ) ] ),
	]
);
$map = Store::duplicate_map();
ok( [ 'd2' ] === ( $map['d1'] ?? [] ) && [ 'd1' ] === ( $map['d2'] ?? [] ), 'duplicate_map: Paar in beide Richtungen', dump( $map ) );
ok( ! isset( $map['d3'] ), 'duplicate_map: 4 Tage Abstand ist keine Dublette' );
ok( [ 'd5' ] === ( $map['d4'] ?? [] ), 'duplicate_map: leere Texte mit gleichem Namen' );
ok( 'd2' === Store::find_possible_duplicate( Store::get( 'd1' ), 'd1' ), 'find_possible_duplicate überspringt sich selbst' );

// normalize() alter Einträge.
$n = Store::normalize( [ 'name' => '<b>Alt</b>', 'text' => 'a<br>b', 'rating' => '9', 'date' => '01.03.2023', 'url' => 'javascript:alert(1)' ] );
ok( 'google' === $n['source'] && 'api' === $n['origin'] && 5 === $n['rating'] && 'Alt' === $n['name'], 'normalize: Standardwerte, Sterne begrenzt, Tags aus Name' );
ok( 'a<br>b' === $n['text'] && 'x &amp; y' === Store::normalize( [ 'text' => "x &amp; y\r\n" ] )['text'], 'normalize: Text unverändert (kein <br>-Umbau, keine Entities), nur Zeilenenden' );
ok( $n['timestamp'] > 0 && '' === $n['url'], 'normalize: Zeitstempel aus date, javascript-URL verworfen' );

/* ================================================================ 6. Render */

section( '6. Ausgabe (Shortcodes und Blöcke)' );
reset_state();
Profile::update( [ 'rating' => 4.8, 'count' => 83, 'name' => 'Testfirma GmbH', 'reviews_uri' => T_REVIEWS, 'write_uri' => T_WRITE, 'photo_url' => 'http://localhost:8897/wp-content/uploads/local-reviews/profile.jpg?v=1' ] );
seed(
	[
		entry( 'r1', [ 'name' => 'Rita Render', 'rating' => 4, 'text' => 'Alles gut <script>alert("x")</script> erledigt.', 'timestamp' => $d( '2024-05-01' ) ] ),
		entry( 'r2', [ 'name' => '"><img src=x onerror=alert(1)>Eve', 'rating' => 5, 'text' => "Erste Zeile\nZweite Zeile", 'timestamp' => $d( '2024-04-01' ) ] ),
		entry( 'r3', [ 'source' => '11880', 'name' => 'Elfi', 'rating' => 5, 'text' => 'Über 11880', 'url' => 'https://www.11880.com/bewertung/9', 'timestamp' => $d( '2024-03-01' ) ] ),
		entry( 'r4', [ 'source' => 'other', 'source_label' => 'Werkenntdenbesten', 'name' => 'Wolf', 'rating' => 3, 'text' => '', 'timestamp' => $d( '2024-02-01' ) ] ),
		entry( 'r5', [ 'name' => 'Hidden Hannes', 'rating' => 5, 'text' => 'Darf nicht erscheinen', 'hidden' => true, 'timestamp' => $d( '2024-06-01' ) ] ),
		entry( 'r6', [ 'name' => 'Lang Lena', 'rating' => 5, 'text' => 'Dies ist ein sehr langer Bewertungstext, der für den Test der Kürzung auf fünfzig Zeichen gedacht ist.', 'timestamp' => $d( '2024-01-01' ) ] ),
	]
);
$html = do_shortcode( '[google_reviews_lokal]' );
ok( str_starts_with( $html, '<div class="lr-reviews"' ), 'Shortcode liefert Wrapper .lr-reviews', mb_substr( $html, 0, 80 ) );
ok( 5 === substr_count( $html, 'class="lr-card"' ), 'fünf sichtbare Karten', (string) substr_count( $html, 'class="lr-card"' ) );
ok( ! str_contains( $html, 'Hidden Hannes' ) && ! str_contains( $html, 'Darf nicht erscheinen' ), 'ausgeblendeter Eintrag erscheint nicht' );
ok( ! str_contains( $html, '<script' ) && str_contains( $html, '&lt;script&gt;' ), 'Text mit <script> escaped' );
ok( ! preg_match( '/<img[^>]*onerror/i', $html ) && str_contains( $html, '<span class="lr-card__name">&quot;&gt;Eve</span>' ), 'Name mit "><img onerror> unschädlich' );
ok( str_contains( $html, "Erste Zeile<br />\nZweite Zeile" ), 'Zeilenumbruch im Text als <br />' );
ok( str_contains( $html, '★★★★☆' ) && str_contains( $html, '★★★★★' ) && str_contains( $html, '★★★☆☆' ), 'Sternzeichen je Bewertung' );
ok( str_contains( $html, 'aria-label="' . esc_attr( sprintf( '%s von 5 Sternen', number_format_i18n( 4, 1 ) ) ) . '"' ), 'aria-label „X von 5 Sternen“' );
ok( str_contains( $html, '<span class="lr-card__date">01.05.2024</span>' ), 'Datum im Format d.m.Y' );
Settings::update( [ 'date_format' => 'Y/m/d' ] );
ok( str_contains( do_shortcode( '[google_reviews_lokal]' ), '2024/05/01' ), 'Datumsformat aus Einstellungen' );
Settings::update( [ 'date_format' => 'd.m.Y' ] );
ok( str_contains( $html, 'class="lr-header"' ) && str_contains( $html, 'Testfirma GmbH' ) && str_contains( $html, '4.8 | 83 Google Bewertungen' ), 'Kopfbereich mit Name, Note und Anzahl' );
ok( (bool) preg_match( '#<img src="[^"]*local-reviews/profile\.jpg[^"]*" class="lr-header__photo"[^>]*width="75" height="75"#', $html ), 'Foto 75×75 im Kopfbereich' );
ok( (bool) preg_match( '#<a class="lr-source lr-source--11880" href="https://www\.11880\.com/bewertung/9" target="_blank" rel="noopener nofollow">via 11880</a>#', $html ), '„via 11880“ als Link mit noopener nofollow' );
ok( str_contains( $html, '<span class="lr-source lr-source--other">via Werkenntdenbesten</span>' ), 'Sonstige mit eigenem Namen, ohne URL als <span>' );
ok( ! str_contains( $html, 'via Google' ), 'show_source foreign (Standard): kein Label bei Google' );

$h = do_shortcode( '[google_reviews_lokal limit="2"]' );
ok( 2 === substr_count( $h, 'class="lr-card"' ), 'limit="2"' );
$h = do_shortcode( '[google_reviews_lokal header="0"]' );
ok( ! str_contains( $h, 'lr-header' ), 'header="0" blendet Kopfbereich aus' );
$h = do_shortcode( '[google_reviews_lokal photo="0"]' );
ok( str_contains( $h, 'lr-header' ) && ! str_contains( $h, 'lr-header__photo' ), 'photo="0" blendet nur das Foto aus' );
$h = do_shortcode( '[google_reviews_lokal show_source="all"]' );
ok( 3 === substr_count( $h, 'lr-source--google' ), 'show_source="all": „via Google“ auch bei Google-Einträgen', (string) substr_count( $h, 'lr-source--google' ) );
$h = do_shortcode( '[google_reviews_lokal show_source="off"]' );
ok( ! str_contains( $h, 'lr-source' ), 'show_source="off": keine Labels' );
$h = do_shortcode( '[google_reviews_lokal hide_empty="1"]' );
ok( 4 === substr_count( $h, 'class="lr-card"' ) && ! str_contains( $h, 'Wolf' ), 'hide_empty="1" blendet leere Texte aus' );
$h = do_shortcode( '[google_reviews_lokal excerpt="50"]' );
ok( str_contains( $h, 'Dies ist ein sehr langer Bewertungstext, der für…' ) && ! str_contains( $h, 'fünfzig Zeichen' ), 'excerpt="50" kürzt an Wortgrenze mit …', preg_match( '#Dies ist[^<]*#u', $h, $mm ) ? $mm[0] : '' );
$h = do_shortcode( '[google_reviews_lokal wrapper_class="a b"]' );
ok( str_starts_with( $h, '<div class="lr-reviews a b"' ), 'wrapper_class="a b"' );
$h = do_shortcode( '[google_reviews_lokal wrapper_class="x&quot; onclick=&quot;alert(1)"]' );
ok( ! str_contains( $h, 'onclick=' ) && ! str_contains( $h, '" onclick' ), 'wrapper_class ohne Attribut-Injection' );
$h = do_shortcode( '[google_reviews_lokal source="11880"]' );
ok( 1 === substr_count( $h, 'class="lr-card"' ) && str_contains( $h, 'Elfi' ), 'source="11880" filtert' );
$h = do_shortcode( '[google_reviews_lokal source="google,other"]' );
ok( 4 === substr_count( $h, 'class="lr-card"' ), 'source="google,other"' );
$h = do_shortcode( '[google_reviews_lokal min_rating="5"]' );
ok( 3 === substr_count( $h, 'class="lr-card"' ), 'min_rating="5"' );
$h = do_shortcode( '[google_reviews_lokal columns="3" columns_tablet="2" columns_mobile="1" card_width="0"]' );
ok( str_contains( $h, '--lr-columns:3;--lr-columns-tablet:2;--lr-columns-mobile:1;--lr-card-width:none;' ), 'Spalten und Kartenbreite als CSS-Variablen' );
ok( wp_style_is( LocalReviews\Render::STYLE, 'enqueued' ), 'Frontend-CSS wird bei Ausgabe geladen' );

// Leeres Archiv.
Store::save_all( [] );
$h = do_shortcode( '[google_reviews_lokal]' );
ok( str_contains( $h, 'lr-reviews__empty' ) && str_contains( $h, 'Aktuell sind keine Bewertungen verfügbar.' ), 'leeres Archiv → Hinweistext' );
$h = do_shortcode( '[google_reviews_lokal source="houzz"]' );
ok( str_contains( $h, 'Aktuell sind keine Bewertungen verfügbar.' ), 'Filter ohne Treffer → Hinweistext' );
seed( [ entry( 'r1', [ 'name' => 'Rita Render', 'rating' => 4, 'text' => 'Text', 'timestamp' => $d( '2024-05-01' ) ] ) ] );

// Badge.
$h = do_shortcode( '[google_badge_einzeln]' );
ok( str_starts_with( $h, '<p class="lr-badge">' ) && str_contains( $h, 'lr-badge__brand' ) && str_contains( $h, 'lr-badge__arrow' ), 'Badge Standard: <p>, Schriftzug, Pfeil' );
ok( str_contains( $h, '★★★★★' ) && str_contains( $h, '83 Bewertungen' ), 'Badge: round(4.8) = 5 Sterne, Anzahl' );
ok( str_contains( $h, 'aria-label="' . number_format_i18n( 4.8, 1 ) . ' von 5 Sternen"' ), 'Badge: aria-label' );
ok( ! str_contains( $h, 'lr-badge__rating' ), 'Badge: Note standardmäßig aus' );
$h = do_shortcode( '[google_badge_einzeln rating="1" brand="0" arrow="0" label="Rezensionen" wrapper_class="a b"]' );
ok( str_contains( $h, '<span class="lr-badge__rating">' . number_format_i18n( 4.8, 1 ) . '</span>' ) && ! str_contains( $h, 'lr-badge__brand' ) && ! str_contains( $h, 'lr-badge__arrow' ), 'Badge: rating an, brand/arrow aus' );
ok( str_contains( $h, '83 Rezensionen' ) && str_starts_with( $h, '<p class="lr-badge a b">' ), 'Badge: eigenes Label, wrapper_class' );
$h = do_shortcode( '[google_badge_einzeln link="custom" url="#bewertungen"]' );
ok( str_starts_with( $h, '<a class="lr-badge" href="#bewertungen">' ), 'Badge link="custom" url="#bewertungen": Anker ohne target', mb_substr( $h, 0, 80 ) );
$h = do_shortcode( '[google_badge_einzeln link="google"]' );
ok( str_contains( $h, 'href="' . esc_url( T_REVIEWS ) . '"' ) && str_contains( $h, 'target="_blank" rel="noopener nofollow"' ), 'Badge link="google": reviewsUri, extern' );
$h = do_shortcode( '[google_badge_einzeln link="custom" url="javascript:alert(1)"]' );
ok( ! str_contains( $h, 'javascript:' ), 'Badge: javascript-URL wird entschärft' );

// Button.
$h = do_shortcode( '[google_reviews_button]' );
ok( str_contains( $h, 'class="lr-button-wrap lr-button-wrap--center"' ) && str_contains( $h, 'class="lr-button lr-button--google"' ), 'Button Standard: Google-Stil, zentriert' );
ok( str_contains( $h, 'href="' . esc_url( T_REVIEWS ) . '"' ) && str_contains( $h, 'target="_blank" rel="noopener nofollow"' ), 'Button: reviewsUri, target _blank, noopener nofollow' );
ok( str_contains( $h, '<svg class="lr-button__icon"' ) && str_contains( $h, 'width="18" height="18"' ), 'Button: G-Logo 18×18' );
ok( str_contains( $h, 'Alle Bewertungen auf Google lesen' ), 'Button: Standardtext' );
$h = do_shortcode( '[google_reviews_button style="theme" class="x" target="write" text="Jetzt bewerten" icon="0" align="left" wrapper_class="w1 w2"]' );
ok( str_contains( $h, 'class="lr-button wp-element-button x"' ), 'Button style="theme" class="x": wp-element-button + eigene Klasse', $h );
ok( str_contains( $h, 'href="' . esc_url( T_WRITE ) . '"' ), 'Button target="write": writeAReviewUri' );
ok( ! str_contains( $h, '<svg' ) && str_contains( $h, 'Jetzt bewerten' ) && str_contains( $h, 'lr-button-wrap--left w1 w2' ), 'Button: icon aus, Text, align, wrapper_class' );
$h = do_shortcode( '[google_reviews_button style="none"]' );
ok( str_contains( $h, 'class="lr-button"' ), 'Button style="none": nur lr-button' );
Settings::update( [ 'button_mode' => 'custom', 'button_url' => 'https://example.com/eigene' ] );
ok( str_contains( do_shortcode( '[google_reviews_button]' ), 'href="https://example.com/eigene"' ), 'Button: eigene URL aus Einstellungen' );
Settings::update( [ 'button_mode' => 'auto', 'button_url' => '' ] );
Profile::update( [ 'reviews_uri' => '', 'write_uri' => '' ] );
ok( str_contains( do_shortcode( '[google_reviews_button]' ), 'place_id:' . T_PLACE ), 'Button: Fallback-Link mit Place ID' );
ok( str_contains( do_shortcode( '[google_reviews_button target="write"]' ), 'writereview?placeid=' . T_PLACE ), 'Button write: Fallback-Link mit Place ID' );
Settings::update( [ 'place_id' => '' ] );
ok( '' === do_shortcode( '[google_reviews_button]' ), 'Button ohne Place ID und URIs: keine Ausgabe im Frontend' );
Settings::update( [ 'place_id' => T_PLACE ] );
Profile::update( [ 'reviews_uri' => T_REVIEWS, 'write_uri' => T_WRITE ] );

// Blöcke.
$block = static fn( string $markup ) => render_block( parse_blocks( $markup )[0] );
ok( WP_Block_Type_Registry::get_instance()->is_registered( 'local-reviews/reviews' ) && WP_Block_Type_Registry::get_instance()->is_registered( 'local-reviews/badge' ) && WP_Block_Type_Registry::get_instance()->is_registered( 'local-reviews/button' ), 'drei Blöcke registriert' );
seed(
	[
		entry( 'b1', [ 'name' => 'Block Eins', 'timestamp' => $d( '2024-05-01' ) ] ),
		entry( 'b2', [ 'name' => 'Block Zwei', 'timestamp' => $d( '2024-04-01' ) ] ),
		entry( 'b3', [ 'source' => '11880', 'name' => 'Block Drei', 'timestamp' => $d( '2024-03-01' ) ] ),
	]
);
$h = $block( '<!-- wp:local-reviews/reviews {"limit":2,"header":false,"wrapper_class":"bw"} /-->' );
ok( str_contains( $h, 'wp-block-local-reviews-reviews' ) && str_contains( $h, 'class="lr-reviews bw"' ), 'Block reviews: Wrapper und wrapper_class', mb_substr( $h, 0, 120 ) );
ok( 2 === substr_count( $h, 'class="lr-card"' ) && ! str_contains( $h, 'lr-header' ), 'Block reviews: limit und header aus' );
$h = $block( '<!-- wp:local-reviews/reviews {"source":"11880","show_source":"all"} /-->' );
ok( 1 === substr_count( $h, 'class="lr-card"' ) && str_contains( $h, 'via 11880' ), 'Block reviews: source und show_source' );
$h = $block( '<!-- wp:local-reviews/reviews /-->' );
ok( 3 === substr_count( $h, 'class="lr-card"' ) && str_contains( $h, 'lr-header' ), 'Block reviews: Standardwerte' );
$h = $block( '<!-- wp:local-reviews/badge {"rating":true,"link":"custom","url":"#bewertungen"} /-->' );
ok( str_contains( $h, 'wp-block-local-reviews-badge' ) && str_contains( $h, 'href="#bewertungen"' ) && str_contains( $h, 'lr-badge__rating' ), 'Block badge: Wrapper, Link, Note' );
$h = $block( '<!-- wp:local-reviews/button {"button_style":"theme","button_class":"btn-x","target":"write"} /-->' );
ok( str_contains( $h, 'wp-block-local-reviews-button' ) && str_contains( $h, 'class="lr-button wp-element-button btn-x"' ), 'Block button: button_style/button_class', mb_substr( $h, 0, 300 ) );
ok( str_contains( $h, 'href="' . esc_url( T_WRITE ) . '"' ), 'Block button: target write' );
$h = $block( '<!-- wp:local-reviews/button /-->' );
ok( str_contains( $h, 'class="lr-button lr-button--google"' ), 'Block button: Standard Google-Stil' );
$h = $block( '<!-- wp:local-reviews/button {"style":{"spacing":{"margin":{"top":"10px"}}}} /-->' );
ok( str_contains( $h, 'lr-button--google' ) && str_contains( $h, 'margin-top:10px' ), 'Block button: Core-Attribut style (Abstände) kollidiert nicht', mb_substr( $h, 0, 200 ) );
Store::save_all( [] );
$h = $block( '<!-- wp:local-reviews/reviews /-->' );
ok( str_contains( $h, 'Aktuell sind keine Bewertungen verfügbar.' ), 'Block reviews: leeres Archiv → Hinweis' );

/* =============================================================== 7. Sources */

// Ergänzungen nach dem Review: leerer Absatz, Anker, Badge/Kopf ohne Daten.
Store::put( entry( 'leer1', [ 'name' => 'Leo Leertext', 'rating' => 4, 'text' => '', 'timestamp' => $d( '2024-07-01' ) ] ) );
$html = do_shortcode( '[google_reviews_lokal header="0"]' );
ok( 1 === preg_match( '#Leo Leertext</span>.*?<p class="lr-card__text"></p>#s', $html ), 'Karte ohne Text hat leeren Absatz wie live', mb_substr( (string) strstr( $html, 'Leo' ), 0, 300 ) );
$block = render_block( parse_blocks( '<!-- wp:local-reviews/reviews {"anchor":"bewertungen"} /-->' )[0] );
ok( str_contains( $block, 'id="bewertungen"' ), 'Block-Anker landet als id im HTML', mb_substr( $block, 0, 120 ) );
Profile::update( [ 'count' => 0, 'rating' => 0, 'name' => '' ] );
ok( '' === do_shortcode( '[google_badge_einzeln]' ), 'Badge ohne Google-Daten: keine Ausgabe' );
ok( ! str_contains( do_shortcode( '[google_reviews_lokal]' ), 'lr-header' ), 'Kopfbereich ohne Name und Daten: keine Ausgabe' );
$before = Cache::snapshot();
Profile::update( [ 'name' => 'Neuer Name' ] );
ok( $before !== Cache::snapshot(), 'Profilname zählt zum Cache-Fingerabdruck' );

// 1.2.0: Anzahl im Badge lokal oder laut Google, „via Google“ mit Link.
reset_state();
Profile::update( [ 'rating' => 5.0, 'count' => 83, 'name' => 'Testfirma GmbH' ] );
seed(
	[
		entry( 'b1', [ 'name' => 'Anna', 'rating' => 5, 'text' => 'Toll', 'url' => 'https://www.google.com/maps/reviews/data=abc', 'timestamp' => $d( '2024-05-01' ) ] ),
		entry( 'b2', [ 'name' => 'Bert', 'rating' => 4, 'text' => 'Gut', 'timestamp' => $d( '2024-04-01' ) ] ),
		entry( 'b3', [ 'source' => '11880', 'name' => 'Cleo', 'rating' => 5, 'text' => 'Prima', 'timestamp' => $d( '2024-03-01' ) ] ),
		entry( 'b4', [ 'name' => 'Dora', 'rating' => 5, 'text' => 'Versteckt', 'hidden' => true, 'timestamp' => $d( '2024-02-01' ) ] ),
	]
);
ok( str_contains( do_shortcode( '[google_badge_einzeln]' ), '83 Bewertungen' ), 'Badge Standard: Anzahl laut Google' );
Settings::update( [ 'badge_count' => 'local' ] );
ok( str_contains( do_shortcode( '[google_badge_einzeln]' ), '3 Bewertungen' ), 'Badge Einstellung local: sichtbare Bewertungen aller Plattformen, ohne ausgeblendete' );
ok( str_contains( do_shortcode( '[google_badge_einzeln count="google"]' ), '83 Bewertungen' ), 'Shortcode count="google" überschreibt Einstellung' );
Settings::update( [ 'badge_count' => 'google' ] );
ok( str_contains( render_block( parse_blocks( '<!-- wp:local-reviews/badge {"count":"local"} /-->' )[0] ), '3 Bewertungen' ), 'Block-Attribut count=local' );
Settings::update( [ 'badge_count' => 'quatsch' ] );
ok( 'google' === Settings::get( 'badge_count' ), 'ungültiger Wert für badge_count wird google' );
$html = do_shortcode( '[google_reviews_lokal show_source="all" header="0"]' );
ok( str_contains( $html, '<a class="lr-source lr-source--google" href="https://www.google.com/maps/reviews/data=abc" target="_blank" rel="noopener nofollow">via Google</a>' ), 'show_source=all: „via Google“ als Link zur Original-Bewertung' );
ok( str_contains( $html, '<span class="lr-source lr-source--google">via Google</span>' ), 'show_source=all: ohne Link als Text' );
ok( ! str_contains( do_shortcode( '[google_reviews_lokal header="0"]' ), 'via Google' ), 'Standard foreign: kein „via Google“' );

section( '7. Plattformen' );
remove_all_filters( 'local_reviews_sources' );
$s = Sources::all();
ok( 'google' === array_key_first( $s ) && 'other' === array_key_last( $s ), 'google zuerst, other zuletzt' );
ok( isset( $s['11880'] ) && Sources::exists( '11880' ) && '11880' === Sources::label( '11880' ), '11880 vorhanden (numerischer Schlüssel)' );
ok( 'Sonstige' === Sources::label( 'other' ) && 'Werkenntdenbesten' === Sources::label( 'other', 'Werkenntdenbesten' ), 'label() bei other' );
ok( [ '11880', '' ] === Sources::resolve( '11880' ), 'resolve("11880")', dump( Sources::resolve( '11880' ) ) );
ok( [ 'google', '' ] === Sources::resolve( 'Google' ) && [ 'google', '' ] === Sources::resolve( ' google ' ), 'resolve("Google")' );
ok( [ 'other', 'Werkenntdenbesten' ] === Sources::resolve( 'Werkenntdenbesten' ), 'resolve("Werkenntdenbesten")' );
ok( [ 'provenexpert', '' ] === Sources::resolve( 'ProvenExpert' ), 'resolve über Anzeigenamen' );
ok( [ 'other', '' ] === Sources::resolve( '' ) && [ 'other', '' ] === Sources::resolve( 'Sonstige' ), 'resolve leer / „Sonstige“' );
add_filter(
	'local_reviews_sources',
	static function ( array $list ): array {
		unset( $list['google'], $list['houzz'] );
		$list['yelp']  = 'Yelp';
		$list['other'] = 'Überschrieben';
		return $list;
	}
);
$s = Sources::all();
ok( isset( $s['yelp'] ) && ! isset( $s['houzz'] ), 'Filter ergänzt Yelp, entfernt Houzz' );
ok( 'google' === array_key_first( $s ) && 'Google' === $s['google'], 'google bleibt Pflicht' );
ok( 'other' === array_key_last( $s ) && 'Sonstige' === $s['other'], 'other bleibt am Ende, nicht überschreibbar' );
ok( [ 'yelp', '' ] === Sources::resolve( 'Yelp' ) && [ 'other', 'Houzz' ] === Sources::resolve( 'Houzz' ), 'resolve folgt dem Filter' );
remove_all_filters( 'local_reviews_sources' );

/* ================================================================== 8. Text */

section( '8. Texthilfen' );
ok( md5( 'Max' . 'Hallo' ) === Text::legacy_id( 'Max', 'Hallo' ), 'legacy_id = md5(Name . Text)' );
$long = str_repeat( 'ä', 40 ); // 80 Byte.
ok( md5( 'Max' . substr( $long, 0, 50 ) ) === Text::legacy_id( 'Max', $long ), 'legacy_id nimmt 50 Bytes (substr, nicht mb_substr)' );
ok( '"super" arbeit toll' === Text::normalize( "„Super“   Arbeit\n<br>Toll" ), 'normalize: Anführungszeichen, Whitespace, Kleinschreibung, <br>', Text::normalize( "„Super“   Arbeit\n<br>Toll" ) );
ok( "it's" === Text::normalize( 'It’s' ) && 'äöü' === Text::normalize( 'ÄÖÜ' ), 'normalize: Apostroph, Umlaute klein' );
ok( 'a & b' === Text::normalize( 'a &amp; b' ), 'normalize: HTML-Entities dekodiert' );
$base = strtotime( '2024-03-10 12:00:00 UTC' );
$ta   = [ 'name' => 'A', 'text' => '„Die Firma hat alles super gemacht“ und mehr', 'timestamp' => $base ];
ok( Text::is_possible_duplicate( $ta, [ 'name' => 'B', 'text' => '"Die Firma hat alles super gemacht" anders', 'timestamp' => $base ] ), 'Dublette: gleicher Tag, gleiche 30 Zeichen' );
ok( Text::is_possible_duplicate( $ta, [ 'name' => 'B', 'text' => $ta['text'], 'timestamp' => $base + DAY_IN_SECONDS ] ), 'Dublette: +1 Tag' );
ok( Text::is_possible_duplicate( $ta, [ 'name' => 'B', 'text' => $ta['text'], 'timestamp' => $base - DAY_IN_SECONDS ] ), 'Dublette: −1 Tag' );
ok( ! Text::is_possible_duplicate( $ta, [ 'name' => 'B', 'text' => $ta['text'], 'timestamp' => $base + 2 * DAY_IN_SECONDS ] ), 'keine Dublette: +2 Tage' );
ok( Text::is_possible_duplicate( [ 'text' => 'x', 'timestamp' => strtotime( '2024-03-10 00:30 UTC' ) ], [ 'text' => 'x', 'timestamp' => strtotime( '2024-03-11 23:30 UTC' ) ] ), 'Kalendertage zählen, nicht 24-h-Abstand' );
ok( ! Text::is_possible_duplicate( $ta, [ 'name' => 'A', 'text' => 'Die Firma hat alles gut gemacht und mehr', 'timestamp' => $base ] ), 'keine Dublette: Unterschied in den ersten 30 Zeichen' );
ok( Text::is_possible_duplicate( [ 'text' => str_repeat( 'x', 30 ) . 'AAA', 'timestamp' => $base ], [ 'text' => str_repeat( 'x', 30 ) . 'BBB', 'timestamp' => $base ] ), 'Dublette: Unterschied erst ab Zeichen 31' );
ok( Text::is_possible_duplicate( [ 'name' => 'Max', 'text' => '', 'timestamp' => $base ], [ 'name' => 'max', 'text' => '', 'timestamp' => $base ] ), 'leere Texte, gleicher Name → Dublette' );
ok( ! Text::is_possible_duplicate( [ 'name' => 'Max', 'text' => '', 'timestamp' => $base ], [ 'name' => 'Moritz', 'text' => '', 'timestamp' => $base ] ), 'leere Texte, anderer Name → keine Dublette' );
ok( ! Text::is_possible_duplicate( [ 'name' => 'Max', 'text' => '', 'timestamp' => $base ], [ 'name' => 'Max', 'text' => 'Text', 'timestamp' => $base ] ), 'ein Text leer → keine Dublette' );
ok( ! Text::is_possible_duplicate( [ 'text' => 'x', 'timestamp' => 0 ], [ 'text' => 'x', 'timestamp' => 0 ] ), 'ohne Zeitstempel → keine Dublette' );
ok( 'Kurz' === Text::excerpt( 'Kurz', 50 ) && 'Lang genug' === Text::excerpt( 'Lang genug', 0 ), 'excerpt: kurz/0 unverändert' );
ok( 'Eins zwei drei…' === Text::excerpt( 'Eins zwei drei vier fünf', 17 ), 'excerpt: Wortgrenze + …', Text::excerpt( 'Eins zwei drei vier fünf', 17 ) );
ok( 'Abcdefghij…' === Text::excerpt( 'Abcdefghijklmnop', 10 ), 'excerpt: ohne Leerzeichen hart gekürzt' );
ok( 'Ähm, öh,…' !== Text::excerpt( 'Ähm, öh, ja genau so', 8 ) && ! str_contains( Text::excerpt( 'Ähm, öh, ja genau so', 8 ), ',…' ), 'excerpt: kein Komma vor …', Text::excerpt( 'Ähm, öh, ja genau so', 8 ) );
ok( 9 === mb_strlen( Text::excerpt( 'Ääääääää ü', 8 ), 'UTF-8' ), 'excerpt zählt Zeichen (mb)' );
$tz = wp_timezone();
ok( ( new DateTimeImmutable( '2024-05-01 12:00', $tz ) )->getTimestamp() === Text::parse_date( '01.05.2024' ), 'parse_date TT.MM.JJJJ → 12:00 Ortszeit' );
ok( ( new DateTimeImmutable( '2024-05-01 12:00', $tz ) )->getTimestamp() === Text::parse_date( '1.5.2024' ), 'parse_date T.M.JJJJ' );
ok( ( new DateTimeImmutable( '2024-05-01 12:00', $tz ) )->getTimestamp() === Text::parse_date( '2024-05-01' ), 'parse_date JJJJ-MM-TT' );
ok( 1700000000 === Text::parse_date( '1700000000' ), 'parse_date Zeitstempel' );
ok( 1714557600 === Text::parse_date( '2024-05-01T10:00:00Z' ), 'parse_date ISO 8601 mit Z' );
ok( 1714557600 === Text::parse_date( '2024-05-01T12:00:00+02:00' ), 'parse_date ISO 8601 mit Offset' );
ok( 1700000000 === Text::parse_date( '11/14/2023 22:13:20' ), 'parse_date Outscraper MM/TT/JJJJ (UTC)' );
ok( 0 === Text::parse_date( '' ) && 0 === Text::parse_date( 'irgendwann' ), 'parse_date leer/unlesbar → 0' );
ok( 0 === Text::parse_date( '31.02.2024' ), 'parse_date ungültiges Datum 31.02.2024 → 0', (string) Text::parse_date( '31.02.2024' ) . ' = ' . gmdate( 'Y-m-d', Text::parse_date( '31.02.2024' ) ) );
ok( "a\nb\nc" === Text::clean( "a<br>b<BR />c" ) && '<3 Liebe' === Text::clean( '&lt;3 Liebe' ), 'clean: <br> → Umbruch, Entities dekodiert' );

/* ============================================================= 9. Uninstall */

section( '9. Deinstallation' );
reset_state();
Fetcher::run(); // Archiv, Profil, Status, Foto.
LocalReviews\Cron::schedule();
wp_schedule_single_event( time() + 3600, LocalReviews\CATCHUP_HOOK );
set_transient( 'local_reviews_import_1', [ 'rows' => [] ], HOUR_IN_SECONDS );
set_transient( 'local_reviews_import_1_error', 'Fehler', HOUR_IN_SECONDS );
set_transient( 'local_reviews_ohne_ablauf', 'x' );
update_option( 'mein_google_reviews_archiv', [ 'k' => [ 'name' => 'Alt', 'text' => 'Alt', 'rating' => 5, 'timestamp' => 1 ] ] );
update_option( 'google_profil_global_rating', '4.9' );
update_option( 'google_profil_global_count', '10' );
update_option( 'google_profil_photo_url', 'http://x/google-profil/p.jpg' );
update_option( 'google_profil_last_error', 'alt' );
set_transient( Migration::LEGACY_TRANSIENT, 'https://www.google.com/maps/data=1', DAY_IN_SECONDS );
wp_mkdir_p( uploads_dir( 'google-profil' ) );
file_put_contents( uploads_dir( 'google-profil' ) . '/profil.jpg', tiny_jpeg() );
$lr_opts_before = array_filter( db_option_names(), fn( $n ) => str_starts_with( $n, 'local_reviews_' ) );
ok( count( $lr_opts_before ) >= 5 && is_dir( uploads_dir( 'local-reviews' ) ) && wp_next_scheduled( LocalReviews\CRON_HOOK ), 'Vorbedingung: Optionen, Foto-Ordner und Cron vorhanden', dump( array_values( $lr_opts_before ) ) );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'local-reviews/local-reviews.php' );
}
include WP_PLUGIN_DIR . '/local-reviews/uninstall.php';
wp_cache_flush();

$names = db_option_names();
$left  = array_values( array_filter( $names, fn( $n ) => str_starts_with( $n, 'local_reviews_' ) ) );
ok( [] === $left, 'alle local_reviews_*-Optionen gelöscht', dump( $left ) );
$left_t = array_values( array_filter( $names, fn( $n ) => str_starts_with( $n, '_transient_local_reviews_' ) || str_starts_with( $n, '_transient_timeout_local_reviews_' ) ) );
ok( [] === $left_t, 'alle local_reviews-Transients gelöscht', dump( $left_t ) );
ok( ! is_dir( uploads_dir( 'local-reviews' ) ), 'uploads/local-reviews gelöscht' );
ok( false === wp_next_scheduled( LocalReviews\CRON_HOOK ) && false === wp_next_scheduled( LocalReviews\CATCHUP_HOOK ), 'Cron-Hooks abgemeldet' );
ok( in_array( 'mein_google_reviews_archiv', $names, true ) && in_array( 'google_profil_global_rating', $names, true ) && in_array( 'google_profil_global_count', $names, true ) && in_array( 'google_profil_photo_url', $names, true ) && in_array( 'google_profil_last_error', $names, true ), 'Legacy-Optionen bleiben' );
ok( in_array( '_transient_' . Migration::LEGACY_TRANSIENT, $names, true ), 'Legacy-Transient bleibt' );
ok( file_exists( uploads_dir( 'google-profil' ) . '/profil.jpg' ), 'uploads/google-profil bleibt' );

// Aufräumen: Legacy-Testdaten entfernen, damit die Seite keine Altdaten-Hinweise zeigt.
foreach ( Migration::LEGACY_OPTIONS as $o ) {
	delete_option( $o );
}
delete_transient( Migration::LEGACY_TRANSIENT );
rrmdir( uploads_dir( 'google-profil' ) );

/* ------------------------------------------------------------- Ergebnis */

if ( $GLOBALS['http_other'] ) {
	echo "\nHinweis: blockierte Nicht-Google-Aufrufe: " . implode( ', ', array_unique( $GLOBALS['http_other'] ) ) . "\n";
}
echo "\n" . str_repeat( '─', 50 ) . "\n";
printf( "%d bestanden, %d fehlgeschlagen\n", $GLOBALS['t_pass'], $GLOBALS['t_fail'] );
exit( $GLOBALS['t_fail'] > 0 ? 1 : 0 );
