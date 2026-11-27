<?php
/**
 * Plugin Name: External Request Inventory
 * Description: List the external hosts your site contacts: server-side HTTP requests and resources in your public pages, checked against your privacy policy page. Stores host names, counts, dates, component names and page paths, no full URLs or visitor data.
 * Version: 0.3.0
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
define( 'ERINV_VERSION', '0.3.0' );
require_once __DIR__ . '/includes/class-erinv-store.php';

register_activation_hook( __FILE__, 'erinv_activate' );
if ( ERINV_Store::recording() ) {
	add_filter( 'pre_http_request', array( 'ERINV_Store', 'observe' ), 10, 3 );
}
add_action( 'admin_menu', 'erinv_admin_menu' );
add_action( 'admin_enqueue_scripts', 'erinv_admin_assets' );
add_action( 'wp_ajax_erinv_report', 'erinv_ajax_report' );
add_action( 'wp_ajax_erinv_save', 'erinv_ajax_save' );
add_action( 'wp_ajax_erinv_action', 'erinv_ajax_action' );

/** Activation: start the server log, so its start date is the activation date. An existing log is kept. */
function erinv_activate() {
	if ( false === get_option( ERINV_Store::SERVER ) ) {
		ERINV_Store::clear_server();
	}
}

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

/**
 * Further pages entered for the previous scan, so the next scan covers them again.
 * Each scan replaces the browser results; without them, hosts found only there would drop out.
 *
 * @return string[] Addresses on this site.
 */
function erinv_further_pages() {
	$scan = get_option( ERINV_Store::BROWSER, array() );
	if ( ! is_array( $scan ) || empty( $scan['further'] ) || ! is_array( $scan['further'] ) ) {
		return array();
	}
	$home   = wp_parse_url( home_url() );
	$origin = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
	$urls   = array();
	foreach ( ERINV_Store::clean_paths( $scan['further'] ) as $path ) {
		$urls[] = $origin . $path;
	}
	return array_slice( $urls, 0, 20 );
}

/** Load local assets only on our screen.
 * @param string $hook Current screen.
 */
function erinv_admin_assets( $hook ) {
	if ( 'tools_page_external-request-inventory' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'erinv-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), ERINV_VERSION );
	wp_enqueue_script( 'erinv-admin', plugins_url( 'assets/admin.js', __FILE__ ), array(), ERINV_VERSION, true );
	wp_localize_script(
		'erinv-admin',
		'ERINV',
		array(
			'url'     => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'erinv' ),
			'pages'   => erinv_scan_urls(),
			'privacy' => admin_url( 'options-privacy.php' ),
			'own'     => array_values( array_unique( array_filter( array( ERINV_Store::host( home_url() ), ERINV_Store::host( site_url() ) ) ) ) ),
			'text'    => array(
				'loading'    => __( 'Loading…', 'external-request-inventory' ),
				/* translators: 1: current page number, 2: number of pages, 3: page address. */
				'scanning'   => __( 'Scanning %1$d of %2$d: %3$s', 'external-request-inventory' ),
				/* translators: 1: number of pages scanned, 2: number of pages that failed, 3: date and time. */
				'scanned'    => __( 'Pages scanned: %1$d, failed: %2$d. Last scan: %3$s', 'external-request-inventory' ),
				/* translators: 1: date and time, 2: number of pages. */
				'last'       => __( 'Last scan: %1$s, pages: %2$d.', 'external-request-inventory' ),
				'never'      => __( 'No browser scan yet.', 'external-request-inventory' ),
				'tileHosts'  => __( 'External hosts', 'external-request-inventory' ),
				'tileNo'     => __( 'Not named in the privacy policy', 'external-request-inventory' ),
				'tileNew'    => __( 'New since the previous scan', 'external-request-inventory' ),
				'tileCover'  => __( 'Named in the privacy policy', 'external-request-inventory' ),
				/* translators: %s: date and time. */
				'tileReq'    => __( 'Server requests since %s', 'external-request-inventory' ),
				'onlyB'      => __( 'Browser only', 'external-request-inventory' ),
				'onlyS'      => __( 'Server only', 'external-request-inventory' ),
				'both'       => __( 'Browser and server', 'external-request-inventory' ),
				'sources'    => __( 'Where the hosts were found', 'external-request-inventory' ),
				'all'        => __( 'All', 'external-request-inventory' ),
				'fNo'        => __( 'Not named', 'external-request-inventory' ),
				'fNew'       => __( 'New', 'external-request-inventory' ),
				/* translators: 1: number of hosts shown, 2: number of hosts. */
				'shown'      => __( 'Showing %1$d of %2$d hosts.', 'external-request-inventory' ),
				'noMatch'    => __( 'No host matches the filter.', 'external-request-inventory' ),
				'recOn'      => __( 'Recording', 'external-request-inventory' ),
				'recOff'     => __( 'Paused', 'external-request-inventory' ),
				'pagesLbl'   => __( 'Pages:', 'external-request-inventory' ),
				'notes'      => __( 'Not every page could be scanned:', 'external-request-inventory' ),
				'policyOpen' => __( 'View privacy policy', 'external-request-inventory' ),
				'policySet'  => __( 'Choose a privacy policy page', 'external-request-inventory' ),
				'cols'       => array( __( 'Host', 'external-request-inventory' ), __( 'Where', 'external-request-inventory' ), __( 'First seen', 'external-request-inventory' ), __( 'Privacy policy', 'external-request-inventory' ) ),
				'error'      => __( 'The request failed. Please retry or reload the page.', 'external-request-inventory' ),
				/* translators: %s: page address. */
				'failed'     => __( 'Could not load: %s', 'external-request-inventory' ),
				/* translators: %s: page address. */
				'redirect'   => __( 'Not loaded, the address redirects (redirects are not followed): %s', 'external-request-inventory' ),
				/* translators: %s: address entered by the user. */
				'foreign'    => __( 'Skipped, not on this site: %s', 'external-request-inventory' ),
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
				'clearTitle' => __( 'Clear server log', 'external-request-inventory' ),
				'confirmClr' => __( 'Delete the recorded server hosts and start a new log?', 'external-request-inventory' ),
				'clearOk'    => __( 'Clear log', 'external-request-inventory' ),
				'cancel'     => __( 'Cancel', 'external-request-inventory' ),
				'flowB'      => __( 'Visitors\' browsers', 'external-request-inventory' ),
				'flowS'      => __( 'Server (WordPress)', 'external-request-inventory' ),
				/* translators: %d: number of hosts. */
				'flowMore'   => __( '+ %d more hosts in the list below', 'external-request-inventory' ),
				/* translators: 1: host name, 2: where it was found, 3: privacy policy state. */
				'flowHost'   => __( '%1$s – %2$s – %3$s. Show in the list.', 'external-request-inventory' ),
				'flowYes'    => __( 'named in the privacy policy', 'external-request-inventory' ),
				'flowNo'     => __( 'not named in the privacy policy', 'external-request-inventory' ),
				'flowNa'     => __( 'privacy policy not checked', 'external-request-inventory' ),
				/* translators: 1: page number, 2: number of pages. */
				'stepOf'     => __( 'Page %1$d of %2$d', 'external-request-inventory' ),
				'stepWait'   => __( 'waiting', 'external-request-inventory' ),
				'stepNow'    => __( 'loading …', 'external-request-inventory' ),
				'stepFail'   => __( 'not scanned', 'external-request-inventory' ),
				'stepNone'   => __( 'no external host', 'external-request-inventory' ),
				/* translators: %d: number of hosts. */
				'stepHosts'  => __( '%d external hosts', 'external-request-inventory' ),
				'stepOne'    => __( '1 external host', 'external-request-inventory' ),
				/* translators: %d: number of hosts. */
				'stepNew'    => __( '%d not seen on earlier pages', 'external-request-inventory' ),
				'copied'     => __( 'Copied to the clipboard.', 'external-request-inventory' ),
				'copyNone'   => __( 'Every host is named in the privacy policy; there is nothing to copy.', 'external-request-inventory' ),
				'copyFail'   => __( 'Copying was not possible. The text is selected below and can be copied with Ctrl+C.', 'external-request-inventory' ),
				/* translators: 1: site name, 2: date. */
				'copyHead'   => __( 'External hosts not named in the privacy policy (%1$s, %2$s)', 'external-request-inventory' ),
				/* translators: %s: resource types. */
				'copyB'      => __( 'Browser: %s', 'external-request-inventory' ),
				/* translators: %s: pages. */
				'copyPages'  => __( 'on %s', 'external-request-inventory' ),
				/* translators: 1: number of requests, 2: components. */
				'copyS'      => __( 'Server: %1$d requests by %2$s', 'external-request-inventory' ),
				/* translators: %s: components. */
				'copyS1'     => __( 'Server: 1 request by %s', 'external-request-inventory' ),
				'today'      => wp_date( get_option( 'date_format' ) ),
				'site'       => get_bloginfo( 'name' ),
				/* translators: %d: number of hosts. */
				'chNew'      => __( '%d hosts appeared since the previous scan', 'external-request-inventory' ),
				'chOne'      => __( '1 host appeared since the previous scan', 'external-request-inventory' ),
				'chNo'       => __( 'not named', 'external-request-inventory' ),
				/* translators: %s: host name. */
				'chJump'     => __( 'Show %s in the list', 'external-request-inventory' ),
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
	$page_count = count( erinv_scan_urls() );
	?>
	<div class="wrap erinv-wrap">
		<h1 class="erinv-title"><span class="dashicons dashicons-networking" aria-hidden="true"></span> <?php esc_html_e( 'External Request Inventory', 'external-request-inventory' ); ?></h1>
		<p class="erinv-lead"><?php esc_html_e( 'Which external hosts does this site contact? The list combines two sources and checks each host against the privacy policy page.', 'external-request-inventory' ); ?></p>
		<details class="erinv-how">
			<summary><?php esc_html_e( 'How the inventory works', 'external-request-inventory' ); ?></summary>
			<ul class="erinv-list">
				<li><?php esc_html_e( 'Server: every request WordPress, a plugin or the theme makes through the WordPress HTTP API, with the component that made it. Recorded while the plugin is active and recording is not paused, starting on activation or when the log was last cleared.', 'external-request-inventory' ); ?></li>
				<li><?php esc_html_e( 'Browser: scripts, stylesheets, fonts, images, iframes, forms and preconnects to other hosts in your public pages, loaded without cookies as a first-time visitor sees them before any consent.', 'external-request-inventory' ); ?></li>
			</ul>
			<p><?php esc_html_e( 'Only host names, counts, dates, component names and page paths are stored. No full URLs, query strings or visitor data. The scan loads pages of this site only, does not follow redirects and never requests an external file.', 'external-request-inventory' ); ?></p>
		</details>

		<section class="erinv-overview" id="erinv-overview" aria-label="<?php esc_attr_e( 'Overview', 'external-request-inventory' ); ?>" hidden></section>

		<section class="erinv-changes" id="erinv-changes" aria-labelledby="erinv-changes-title" hidden>
			<h2 id="erinv-changes-title" class="erinv-changes-title"></h2>
			<p class="erinv-changes-lead"><?php esc_html_e( 'A plugin, theme or embed added since then may load them. Each host leads to its row in the list.', 'external-request-inventory' ); ?></p>
			<ul class="erinv-changes-list" id="erinv-changes-list"></ul>
		</section>

		<section class="erinv-card erinv-flow-card" id="erinv-flow-card" aria-labelledby="erinv-flow-title" hidden>
			<h2 id="erinv-flow-title"><span class="dashicons dashicons-randomize" aria-hidden="true"></span> <?php esc_html_e( 'Where data flows', 'external-request-inventory' ); ?></h2>
			<p class="erinv-muted"><?php esc_html_e( 'Each line connects a source with an external host. Red: the host is not named in the privacy policy, green: it is named. Selecting a host shows its row in the list.', 'external-request-inventory' ); ?></p>
			<div class="erinv-flow" id="erinv-flow"></div>
			<svg id="erinv-flow-template" class="erinv-flow-lines" aria-hidden="true" focusable="false" hidden></svg>
		</section>

		<div class="erinv-panels">
			<section class="erinv-card" aria-labelledby="erinv-scan-title">
				<h2 id="erinv-scan-title"><span class="dashicons dashicons-search" aria-hidden="true"></span> <?php esc_html_e( 'Browser scan', 'external-request-inventory' ); ?></h2>
				<p>
					<?php
					/* translators: %d: number of pages. */
					echo esc_html( sprintf( _n( 'The scan loads %d page: the home page, the privacy policy and the latest posts and pages.', 'The scan loads %d pages: the home page, the privacy policy and the latest posts and pages.', $page_count, 'external-request-inventory' ), $page_count ) );
					?>
				</p>
				<p>
					<label for="erinv-extra"><?php esc_html_e( 'Further pages of this site, one address per line (optional, up to 20):', 'external-request-inventory' ); ?></label><br>
					<textarea id="erinv-extra" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", erinv_further_pages() ) ); ?></textarea>
				</p>
				<p class="erinv-actions">
					<button type="button" class="button button-primary" id="erinv-scan"><?php esc_html_e( 'Scan pages', 'external-request-inventory' ); ?></button>
					<button type="button" class="button" id="erinv-csv" disabled><?php esc_html_e( 'Export as CSV', 'external-request-inventory' ); ?></button>
					<span class="spinner" id="erinv-spinner"></span>
				</p>
				<div class="erinv-progress" id="erinv-progress-wrap" hidden>
					<progress id="erinv-progress" max="1" value="0" aria-labelledby="erinv-status"></progress>
				</div>
				<div id="erinv-status" class="erinv-status" role="status" aria-live="polite"></div>
				<ol class="erinv-steps" id="erinv-steps" aria-label="<?php esc_attr_e( 'Pages of the last scan', 'external-request-inventory' ); ?>" hidden></ol>
			</section>

			<section class="erinv-card" aria-labelledby="erinv-server-title">
				<h2 id="erinv-server-title"><span class="dashicons dashicons-cloud" aria-hidden="true"></span> <?php esc_html_e( 'Server requests', 'external-request-inventory' ); ?></h2>
				<div id="erinv-recording" class="erinv-recording"></div>
				<p class="erinv-actions">
					<button type="button" class="button" id="erinv-toggle"></button>
					<button type="button" class="button erinv-button-delete" id="erinv-clear"><?php esc_html_e( 'Clear server log', 'external-request-inventory' ); ?></button>
				</p>
			</section>
		</div>

		<section class="erinv-card erinv-hosts" aria-labelledby="erinv-hosts-title">
			<h2 id="erinv-hosts-title"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span> <?php esc_html_e( 'External hosts', 'external-request-inventory' ); ?></h2>
			<div id="erinv-summary"></div>
			<div class="erinv-toolbar" id="erinv-toolbar" hidden>
				<div class="erinv-search">
					<label for="erinv-search"><?php esc_html_e( 'Filter hosts', 'external-request-inventory' ); ?></label>
					<span class="erinv-search-field">
						<input type="search" id="erinv-search" class="regular-text" autocomplete="off" spellcheck="false" aria-describedby="erinv-search-key" placeholder="<?php esc_attr_e( 'Host or service', 'external-request-inventory' ); ?>">
						<kbd id="erinv-search-key" title="<?php esc_attr_e( 'Press / to filter', 'external-request-inventory' ); ?>"><span aria-hidden="true">/</span><span class="screen-reader-text"><?php esc_html_e( 'Press / to filter', 'external-request-inventory' ); ?></span></kbd>
					</span>
				</div>
				<div class="erinv-filters" id="erinv-filters" role="group" aria-label="<?php esc_attr_e( 'Show', 'external-request-inventory' ); ?>"></div>
			</div>
			<div class="erinv-table" id="erinv-table">
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
			<p id="erinv-shown" class="erinv-shown" aria-live="polite"></p>
			<div class="erinv-copy-box" id="erinv-copy-box" hidden>
				<button type="button" class="button" id="erinv-copy"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><span><?php esc_html_e( 'Copy hosts not named as text', 'external-request-inventory' ); ?></span></button>
				<span class="erinv-muted"><?php esc_html_e( 'A list with service, resource types, pages and components, as a starting point for the privacy policy.', 'external-request-inventory' ); ?></span>
				<p class="erinv-copy-status" id="erinv-copy-status" role="status" aria-live="polite"></p>
				<textarea class="erinv-copy-area" id="erinv-copy-area" readonly hidden aria-label="<?php esc_attr_e( 'Hosts not named as text', 'external-request-inventory' ); ?>"></textarea>
			</div>
			<div id="erinv-empty" class="erinv-empty" hidden>
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<p class="erinv-empty-title"><?php esc_html_e( 'No external hosts recorded yet.', 'external-request-inventory' ); ?></p>
				<p><?php esc_html_e( 'Scan the public pages to list the hosts they load. Server requests appear here as WordPress, plugins and the theme make them, for example update checks after a day.', 'external-request-inventory' ); ?></p>
				<p><button type="button" class="button button-primary" id="erinv-empty-scan"><?php esc_html_e( 'Scan pages', 'external-request-inventory' ); ?></button></p>
			</div>
		</section>
		<p class="erinv-limits"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> <?php esc_html_e( 'Limits: resources that scripts add after the page has loaded, or only after consent, are not visible to the scan. Requests that bypass the WordPress HTTP API are not recorded. "Named" means the host, its domain or the service name appears in the policy text; it is a prompt for review, not legal advice.', 'external-request-inventory' ); ?></p>
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
	// JSON with host names, resource types and decoded page paths. After decoding, ERINV_Store sanitizes every
	// value again (sanitize_text_field, sanitize_key) and keeps only valid host names, known types and site paths.
	$raw   = isset( $_POST['hosts'] ) && is_string( $_POST['hosts'] ) ? json_decode( sanitize_textarea_field( wp_unslash( $_POST['hosts'] ) ), true ) : null;
	$pages = isset( $_POST['pages'] ) && is_string( $_POST['pages'] ) ? json_decode( sanitize_textarea_field( wp_unslash( $_POST['pages'] ) ), true ) : null;
	$more  = isset( $_POST['further'] ) && is_string( $_POST['further'] ) ? json_decode( sanitize_textarea_field( wp_unslash( $_POST['further'] ) ), true ) : array();
	if ( ! is_array( $raw ) || ! is_array( $pages ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid request.', 'external-request-inventory' ) ), 400 );
	}
	$more = is_array( $more ) ? array_slice( ERINV_Store::clean_paths( $more ), 0, 20 ) : array();
	ERINV_Store::save_scan( ERINV_Store::clean_scan( $raw ), array_slice( ERINV_Store::clean_paths( $pages ), 0, 40 ), $more );
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
