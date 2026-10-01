<?php
/**
 * Host inventory: server-side HTTP API requests and browser scan results, stored as host names only.
 *
 * @package ExternalRequestInventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores and reports external hosts. Never stores full URLs, query strings or visitor data. */
class ERINV_Store {
	/** Option with the server-side request log. */
	const SERVER = 'erinv_server';

	/** Option with the latest browser scan. */
	const BROWSER = 'erinv_browser';

	/** Option with settings. */
	const SETTINGS = 'erinv_settings';

	/** Hosts kept per list. */
	const MAX_HOSTS = 300;

	/** Components and pages kept per host. */
	const MAX_ITEMS = 5;

	/** Resource types the browser scan may report. */
	const TYPES = array( 'script', 'style', 'font', 'image', 'media', 'iframe', 'embed', 'form', 'preconnect', 'prefetch', 'inline-script', 'inline-style', 'css' );

	/** Hosts seen during this request, written once at shutdown.
	 * @var array
	 */
	private static $pending = array();

	/** Whether server-side recording is on. It is on after activation.
	 * @return bool
	 */
	public static function recording() {
		$settings = get_option( self::SETTINGS, array() );
		return ! is_array( $settings ) || ! isset( $settings['record'] ) || (bool) $settings['record'];
	}

	/** Switch server-side recording.
	 * @param bool $on New state.
	 */
	public static function set_recording( $on ) {
		update_option( self::SETTINGS, array( 'record' => (bool) $on ), false );
	}

	/** Note an outgoing HTTP API request. Hooked to pre_http_request; never changes the request.
	 * @param false|array|WP_Error $preempt Short-circuit value of other filters.
	 * @param array                $args    Request arguments.
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error Unchanged.
	 */
	public static function observe( $preempt, $args, $url ) {
		$host = self::host( (string) $url );
		if ( '' === $host || self::is_own( $host ) ) {
			return $preempt;
		}
		if ( ! self::$pending ) {
			add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		}
		if ( ! isset( self::$pending[ $host ] ) ) {
			self::$pending[ $host ] = array(
				'count'    => 0,
				'sources'  => array(),
				'contexts' => array(),
			);
		}
		++self::$pending[ $host ]['count'];
		self::$pending[ $host ]['sources'][ self::component() ]  = true;
		self::$pending[ $host ]['contexts'][ self::context() ]   = true;
		return $preempt;
	}

	/** Write the hosts of this request to the log. */
	public static function flush() {
		$log = self::server_log();
		$now = time();
		foreach ( self::$pending as $host => $seen ) {
			if ( ! isset( $log['hosts'][ $host ] ) ) {
				if ( count( $log['hosts'] ) >= self::MAX_HOSTS ) {
					++$log['dropped'];
					continue;
				}
				$log['hosts'][ $host ] = array(
					'first'    => $now,
					'last'     => $now,
					'count'    => 0,
					'sources'  => array(),
					'contexts' => array(),
				);
			}
			$entry             = $log['hosts'][ $host ];
			$entry['last']     = $now;
			$entry['count']   += $seen['count'];
			$entry['sources']  = array_slice( array_values( array_unique( array_merge( $entry['sources'], array_keys( $seen['sources'] ) ) ) ), 0, self::MAX_ITEMS );
			$entry['contexts'] = array_values( array_unique( array_merge( $entry['contexts'], array_keys( $seen['contexts'] ) ) ) );

			$log['hosts'][ $host ] = $entry;
		}
		self::$pending = array();
		update_option( self::SERVER, $log, false );
	}

	/** The stored server log with defaults.
	 * @return array
	 */
	private static function server_log() {
		$log = get_option( self::SERVER, array() );
		$log = is_array( $log ) ? $log : array();
		return array(
			'since'   => isset( $log['since'] ) ? (int) $log['since'] : time(),
			'dropped' => isset( $log['dropped'] ) ? (int) $log['dropped'] : 0,
			'hosts'   => isset( $log['hosts'] ) && is_array( $log['hosts'] ) ? $log['hosts'] : array(),
		);
	}

	/** Start a new server log. */
	public static function clear_server() {
		update_option( self::SERVER, array( 'since' => time(), 'dropped' => 0, 'hosts' => array() ), false );
	}

	/** Forget browser scans. */
	public static function clear_browser() {
		delete_option( self::BROWSER );
	}

	/** The plugin, must-use plugin or theme that started a request, from the call stack. File paths are not stored.
	 * @return string Component key such as "plugin:woocommerce", or "core".
	 */
	private static function component() {
		$own   = basename( dirname( __DIR__ ) );
		$roots = array(
			'plugin'    => wp_normalize_path( WP_PLUGIN_DIR ) . '/',
			'mu-plugin' => wp_normalize_path( WPMU_PLUGIN_DIR ) . '/',
			'theme'     => wp_normalize_path( get_theme_root() ) . '/',
		);
		$trace = ( new Exception() )->getTrace();
		foreach ( $trace as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );
			foreach ( $roots as $type => $root ) {
				if ( 0 !== strpos( $file, $root ) ) {
					continue;
				}
				$slug = strtok( substr( $file, strlen( $root ) ), '/' );
				if ( 'plugin' === $type && $slug === $own ) {
					continue 2;
				}
				return $type . ':' . ( 'mu-plugin' === $type ? basename( $slug, '.php' ) : $slug );
			}
		}
		return 'core';
	}

	/** Kind of request that made the call.
	 * @return string
	 */
	private static function context() {
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( wp_doing_ajax() ) {
			return 'ajax';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		return is_admin() ? 'admin' : 'front';
	}

	/** Lower-case host of a URL, or an empty string.
	 * @param string $url URL.
	 * @return string
	 */
	public static function host( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) ? self::clean_host( $host ) : '';
	}

	/** Validate a host name or IP address.
	 * @param string $host Candidate.
	 * @return string Clean host or empty string.
	 */
	public static function clean_host( $host ) {
		$host = strtolower( rtrim( trim( (string) $host ), '.' ) );
		$host = trim( $host, '[]' );
		if ( strlen( $host ) > 253 ) {
			return '';
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}
		return preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $host ) ? $host : '';
	}

	/** Whether a host belongs to this site.
	 * @param string $host Host.
	 * @return bool
	 */
	public static function is_own( $host ) {
		static $own = null;
		if ( null === $own ) {
			$own = array_filter( array_unique( array( self::host( home_url() ), self::host( site_url() ), self::host( admin_url() ) ) ) );
		}
		return in_array( $host, $own, true );
	}

	/** Store a browser scan sent from the tools screen.
	 * @param array $hosts Host => array( 'types' => array, 'pages' => array ), already validated.
	 * @param array $pages Scanned page paths.
	 * @return array Stored scan.
	 */
	public static function save_scan( $hosts, $pages ) {
		$old   = get_option( self::BROWSER, array() );
		$old   = is_array( $old ) ? $old : array();
		$known = isset( $old['known'] ) && is_array( $old['known'] ) ? $old['known'] : array();
		$now   = time();
		$new   = array();
		foreach ( array_keys( $hosts ) as $host ) {
			if ( ! isset( $known[ $host ] ) ) {
				$known[ $host ] = $now;
				if ( isset( $old['scanned'] ) ) {
					$new[] = $host;
				}
			}
		}
		if ( count( $known ) > self::MAX_HOSTS * 2 ) {
			asort( $known );
			$known = array_slice( $known, -self::MAX_HOSTS * 2, null, true );
		}
		$scan = array(
			'scanned' => $now,
			'pages'   => $pages,
			'hosts'   => $hosts,
			'known'   => $known,
			'new'     => $new,
		);
		update_option( self::BROWSER, $scan, false );
		return $scan;
	}

	/** Validate scan data from the browser.
	 * @param mixed $raw Decoded JSON.
	 * @return array Host => types and pages.
	 */
	public static function clean_scan( $raw ) {
		$hosts = array();
		if ( ! is_array( $raw ) ) {
			return $hosts;
		}
		foreach ( $raw as $host => $info ) {
			$host = self::clean_host( (string) $host );
			if ( '' === $host || self::is_own( $host ) || ! is_array( $info ) || count( $hosts ) >= self::MAX_HOSTS ) {
				continue;
			}
			$types = isset( $info['types'] ) && is_array( $info['types'] ) ? array_values( array_intersect( self::TYPES, array_map( 'strval', $info['types'] ) ) ) : array();
			$pages = isset( $info['pages'] ) && is_array( $info['pages'] ) ? self::clean_paths( $info['pages'] ) : array();
			if ( $types ) {
				$hosts[ $host ] = array(
					'types' => $types,
					'pages' => array_slice( $pages, 0, self::MAX_ITEMS ),
				);
			}
		}
		ksort( $hosts );
		return $hosts;
	}

	/** Keep site-relative paths only.
	 * @param array $paths Candidates.
	 * @return array
	 */
	public static function clean_paths( $paths ) {
		$clean = array();
		foreach ( $paths as $path ) {
			$path = is_string( $path ) ? substr( $path, 0, 200 ) : '';
			if ( '' !== $path && '/' === $path[0] && ( ! isset( $path[1] ) || '/' !== $path[1] ) && ! preg_match( '/[\s<>"\'\\\\]/', $path ) ) {
				$clean[] = $path;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/** Combined report of browser and server hosts with the privacy policy check.
	 * @return array
	 */
	public static function report() {
		$server  = self::server_log();
		$browser = get_option( self::BROWSER, array() );
		$browser = is_array( $browser ) ? $browser : array();
		$b_hosts = isset( $browser['hosts'] ) && is_array( $browser['hosts'] ) ? $browser['hosts'] : array();
		$known   = isset( $browser['known'] ) && is_array( $browser['known'] ) ? $browser['known'] : array();
		$new     = isset( $browser['new'] ) && is_array( $browser['new'] ) ? $browser['new'] : array();
		$policy  = self::policy_text();
		$names   = self::component_names();
		$rows    = array();
		foreach ( array_unique( array_merge( array_keys( $b_hosts ), array_keys( $server['hosts'] ) ) ) as $host ) {
			$first = array();
			$row   = array(
				'host'    => $host,
				'service' => self::service( $host ),
				'browser' => null,
				'server'  => null,
				'new'     => in_array( $host, $new, true ),
				'policy'  => null === $policy ? null : self::mentioned( $host, $policy ),
			);
			if ( isset( $b_hosts[ $host ] ) ) {
				$row['browser'] = $b_hosts[ $host ];
				if ( isset( $known[ $host ] ) ) {
					$first[] = (int) $known[ $host ];
				}
			}
			if ( isset( $server['hosts'][ $host ] ) ) {
				$entry         = $server['hosts'][ $host ];
				$first[]       = (int) $entry['first'];
				$row['server'] = array(
					'count'    => (int) $entry['count'],
					'last'     => self::date( (int) $entry['last'] ),
					'sources'  => array_map(
						function ( $key ) use ( $names ) {
							if ( isset( $names[ $key ] ) ) {
								return $names[ $key ];
							}
							/* translators: %s: file name of a must-use plugin. */
							return 0 === strpos( $key, 'mu-plugin:' ) ? sprintf( __( 'Must-use plugin: %s', 'external-request-inventory' ), substr( $key, 10 ) ) : $key;
						},
						$entry['sources']
					),
					'contexts' => $entry['contexts'],
				);
			}
			$row['first'] = $first ? self::date( min( $first ) ) : '';
			$rows[]       = $row;
		}
		usort(
			$rows,
			function ( $a, $b ) {
				$rank_a = ( false === $a['policy'] ? 0 : 2 ) + ( $a['new'] ? 0 : 1 );
				$rank_b = ( false === $b['policy'] ? 0 : 2 ) + ( $b['new'] ? 0 : 1 );
				return $rank_a === $rank_b ? strcmp( $a['host'], $b['host'] ) : $rank_a - $rank_b;
			}
		);
		$policy_id = (int) get_option( 'wp_page_for_privacy_policy' );
		return array(
			'rows'         => $rows,
			'scanned'      => isset( $browser['scanned'] ) ? self::date( (int) $browser['scanned'] ) : '',
			'pages'        => isset( $browser['pages'] ) ? $browser['pages'] : array(),
			'recording'    => self::recording(),
			'server_since' => self::date( $server['since'] ),
			'dropped'      => $server['dropped'],
			'policy_url'   => null === $policy ? '' : (string) get_permalink( $policy_id ),
		);
	}

	/** Lower-case text of the published privacy policy page, or null.
	 * @return string|null
	 */
	private static function policy_text() {
		$id = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( ! $id || 'publish' !== get_post_status( $id ) ) {
			return null;
		}
		$post = get_post( $id );
		return strtolower( wp_strip_all_tags( $post->post_content ) . ' ' . $post->post_title );
	}

	/** Whether the policy names the host, its domain or its known service.
	 * @param string $host Host.
	 * @param string $text Lower-case policy text.
	 * @return bool
	 */
	public static function mentioned( $host, $text ) {
		$needles = array_merge( array( $host, self::domain( $host ) ), self::aliases( self::service( $host ) ) );
		foreach ( array_unique( $needles ) as $needle ) {
			if ( '' !== $needle && preg_match( '/(?<![a-z0-9])' . preg_quote( $needle, '/' ) . '(?![a-z0-9])/', $text ) ) {
				return true;
			}
		}
		return false;
	}

	/** Words that name a service in a privacy policy. Short or ambiguous names get specific words.
	 * @param string $service Service name.
	 * @return array Lower-case search words.
	 */
	private static function aliases( $service ) {
		$aliases = self::data( 'aliases' );
		if ( isset( $aliases[ $service ] ) ) {
			return $aliases[ $service ];
		}
		return strlen( $service ) >= 5 ? array( strtolower( $service ) ) : array();
	}

	/** Registrable domain, approximated: two labels, or three for second-level public suffixes such as co.uk.
	 * @param string $host Host.
	 * @return string
	 */
	public static function domain( $host ) {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}
		$labels = explode( '.', $host );
		$count  = count( $labels );
		if ( $count < 3 ) {
			return $host;
		}
		$second = $labels[ $count - 2 ];
		$take   = ( 2 === strlen( $labels[ $count - 1 ] ) && in_array( $second, array( 'co', 'com', 'org', 'net', 'gov', 'ac', 'edu' ), true ) ) ? 3 : 2;
		return implode( '.', array_slice( $labels, -$take ) );
	}

	/** Name of a well-known service behind a host, or an empty string.
	 * @param string $host Host.
	 * @return string
	 */
	public static function service( $host ) {
		foreach ( self::data( 'services' ) as $suffix => $name ) {
			if ( $host === $suffix || substr( $host, -strlen( $suffix ) - 1 ) === '.' . $suffix ) {
				return $name;
			}
		}
		return '';
	}

	/** Recognition data from includes/services.json.
	 * @param string $key "services" or "aliases".
	 * @return array
	 */
	private static function data( $key ) {
		static $data = null;
		if ( null === $data ) {
			$data = wp_json_file_decode( __DIR__ . '/services.json', array( 'associative' => true ) );
			$data = is_array( $data ) ? $data : array();
		}
		return isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? $data[ $key ] : array();
	}

	/** Readable names for component keys.
	 * @return array Key => name.
	 */
	private static function component_names() {
		$names = array( 'core' => __( 'WordPress core or code outside plugins and themes', 'external-request-inventory' ) );
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			$slug = false === strpos( $file, '/' ) ? basename( $file, '.php' ) : strtok( $file, '/' );
			/* translators: %s: plugin name. */
			$names[ 'plugin:' . $slug ] = sprintf( __( 'Plugin: %s', 'external-request-inventory' ), $data['Name'] );
		}
		foreach ( wp_get_themes() as $slug => $theme ) {
			/* translators: %s: theme name. */
			$names[ 'theme:' . $slug ] = sprintf( __( 'Theme: %s', 'external-request-inventory' ), $theme->get( 'Name' ) );
		}
		return $names;
	}

	/** Site-formatted date.
	 * @param int $time Timestamp.
	 * @return string
	 */
	private static function date( $time ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}
}
