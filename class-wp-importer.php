<?php
/*
Plugin Name: class-wp-importer
Plugin URI: http://wordpress.org/extend/plugins/class-wp-importer/
Description: Shared base class for importer plugins.
Author: Automattic, Brian Colinger
Author URI: http://automattic.com/
Version: 0.3
Stable tag: 0.3
License: GPL v2 - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

if ( !class_exists( 'WP_Http' ) ) {
	// Load WP_Http class
	require_once ABSPATH . 'wp-includes/http.php';
}

/**
 * WP_Importer base class
 * @author Brian Colinger
 */
class WP_Importer {
	/**
	 * Class Constructor
	 *
	 * @return void
	 */
	public function __construct() {
	}

	/**
	 * Toggle $wpdb to read from MySql masters or slaves
	 *
	 * @param bool $flag
	 * @return void
	 */
	public function read_from_masters($flag = true) {
		global $wpdb;

		if ($flag === true) {
			// Read from masters
			$wpdb->send_reads_to_masters ();
		} else {
			// Read from slaves
			$wpdb->srtm = array ();
		}
	}

	/**
	 * Returns array with imported permalinks from WordPress database
	 *
	 * @param string $bid
	 * @param bool $read_from_master
	 * @return array
	 */
	public function get_imported_posts($importer_name, $bid, $read_from_master = false) {
		global $wpdb;

		if ($read_from_master === true) {
			// Set $wpdb to read from masters
			$this->read_from_masters ( true );
		}

		$hashtable = array ();

		$limit = 100;
		$offset = 0;

		// Grab all posts in chunks
		do {
			$meta_key = $importer_name . '_' . $bid . '_permalink';
			$sql = $wpdb->prepare ( "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '%s' LIMIT %d,%d", $meta_key, $offset, $limit );
			$results = $wpdb->get_results ( $sql );

			// Increment offset
			$offset = ($limit + $offset);

			if (! empty ( $results )) {
				foreach ( $results as $r ) {
					// Set permalinks into array
					$hashtable [$r->meta_value] = intval ( $r->post_id );
				}
			}
		} while ( count ( $results ) == $limit );

		// unset to save memory
		unset ( $results, $r );

		if ($read_from_master === true) {
			// Set $wpdb to read from slaves
			$this->read_from_masters ( false );
		}

		return $hashtable;
	}

	/**
	 * Return count of imported permalinks from WordPress database
	 *
	 * @param string $bid
	 * @param bool $read_from_master
	 * @return int
	 */
	public function count_imported_posts($importer_name, $bid, $read_from_master = false) {
		global $wpdb;

		if ($read_from_master === true) {
			// Set $wpdb to read from masters
			$this->read_from_masters ( true );
		}

		$count = 0;

		// Get count of permalinks
		$meta_key = $importer_name . '_' . $bid . '_permalink';
		$sql = $wpdb->prepare ( "SELECT COUNT( post_id ) AS cnt FROM $wpdb->postmeta WHERE meta_key = '%s'", $meta_key );

		$result = $wpdb->get_results ( $sql );

		if (! empty ( $result ))
			$count = intval ( $result [0]->cnt );

		// unset to save memory
		unset ( $results );

		if ($read_from_master === true) {
			// Set $wpdb to read from slaves
			$this->read_from_masters ( false );
		}

		return $count;
	}

	/**
	 * Set array with imported comments from WordPress database
	 *
	 * @param string $bid
	 * @param bool $read_from_master
	 * @return array
	 */
	function get_imported_comments($bid, $read_from_master = false) {
		global $wpdb;

		if ($read_from_master === true) {
			// Set $wpdb to read from masters
			$this->read_from_masters ( true );
		}

		$hashtable = array ();

		$limit = 100;
		$offset = 0;

		// Grab all comments in chunks
		do {
			$sql = $wpdb->prepare ( "SELECT comment_ID, comment_agent FROM $wpdb->comments LIMIT %d,%d", $offset, $limit );
			$results = $wpdb->get_results ( $sql );

			// Increment offset
			$offset = ($limit + $offset);

			if (! empty ( $results )) {
				foreach ( $results as $r ) {
					// Explode comment_agent key
					list ( $ca_bid, $source_comment_id ) = explode ( '-', $r->comment_agent );
					$source_comment_id = intval ( $source_comment_id );

					// Check if this comment came from this blog
					if ($bid == $ca_bid) {
						$hashtable [$source_comment_id] = intval ( $r->comment_ID );
					}
				}
			}
		} while ( count ( $results ) == $limit );

		// unset to save memory
		unset ( $results, $r );

		if ($read_from_master === true) {
			// Set $wpdb to read from slaves
			$this->read_from_masters ( false );
		}

		return $hashtable;
	}

	public function set_blog( $blog_id ) {
		if (is_numeric( $blog_id )) {
			$blog_id = (int) $blog_id;
		} else {
			$blog = 'http://' . preg_replace( '#^https?://#', '', $blog_id );
			if ( ( !$parsed = parse_url( $blog )) || empty( $parsed ['host'] ) ) {
				fwrite( STDERR, "Error: can not determine blog_id from $blog_id\n" );
				exit();
			}
			if ( empty( $parsed ['path'] ) )
				$parsed ['path'] = '/';
			if ( !$blog = get_blog_info( $parsed ['host'], $parsed ['path'] ) ) {
				fwrite( STDERR, "Error: Could not find blog\n" );
				exit();
			}
			$blog_id = (int) $blog->blog_id;
			// Restore global $current_blog
			global $current_blog;
			$current_blog = $blog;
		}

		if ( function_exists( 'is_multisite' ) ) {
			if ( is_multisite() )
				switch_to_blog( $blog_id );
		}

		return $blog_id;
	}

	public function set_user( $user_id ) {
		if (is_numeric( $user_id )) {
			$user_id = (int) $user_id;
		} else {
			$user_id = (int) username_exists( $user_id );
		}

		if ( !$user_id || !wp_set_current_user( $user_id ) ) {
			fwrite( STDERR, "Error: can not find user\n" );
			exit();
		}

		return $user_id;
	}

	/**
	 * Sort by strlen, longest string first
	 *
	 * @param string $a
	 * @param string $b
	 * @return int
	 */
	public function cmpr_strlen( $a, $b ) {
		return strlen( $b ) - strlen( $a );
	}

	/**
	 * GET URL
	 *
	 * @param string $url
	 * @param string $username
	 * @param string $password
	 * @param bool $head
	 * @return array
	 */
	public function get_page( $url, $username = '', $password = '', $head = false ) {
		// Increase the timeout
		add_filter( 'http_request_timeout', array( &$this, 'bump_request_timeout' ) );

		$headers = array();
		$args = array();
		if ( true === $head )
			$args['method'] = 'HEAD';
		if ( !empty( $username ) && !empty( $password ) )
			$headers['Authorization'] = 'Basic ' . base64_encode( "$username:$password" );

		$args['headers'] = $headers;

		$request = new WP_Http();
		return $request->request( $url , $args );
	}

	/**
	 * Bump up the request timeout for http requests
	 *
	 * @param int $val
	 * @return int
	 */
	public function bump_request_timeout( $val ) {
		return 60;
	}

	/**
	 * Check if user has exceeded disk quota
	 *
	 * @return bool
	 */
	public function is_user_over_quota() {
		global $current_user, $current_blog;

		if( function_exists( 'upload_is_user_over_quota' ) ) {
			if( upload_is_user_over_quota( 1 ) ) {
				echo "Sorry, you have used your upload quota.\n";
				$blog_url = 'http://' . $current_blog->domain . $current_blog->path . 'wp-admin/paid-upgrades.php';
				$msg = '';
				$msg .= "Howdy,\n\r";
				$msg .= "You have used up all of your available upload space. This means we can not import any more attachments.\n\r";
				$msg .= "You can purchase a space upgrade here: $blog_url \n\r";
/*
				$msg .= "If you purchase a space upgrade, we will automatically continue importing your attachments\n\r";
				add_option( 'over_quota_resume', true );
*/
				wp_mail( $current_user->user_email, __( '[WordPress.com] Upload space limit reached' ), $msg );
				return true;
			}
		}

		return false;
	}
}

/**
 * Wrapper for print_r() with additional line break
 *
 * @param string $array
 */
function afdebug($array) {
	if (isset ( $_SERVER ['SERVER_NAME'] )) {
		echo '<pre style="text-align:left; font-size:12px; line-height:16px;">' . "\n";
	}

	print_r ( $array );
	echo "\n";

	if (isset ( $_SERVER ['SERVER_NAME'] )) {
		echo '</pre>' . "\n";
	}
}

/**
 * Wrapper for var_dump() with additional line break
 *
 * @param string $array
 */
function afvardump($array) {
	if (isset ( $_SERVER ['SERVER_NAME'] )) {
		echo '<pre style="text-align:left; font-size:12px; line-height:16px;">' . "\n";
	}

	var_dump ( $array );
	echo "\n";

	if (isset ( $_SERVER ['SERVER_NAME'] )) {
		echo '</pre>' . "\n";
	}
}

/**
 * Returns value of command line params.
 * Exits when a required param is not set.
 *
 * @param string $strParam
 * @param bool $blnRequired
 * @return mixed
 */
function get_args($strParam, $blnRequired = false) {
	$args = $_SERVER ['argv'];

	$out = array ();

	$last_arg = null;
	$return = null;

	$il = sizeof ( $args );

	for($i = 1, $il; $i < $il; $i ++) {
		if (( bool ) preg_match ( "/^--(.+)/", $args [$i], $match )) {
			$parts = explode ( "=", $match [1] );
			$key = preg_replace ( "/[^a-z0-9]+/", "", $parts [0] );

			if (isset ( $parts [1] )) {
				$out [$key] = $parts [1];
			} else {
				$out [$key] = true;
			}

			$last_arg = $key;
		} else if (( bool ) preg_match ( "/^-([a-zA-Z0-9]+)/", $args [$i], $match )) {
			for($j = 0, $jl = strlen ( $match [1] ); $j < $jl; $j ++) {
				$key = $match [1] {$j};
				$out [$key] = true;
			}

			$last_arg = $key;
		} else if ($last_arg !== null) {
			$out [$last_arg] = $args [$i];
		}
	}

	// Check array for specified param
	if (isset ( $out [$strParam] )) {
		// Set return value
		$return = $out [$strParam];
	}

	// Check for missing required param
	if (! isset ( $out [$strParam] ) && $blnRequired) {
		// Display message and exit
		echo "\"$strParam\" parameter is required but was not specified\n";
		exit ();
	}

	return $return;
}

/**
 * Replace multiple spaces with a single space
 *
 * @param string $string
 * @return string
 */
function min_whitespace( $string ) {
	return preg_replace( '|\s+|', ' ', $string );
}

/**
 * Reset global variables that grow out of control
 *
 * @return void
 */
function stop_the_insanity() {
	global $wpdb, $wp_actions;

	// Or define( 'WP_IMPORTING', true );
	$wpdb->queries = array();
	// Reset $wp_actions to keep it from growing out of control
	$wp_actions = array();
}
