<?php
/**
 * Plugin Name: External Request Inventory
 * Description: List the external hosts your site contacts: server-side HTTP requests and resources in your public pages, checked against your privacy policy page. Stores host names only.
 * Version: 0.1.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: LW IT Solutions
 * Author URI: https://www.lukaswojcik.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: external-request-inventory
 *
 * @package ExternalRequestInventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require_once __DIR__ . '/includes/class-erinv-store.php';

if ( ERINV_Store::recording() ) {
	add_filter( 'pre_http_request', array( 'ERINV_Store', 'observe' ), 10, 3 );
}
add_action( 'admin_menu', 'erinv_admin_menu' );
add_action( 'admin_enqueue_scripts', 'erinv_admin_assets' );
add_action( 'wp_ajax_erinv_report', 'erinv_ajax_report' );
add_action( 'wp_ajax_erinv_save', 'erinv_ajax_save' );
add_action( 'wp_ajax_erinv_action', 'erinv_ajax_action' );

/** Register the tools screen. */
function erinv_admin_menu() {
	add_management_page( __( 'External Request Inventory', 'external-request-inventory' ), __( 'External Requests', 'external-request-inventory' ), 'manage_options', 'external-request-inventory', 'erinv_admin_page' );
}

/** Public pages of this site that the browser scan loads first.
 * @return array Absolute URLs on this site.
 */
function erinv_scan_urls() {
	$urls   = array( home_url( '/' ) );
	$policy = (int) get_option( 'wp_page_for_privacy_policy' );
	if ( $policy && 'publish' === get_post_status( $policy ) ) {
		$urls[] = get_permalink( $policy );
	}
	foreach ( array( 'post' => 5, 'page' => 3 ) as $type => $limit ) {
		$query = new WP_Query(
			array(
				'post_type'              => $type,
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'has_password'           => false,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'fields'                 => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $query->posts as $id ) {
			$urls[] = get_permalink( $id );
		}
	}
	return array_values( array_unique( array_filter( $urls ) ) );
}

/** Load local assets only on our screen.
 * @param string $hook Current screen.
 */
function erinv_admin_assets( $hook ) {
	if ( 'tools_page_external-request-inventory' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'erinv-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), '0.1.0' );
	wp_enqueue_script( 'erinv-admin', plugins_url( 'assets/admin.js', __FILE__ ), array(), '0.1.0', true );
	wp_localize_script(
		'erinv-admin',
		'ERINV',
		array(
			'url'   => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'erinv' ),
			'pages' => erinv_scan_urls(),
			'own'   => array_values( array_unique( array_filter( array( ERINV_Store::host( home_url() ), ERINV_Store::host( site_url() ) ) ) ) ),
			'text'  => array(
				'loading'    => __( 'Loading…', 'external-request-inventory' ),
				/* translators: 1: current page number, 2: number of pages, 3: page address. */
				'scanning'   => __( 'Scanning %1$d of %2$d: %3$s', 'external-request-inventory' ),
				/* translators: 1: number of pages scanned, 2: number of pages that failed, 3: date and time. */
				'scanned'    => __( 'Scanned %1$d pages, %2$d failed. Last scan: %3$s', 'external-request-inventory' ),
				/* translators: 1: date and time, 2: number of pages. */
				'last'       => __( 'Last scan: %1$s, %2$d pages.', 'external-request-inventory' ),
				'never'      => __( 'No browser scan yet.', 'external-request-inventory' ),
				'error'      => __( 'The request failed. Please retry or reload the page.', 'external-request-inventory' ),
				/* translators: %s: page address. */
				'failed'     => __( 'Could not load: %s', 'external-request-inventory' ),
				/* translators: %s: address entered by the user. */
				'foreign'    => __( 'Skipped, not on this site: %s', 'external-request-inventory' ),
				/* translators: 1: number of hosts, 2: hosts not named in the privacy policy, 3: new hosts. */
				'summary'    => __( '%1$d external hosts. %2$d not named in the privacy policy. %3$d new since the previous scan.', 'external-request-inventory' ),
				'nopolicy'   => __( 'No published privacy policy page is set under Settings > Privacy, so the policy check is skipped.', 'external-request-inventory' ),
				'empty'      => __( 'No external hosts recorded yet.', 'external-request-inventory' ),
				'browser'    => __( 'Browser', 'external-request-inventory' ),
				'server'     => __( 'Server', 'external-request-inventory' ),
				/* translators: %s: date and time. */
				'once'       => __( '1 request, %s', 'external-request-inventory' ),
				/* translators: 1: number of requests, 2: date and time. */
				'times'      => __( '%1$d requests, last %2$s', 'external-request-inventory' ),
				'new'        => __( 'new', 'external-request-inventory' ),
				'yes'        => __( 'named', 'external-request-inventory' ),
				'no'         => __( 'not named', 'external-request-inventory' ),
				'na'         => __( 'no policy page', 'external-request-inventory' ),
				/* translators: %s: date and time. */
				'recordOn'   => __( 'Recording server requests since %s.', 'external-request-inventory' ),
				'recordOff'  => __( 'Recording of server requests is paused.', 'external-request-inventory' ),
				/* translators: %d: number of hosts. */
				'dropped'    => __( '%d further hosts were not stored because the list is full.', 'external-request-inventory' ),
				'pause'      => __( 'Pause recording', 'external-request-inventory' ),
				'resume'     => __( 'Resume recording', 'external-request-inventory' ),
				'confirmClr' => __( 'Delete the recorded server hosts and start a new log?', 'external-request-inventory' ),
				'types'      => array(
					'script'        => __( 'script', 'external-request-inventory' ),
					'style'         => __( 'stylesheet', 'external-request-inventory' ),
					'font'          => __( 'font', 'external-request-inventory' ),
					'image'         => __( 'image', 'external-request-inventory' ),
					'media'         => __( 'audio/video', 'external-request-inventory' ),
					'iframe'        => __( 'iframe', 'external-request-inventory' ),
					'embed'         => __( 'embed', 'external-request-inventory' ),
					'form'          => __( 'form target', 'external-request-inventory' ),
					'preconnect'    => __( 'preconnect', 'external-request-inventory' ),
					'prefetch'      => __( 'prefetch', 'external-request-inventory' ),
					'inline-script' => __( 'named in inline script', 'external-request-inventory' ),
					'inline-style'  => __( 'inline style', 'external-request-inventory' ),
					'css'           => __( 'from a stylesheet', 'external-request-inventory' ),
				),
				'contexts'   => array(
					'cron'  => __( 'cron', 'external-request-inventory' ),
					'cli'   => __( 'WP-CLI', 'external-request-inventory' ),
					'ajax'  => __( 'AJAX', 'external-request-inventory' ),
					'rest'  => __( 'REST API', 'external-request-inventory' ),
					'admin' => __( 'admin', 'external-request-inventory' ),
					'front' => __( 'front end', 'external-request-inventory' ),
				),
				'csv'        => array( 'host', 'service', 'browser_types', 'browser_pages', 'server_requests', 'server_last', 'server_sources', 'server_contexts', 'first_seen', 'new', 'privacy_policy' ),
			),
		)
	);
}

/** Render the tools screen. */
function erinv_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap erinv-wrap">
		<h1><?php esc_html_e( 'External Request Inventory', 'external-request-inventory' ); ?></h1>
		<p><?php esc_html_e( 'Which external hosts does this site contact? The list combines two sources and checks each host against the privacy policy page.', 'external-request-inventory' ); ?></p>
		<ul class="erinv-list">
			<li><?php esc_html_e( 'Server: every request WordPress, a plugin or the theme makes through the WordPress HTTP API, with the component that made it. Recorded from activation on.', 'external-request-inventory' ); ?></li>
			<li><?php esc_html_e( 'Browser: scripts, stylesheets, fonts, images, iframes, forms and preconnects to other hosts in your public pages, loaded without cookies as a first-time visitor sees them before any consent.', 'external-request-inventory' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'Only host names, counts, dates, component names and page paths are stored. No full URLs, query strings or visitor data. The scan loads pages of this site only and never requests an external file.', 'external-request-inventory' ); ?></p>

		<h2><?php esc_html_e( 'Browser scan', 'external-request-inventory' ); ?></h2>
		<p>
			<?php
			/* translators: %d: number of pages. */
			echo esc_html( sprintf( _n( 'The scan loads %d page: the home page, the privacy policy and the latest posts and pages.', 'The scan loads %d pages: the home page, the privacy policy and the latest posts and pages.', count( erinv_scan_urls() ), 'external-request-inventory' ), count( erinv_scan_urls() ) ) );
			?>
		</p>
		<p>
			<label for="erinv-extra"><?php esc_html_e( 'Further pages of this site, one address per line (optional, up to 20):', 'external-request-inventory' ); ?></label><br>
			<textarea id="erinv-extra" rows="3" class="large-text code"></textarea>
		</p>
		<p class="erinv-actions">
			<button type="button" class="button button-primary" id="erinv-scan"><?php esc_html_e( 'Scan pages', 'external-request-inventory' ); ?></button>
			<button type="button" class="button" id="erinv-csv" disabled><?php esc_html_e( 'Export as CSV', 'external-request-inventory' ); ?></button>
		</p>
		<p id="erinv-status" role="status" aria-live="polite"></p>

		<h2><?php esc_html_e( 'Server requests', 'external-request-inventory' ); ?></h2>
		<p id="erinv-recording"></p>
		<p class="erinv-actions">
			<button type="button" class="button" id="erinv-toggle"></button>
			<button type="button" class="button" id="erinv-clear"><?php esc_html_e( 'Clear server log', 'external-request-inventory' ); ?></button>
		</p>

		<h2><?php esc_html_e( 'External hosts', 'external-request-inventory' ); ?></h2>
		<p id="erinv-summary"></p>
		<div class="erinv-table">
			<table class="widefat striped">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Host', 'external-request-inventory' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Where', 'external-request-inventory' ); ?></th>
					<th scope="col"><?php esc_html_e( 'First seen', 'external-request-inventory' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Privacy policy', 'external-request-inventory' ); ?></th>
				</tr></thead>
				<tbody id="erinv-results"></tbody>
			</table>
		</div>
		<p><?php esc_html_e( 'Limits: resources that scripts add after the page has loaded, or only after consent, are not visible to the scan. Requests that bypass the WordPress HTTP API are not recorded. "Named" means the host, its domain or the service name appears in the policy text; it is a prompt for review, not legal advice.', 'external-request-inventory' ); ?></p>
	</div>
	<?php
}

/** Stop unless the user is an administrator. Nonces never substitute for permissions. */
function erinv_require_admin() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Administrator permission required.', 'external-request-inventory' ) ), 403 );
	}
}

/** Return the current report. */
function erinv_ajax_report() {
	erinv_require_admin();
	check_ajax_referer( 'erinv', 'nonce' );
	wp_send_json_success( ERINV_Store::report() );
}

/** Store a browser scan and return the report. */
function erinv_ajax_save() {
	erinv_require_admin();
	check_ajax_referer( 'erinv', 'nonce' );
	// JSON with host names and decoded page paths; every field is validated again in ERINV_Store.
	$raw   = isset( $_POST['hosts'] ) && is_string( $_POST['hosts'] ) ? json_decode( sanitize_textarea_field( wp_unslash( $_POST['hosts'] ) ), true ) : null;
	$pages = isset( $_POST['pages'] ) && is_string( $_POST['pages'] ) ? json_decode( sanitize_textarea_field( wp_unslash( $_POST['pages'] ) ), true ) : null;
	if ( ! is_array( $raw ) || ! is_array( $pages ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid request.', 'external-request-inventory' ) ), 400 );
	}
	ERINV_Store::save_scan( ERINV_Store::clean_scan( $raw ), array_slice( ERINV_Store::clean_paths( $pages ), 0, 40 ) );
	wp_send_json_success( ERINV_Store::report() );
}

/** Pause or resume recording, or clear a log. */
function erinv_ajax_action() {
	erinv_require_admin();
	check_ajax_referer( 'erinv', 'nonce' );
	$task = isset( $_POST['task'] ) && is_string( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
	switch ( $task ) {
		case 'pause':
			ERINV_Store::set_recording( false );
			break;
		case 'resume':
			ERINV_Store::set_recording( true );
			break;
		case 'clear':
			ERINV_Store::clear_server();
			break;
		default:
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'external-request-inventory' ) ), 400 );
	}
	wp_send_json_success( ERINV_Store::report() );
}
