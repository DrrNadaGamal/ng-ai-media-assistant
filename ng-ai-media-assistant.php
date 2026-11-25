<?php
/**
 * Plugin Name: NG AI Media Assistant
 * Description: Suggests alt text and descriptions for Media Library images with Google Gemini, in Arabic, English, French or German. Suggestions go to a review queue by default. Uses your own Gemini API key. Part of the NG WordPress Suite.
 * Version: 0.1.0
 * Author: Nada Gamal
 * License: GPL-2.0-or-later
 * Text Domain: ng-ama
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class NGAMA {
	const OPT = 'ngama_settings';
	const META = '_ngama';
	static function defaults() { return array( 'model' => 'gemini-flash-latest', 'lang' => 'auto', 'mode' => 'review', 'on_upload' => 1, 'key_enc' => '', 'context' => '' ); }
	static function s() { return wp_parse_args( get_option( self::OPT, array() ), self::defaults() ); }
	static function enc( $p ) { $k = hash( 'sha256', wp_salt( 'auth' ), true ); $iv = random_bytes( 12 ); $tag = ''; $c = openssl_encrypt( $p, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag ); return base64_encode( $iv . $tag . $c ); }
	static function dec( $b ) { if ( ! $b ) { return ''; } $r = base64_decode( $b ); $p = openssl_decrypt( substr( $r, 28 ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, substr( $r, 0, 12 ), substr( $r, 12, 16 ) ); return false === $p ? '' : $p; }
	static function key() { return defined( 'NGAMA_KEY' ) ? NGAMA_KEY : self::dec( self::s()['key_enc'] ); }
	static function endpoint( $model ) { return apply_filters( 'ngama_endpoint', 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent', $model ); }

	static function init() {
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_upload' ), 40, 2 );
		add_action( 'admin_menu', function () { add_media_page( 'NG AI Media Assistant', __( 'AI Media Assistant', 'ng-ama' ), 'upload_files', 'ngama', array( __CLASS__, 'page' ) ); } );
		foreach ( array( 'save', 'scan', 'approve', 'reject' ) as $a ) { add_action( 'admin_post_ngama_' . $a, array( __CLASS__, 'act_' . $a ) ); }
		add_filter( 'gettext_ng-ama', function ( $t ) { $m = self::T(); $l = substr( apply_filters( 'ngama_locale', determine_locale() ), 0, 2 ); return isset( $m[ $l ][ $t ] ) ? $m[ $l ][ $t ] : $t; } );
	}
	static function T() { return array(
		'ar' => array( 'AI Media Assistant' => 'مساعد الوسائط الذكي', 'Save settings' => 'حفظ الإعدادات', 'Generate for images without alt text' => 'توليد للصور بدون نص بديل', 'Approve' => 'اعتماد', 'Reject' => 'رفض', 'Review queue' => 'قائمة المراجعة' ),
		'fr' => array( 'AI Media Assistant' => 'Assistant média IA', 'Save settings' => 'Enregistrer', 'Generate for images without alt text' => 'Générer pour les images sans texte alternatif', 'Approve' => 'Approuver', 'Reject' => 'Rejeter', 'Review queue' => 'File de relecture' ),
		'de' => array( 'AI Media Assistant' => 'KI-Medienassistent', 'Save settings' => 'Einstellungen speichern', 'Generate for images without alt text' => 'Für Bilder ohne Alt-Text erzeugen', 'Approve' => 'Freigeben', 'Reject' => 'Ablehnen', 'Review queue' => 'Prüfliste' ),
	); }
	static function lang_name() {
		$l = self::s()['lang']; if ( 'auto' === $l ) { $l = substr( get_locale(), 0, 2 ); }
		$n = array( 'ar' => 'Arabic', 'en' => 'English', 'fr' => 'French', 'de' => 'German' ); return isset( $n[ $l ] ) ? $n[ $l ] : 'English';
	}

	/* ---- generation ---- */
	static function image_path( $id ) {
		$f = get_attached_file( $id ); $m = wp_get_attachment_metadata( $id );
		if ( ! empty( $m['sizes']['large']['file'] ) ) { $c = dirname( $f ) . '/' . $m['sizes']['large']['file']; if ( file_exists( $c ) ) { return $c; } }
		return $f;
	}
	static function generate( $id ) {
		if ( ! wp_attachment_is_image( $id ) ) { return new WP_Error( 'type', 'Not an image' ); }
		$key = self::key(); if ( ! $key ) { return new WP_Error( 'key', 'No Gemini API key set' ); }
		$path = self::image_path( $id ); if ( ! $path || ! file_exists( $path ) ) { return new WP_Error( 'file', 'Image file missing' ); }
		if ( filesize( $path ) > 4 * 1024 * 1024 ) { return new WP_Error( 'size', 'Image too large to send (4 MB limit)' ); }
		$mime = wp_check_filetype( $path )['type']; $s = self::s();
		$prompt = 'Write accessible alt text for this image in ' . self::lang_name() . ': one factual sentence under 125 characters, describing what is visible, no "image of" or "picture of". Also write a longer description of up to 2 sentences. ' . ( $s['context'] ? 'Site context: ' . wp_strip_all_tags( $s['context'] ) . '. ' : '' ) . 'Answer ONLY as JSON: {"alt":"...","description":"..."}';
		$body = array( 'contents' => array( array( 'parts' => array( array( 'text' => $prompt ), array( 'inline_data' => array( 'mime_type' => $mime, 'data' => base64_encode( file_get_contents( $path ) ) ) ) ) ) ), 'generationConfig' => array( 'temperature' => 0.2, 'responseMimeType' => 'application/json' ) );
		$r = wp_remote_post( self::endpoint( $s['model'] ), array( 'timeout' => 45, 'headers' => array( 'Content-Type' => 'application/json', 'x-goog-api-key' => $key ), 'body' => wp_json_encode( $body ) ) );
		if ( is_wp_error( $r ) ) { return $r; }
		$code = wp_remote_retrieve_response_code( $r ); if ( 200 !== $code ) { return new WP_Error( 'api', 'Gemini API error HTTP ' . $code ); }
		$j = json_decode( wp_remote_retrieve_body( $r ), true ); $txt = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
		$p = self::parse( $txt ); if ( ! $p ) { return new WP_Error( 'parse', 'Could not read the model answer' ); }
		$p['lang'] = self::lang_name(); $p['time'] = time(); $p['status'] = 'pending';
		return $p;
	}
	static function parse( $txt ) {
		$txt = trim( preg_replace( '/^```(?:json)?|```$/m', '', $txt ) ); $d = json_decode( $txt, true );
		if ( ! is_array( $d ) || empty( $d['alt'] ) || ! is_string( $d['alt'] ) ) { return null; }
		return array( 'alt' => mb_substr( sanitize_text_field( $d['alt'] ), 0, 200 ), 'description' => mb_substr( sanitize_textarea_field( is_string( $d['description'] ?? '' ) ? ( $d['description'] ?? '' ) : '' ), 0, 500 ) );
	}
	static function process( $id ) {
		$p = self::generate( $id ); if ( is_wp_error( $p ) ) { update_post_meta( $id, self::META, array( 'status' => 'error', 'error' => $p->get_error_message(), 'time' => time() ) ); return $p; }
		update_post_meta( $id, self::META, $p );
		if ( 'auto' === self::s()['mode'] && '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) { self::apply( $id ); }   // auto mode never overwrites existing alt
		return $p;
	}
	static function apply( $id ) {
		$m = get_post_meta( $id, self::META, true ); if ( empty( $m['alt'] ) ) { return false; }
		update_post_meta( $id, '_wp_attachment_image_alt', $m['alt'] );
		if ( ! empty( $m['description'] ) && '' === trim( (string) get_post_field( 'post_content', $id ) ) ) { wp_update_post( array( 'ID' => $id, 'post_content' => $m['description'] ) ); }
		$m['status'] = 'applied'; update_post_meta( $id, self::META, $m ); return true;
	}
	static function on_upload( $meta, $id ) {
		if ( self::s()['on_upload'] && self::key() && wp_attachment_is_image( $id ) && '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) { self::process( $id ); }
		return $meta;
	}
	static function missing() {
		return get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit', 'posts_per_page' => 10, 'fields' => 'ids',
			'meta_query' => array( 'relation' => 'AND', array( 'relation' => 'OR', array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ), array( 'key' => '_wp_attachment_image_alt', 'value' => '' ) ), array( 'key' => self::META, 'compare' => 'NOT EXISTS' ) ) ) );
	}

	/* ---- admin ---- */
	static function guard() { if ( ! current_user_can( 'upload_files' ) ) { wp_die( 'Forbidden', 403 ); } check_admin_referer( 'ngama' ); }
	static function back( $m ) { wp_safe_redirect( add_query_arg( 'msg', rawurlencode( $m ), admin_url( 'upload.php?page=ngama' ) ) ); exit; }
	static function act_save() {
		self::guard(); if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden', 403 ); } $in = wp_unslash( $_POST ); $s = self::s();
		$n = array( 'model' => preg_replace( '/[^a-z0-9.\-]/i', '', $in['model'] ?? 'gemini-flash-latest' ), 'lang' => in_array( $in['lang'] ?? '', array( 'auto', 'ar', 'en', 'fr', 'de' ), true ) ? $in['lang'] : 'auto', 'mode' => 'auto' === ( $in['mode'] ?? '' ) ? 'auto' : 'review', 'on_upload' => empty( $in['on_upload'] ) ? 0 : 1, 'context' => sanitize_text_field( $in['context'] ?? '' ), 'key_enc' => $s['key_enc'] );
		if ( ! empty( $in['key'] ) ) { $n['key_enc'] = self::enc( trim( $in['key'] ) ); }
		update_option( self::OPT, $n, false ); self::back( 'Saved' );
	}
	static function act_scan() { self::guard(); @set_time_limit( 120 ); $ok = 0; $bad = 0; foreach ( self::missing() as $id ) { is_wp_error( self::process( $id ) ) ? $bad++ : $ok++; } self::back( "Generated: $ok, failed: $bad (10 per run)" ); }
	static function act_approve() { self::guard(); $n = 0; foreach ( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) as $id ) { if ( current_user_can( 'edit_post', $id ) && self::apply( $id ) ) { $n++; } } self::back( "Approved: $n" ); }
	static function act_reject() { self::guard(); foreach ( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) as $id ) { if ( current_user_can( 'edit_post', $id ) ) { $m = get_post_meta( $id, self::META, true ); if ( $m ) { $m['status'] = 'rejected'; update_post_meta( $id, self::META, $m ); } } } self::back( 'Rejected' ); }
	static function pending() { return get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 50, 'fields' => 'ids', 'meta_key' => self::META ) ); }
	static function page() {
		if ( ! current_user_can( 'upload_files' ) ) { return; }
		$s = self::s(); $act = admin_url( 'admin-post.php' ); $rtl = is_rtl() ? ' dir="rtl"' : '';
		echo '<div class="wrap"' . $rtl . '><h1>NG ' . esc_html__( 'AI Media Assistant', 'ng-ama' ) . '</h1>';
		if ( isset( $_GET['msg'] ) ) { echo '<div class="notice notice-info"><p>' . esc_html( wp_unslash( $_GET['msg'] ) ) . '</p></div>'; }
		if ( current_user_can( 'manage_options' ) ) {
			echo '<form method="post" action="' . esc_url( $act ) . '">'; wp_nonce_field( 'ngama' ); echo '<input type="hidden" name="action" value="ngama_save"><table class="form-table">';
			printf( '<tr><th>Gemini model</th><td><input name="model" class="regular-text" value="%s"><p class="description">Model names change; use one your API key can access.</p></td></tr>', esc_attr( $s['model'] ) );
			echo '<tr><th>Language</th><td><select name="lang">'; foreach ( array( 'auto' => 'Site language', 'ar' => 'العربية', 'en' => 'English', 'fr' => 'Français', 'de' => 'Deutsch' ) as $k => $l ) { printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $s['lang'], $k, false ), esc_html( $l ) ); } echo '</select></td></tr>';
			printf( '<tr><th>Mode</th><td><select name="mode"><option value="review" %s>Review queue (recommended)</option><option value="auto" %s>Auto-apply to images with empty alt</option></select></td></tr>', selected( $s['mode'], 'review', false ), selected( $s['mode'], 'auto', false ) );
			printf( '<tr><th>On upload</th><td><input type="checkbox" name="on_upload" value="1" %s></td></tr>', checked( $s['on_upload'], 1, false ) );
			printf( '<tr><th>Site context</th><td><input name="context" class="large-text" value="%s" placeholder="e.g. dental clinic in Cairo"></td></tr>', esc_attr( $s['context'] ) );
			if ( defined( 'NGAMA_KEY' ) ) { echo '<tr><th>API key</th><td>From wp-config.php (NGAMA_KEY).</td></tr>'; } else { printf( '<tr><th>API key</th><td><input type="password" name="key" class="regular-text" autocomplete="off"><p class="description">%s</p></td></tr>', $s['key_enc'] ? 'Saved (encrypted). Leave empty to keep.' : 'Prefer defining NGAMA_KEY in wp-config.php.' ); }
			echo '</table>'; submit_button( __( 'Save settings', 'ng-ama' ) ); echo '</form>';
		}
		echo '<form method="post" action="' . esc_url( $act ) . '">'; wp_nonce_field( 'ngama' ); echo '<input type="hidden" name="action" value="ngama_scan">'; submit_button( __( 'Generate for images without alt text', 'ng-ama' ), 'secondary' ); echo '</form>';
		echo '<h2>' . esc_html__( 'Review queue', 'ng-ama' ) . '</h2><form method="post" action="' . esc_url( $act ) . '">'; wp_nonce_field( 'ngama' ); echo '<table class="widefat"><tbody>';
		foreach ( self::pending() as $id ) { $m = get_post_meta( $id, self::META, true ); if ( empty( $m['status'] ) || 'pending' !== $m['status'] ) { continue; }
			printf( '<tr><td><input type="checkbox" name="ids[]" value="%d"></td><td>%s</td><td><strong>%s</strong><br>%s</td></tr>', $id, wp_get_attachment_image( $id, array( 80, 80 ) ), esc_html( $m['alt'] ), esc_html( $m['description'] ?? '' ) ); }
		echo '</tbody></table><button class="button button-primary" name="action" value="ngama_approve">' . esc_html__( 'Approve', 'ng-ama' ) . '</button> <button class="button" name="action" value="ngama_reject">' . esc_html__( 'Reject', 'ng-ama' ) . '</button></form></div>';
	}
}
NGAMA::init();
