<?php
/**
 * Plugin Name: Bench2
 */

namespace bench2;

use WP_REST_Server;
use WP_REST_Request;
use WP_Query;
use WP_Theme;
use WP_Error;

function rest_api_init() {
	register_rest_route( 'bench2/1.0', '/hello', [
		'callback' => __NAMESPACE__ . '\hello',
	] );

	register_rest_route( 'bench2/1.0', '/specs', [
		'callback' => __NAMESPACE__ . '\specs',
		'permission_callback' => __NAMESPACE__ . '\auth',
	] );

	register_rest_route( 'bench2/1.0', '/clean', [
		'callback' => __NAMESPACE__ . '\clean',
		'permission_callback' => __NAMESPACE__ . '\auth',
		'methods' => WP_REST_Server::CREATABLE,
	] );

	register_rest_route( 'bench2/1.0', '/prepare', [
		'callback' => __NAMESPACE__ . '\prepare',
		'permission_callback' => __NAMESPACE__ . '\auth',
		'methods' => WP_REST_Server::CREATABLE,
	] );

	register_rest_route( 'bench2/1.0', '/status', [
		'callback' => __NAMESPACE__ . '\status',
		'permission_callback' => __NAMESPACE__ . '\auth',
		'methods' => WP_REST_Server::CREATABLE,
	] );

}
add_action( 'rest_api_init', __NAMESPACE__ . '\rest_api_init' );

function hello() {
	return 'hi';
}

function auth() {
	if ( ! defined( 'BENCH2_KEY' ) || empty( BENCH2_KEY ) ) {
		return false;
	}

	if ( empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
		return false;
	}

	$auth = explode( ' ', $_SERVER['HTTP_AUTHORIZATION'] );
	if ( empty( $auth[0] ) || empty( $auth[1] ) ) {
		return false;
	}

	if ( strtolower( trim( $auth[0] ) ) !== 'bearer' ) {
		return false;
	}

	if ( hash_equals( BENCH2_KEY, trim( $auth[1] ) ) ) {
		return true;
	}

	return false;
}

function status() {
	return get_option( 'bench2_status' );
}

function specs() {
	global $wpdb, $wp_version;

	if ( is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}

	$data = [
		'php_version' => PHP_VERSION,
		'php_sapi_name' => _call( 'php_sapi_name' ),
		// 'php_extensions' => _call( 'get_loaded_extensions' ),
		'zend_version' => _call( 'zend_version' ),
		'php_memory_limit' => _call( 'ini_get', 'memory_limit' ),
		'php_opcache_enabled' => (bool) _call( 'ini_get', 'opcache.enable' ),
		'php_max_execution_time' => (int) _call( 'ini_get', 'max_execution_time' ),
		'mysql_version' => $wpdb->get_var( "SHOW VARIABLES LIKE 'version'", 1 ),
		'wp_version' => $wp_version,
		'object_cache' => (bool) wp_using_ext_object_cache(),
		'active_plugins' => (array) get_option( 'active_plugins' ),
		'mu_plugins' => array_keys( (array) _call( 'get_mu_plugins' ) ),
	];

	return $data;
}

function prepare( WP_REST_Request $request ) {
	$context = $request->get_param( 'context' );
	$config = $request->get_param( 'config' );

	if ( ! in_array( $context, [
		'wordpress',
		'woocommerce',
		'learndash',
		'misc',
		'finalize',
	] ) ) {
		return new WP_Error( 'invalid' );
	}

	$func = __NAMESPACE__ . '\_prepare_' . $context;

	if ( ! is_callable( $func ) ) {
		return new WP_Error( 'invalid' );
	}

	if ( get_option( 'bench2_status' ) !== 'clean' ) {
		return new WP_Error( 'wrong-status' );
	}

	return $func( $config );
}

function _prepare_misc( $config ) {
	return _prepare_wordpress( $config );
}

function _prepare_wordpress( $config ) {
	wp_suspend_cache_addition();

	$ops = [
		'theme',
	];

	foreach ( [
		'users',
		'posts',
		'pages',
		'media',
	] as $op ) {
		if ( ! empty( $config[ $op ] ) ) {
			$ops[] = $op;
		}
	}

	$op = 'theme';
	if ( ! empty( $config['op'] ) ) {
		$op = $config['op'];
	}

	if ( ! in_array( $op, $ops ) ) {
		return new WP_Error( 'wrong-op' );
	}

	reset( $ops );
	while ( $_op = current( $ops ) ) {
		$next_op = next( $ops );

		if ( $_op !== $op ) {
			continue;
		}

		$func = __NAMESPACE__ . '\_prepare_wordpress__' . $op;

		if ( ! is_callable( $func ) ) {
			return new WP_Error( 'invalid' );
		}

		$r = $func( $config );
		if ( is_wp_error( $r ) ) {
			return $r;
		}

		if ( is_bool( $r ) && ! $r ) {
			return $r;
		}

		// Allow op re-runs/cunks.
		if ( is_array( $r ) ) {
			return $r;
		}

		if ( $next_op ) {
			return [ 'next_op' => $next_op ];
		}
	}
}

function _prepare_wordpress__theme( $config ) {
	$theme = wp_get_theme( 'twentytwentyfive' );
	if ( ! $theme->exists() ) {
		return new WP_Error( 'theme-not-found' );
	}

	switch_theme( $theme->get_stylesheet() );
}

function _prepare_wordpress__users( $config ) {
	global $wpdb, $wp_object_cache;

	$start = 1;
	$chunk = 100;
	$created = [];

	if ( ! empty( $config['op_args'] ) ) {
		if ( $config['op_args'] === 'done' ) {
			return;
		}

		$start = intval( $config['op_args'] );
	}

	$end = min( $start + $chunk -1, $config['users'] );

	for ( $i = $start; $i <= $end; $i++ ) {
		$prefix = substr( md5( $i ), 0, 8 );
		$username = $prefix . '.bench2.' . $i;

		wp_insert_user( [
			'user_login'   => $username,
			'user_pass'    => $config['password'],
			'user_email'   => $prefix . '@bench2.com',
			'first_name'   => 'Benchino',
			'last_name'    => 'Refresher',
			'display_name' => 'Benchino Refresher',
			'role'         => $config['role'],
		] );

		$created[] = $username;

		if ( $i % 100 == 0 ) {
			$wpdb->queries = [];
			$wp_object_cache->cache = [];
		}
	}

	if ( $end < $config['users'] ) {
		return [ 'next_op' => 'users', 'op_args' => $end + 1, 'created' => $created ];
	}

	return [ 'next_op' => 'users', 'op_args' => 'done', 'created' => $created ];
}

function _prepare_wordpress__posts( $config ) {
	global $wpdb, $wp_object_cache;

	$start = 1;
	$chunk = 100;

	if ( ! empty( $config['op_args'] ) ) {
		$start = intval( $config['op_args'] );
	}

	$end = min( $start + $chunk -1, $config['posts'] );

	for ( $i = $start; $i <= $end; $i++ ) {
		$prefix = substr( md5( $i ), 0, 8 );

		wp_insert_post( [
			'post_type' => 'post',
			'post_status' => 'publish',
			'post_title' => $prefix . ': A Bench2 Test Post',	
			'post_content' => _lorem( $i ),
		] );

		if ( $i % 100 == 0 ) {
			$wpdb->queries = [];
			$wp_object_cache->cache = [];
		}
	}

	if ( $end < $config['posts'] ) {
		return [ 'next_op' => 'posts', 'op_args' => $end + 1 ];
	}
}

function _prepare_wordpress__pages( $config ) {
	global $wpdb, $wp_object_cache;

	$start = 1;
	$chunk = 100;

	if ( ! empty( $config['op_args'] ) ) {
		$start = intval( $config['op_args'] );
	}

	$end = min( $start + $chunk -1, $config['pages'] );

	for ( $i = $start; $i <= $end; $i++ ) {
		$prefix = substr( md5( $i ), 0, 8 );

		wp_insert_post( [
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_title' => $prefix . ': A Bench2 Test Page',
			'post_content' => _lorem( $i ),
		] );

		if ( $i % 100 == 0 ) {
			$wpdb->queries = [];
			$wp_object_cache->cache = [];
		}
	}

	if ( $end < $config['pages'] ) {
		return [ 'next_op' => 'pages', 'op_args' => $end + 1 ];
	}
}

function _prepare_wordpress__media( $config ) {
	global $wpdb, $wp_object_cache;

	$start = 1;
	$chunk = 20;

	if ( ! empty( $config['op_args'] ) ) {
		$start = intval( $config['op_args'] );
	}

	$end = min( $start + $chunk -1, $config['media'] );

	$include = [
		ABSPATH . 'wp-admin/includes/media.php',
		ABSPATH . 'wp-admin/includes/file.php',
		ABSPATH . 'wp-admin/includes/image.php',
	];

	foreach ( $include as $filename ) {
		if ( is_readable( $filename ) ) {
			include_once( $filename );
		}
	}

	$upload_dir = wp_upload_dir();
	wp_mkdir_p( $upload_dir['path'] );

	for ( $i = $start; $i <= $end; $i++ ) {
		$prefix = substr( md5( $i ), 0, 8 );
		$filename = (string) ( $i % 10 ) . '.jpg';
		copy( __DIR__ . '/assets/' . $filename, sys_get_temp_dir() . '/' . $filename );
		media_handle_sideload( [
			'name' => $prefix . '.media.' . $i . '.jpg',
			'tmp_name' => sys_get_temp_dir() . '/' . $filename,
		] );
	}

	if ( $end < $config['media'] ) {
		return [ 'next_op' => 'media', 'op_args' => $end + 1 ];
	}
}

function _prepare_woocommerce( $config ) {
	$theme = wp_get_theme( 'storefront' );
	if ( ! $theme->exists() ) {
		return new WP_Error( 'theme-not-found' );
	}

	switch_theme( $theme->get_stylesheet() );
	return true;
}

function _prepare_finalize( $config ) {
	update_option( 'bench2_status', 'ready' );
	wp_cache_flush();
	// TODO Maybe warm caches
	return true;
}

function clean( $request ) {
	$context = $request->get_param( 'context' );

	if ( ! in_array( $context, [
		'options',
		'themes',
		'plugins',
		'data',
		'finalize',
	] ) ) {
		return new WP_Error( 'invalid' );
	}

	$func = __NAMESPACE__ . '\_clean_' . $context;

	if ( ! is_callable( $func ) ) {
		return new WP_Error( 'invalid' );
	}

	return $func();
}

function _clean_options() {
	global $wp_rewrite;
	$wp_rewrite->set_permalink_structure( '/%postname%/' ); 
	flush_rewrite_rules();

	update_option( 'blogname', 'WordPress' );
	update_option( 'blogdescription', 'Just another WordPress blog' );
	return true;
}

function _clean_themes() {
	$theme = WP_Theme::get_core_default_theme();
	switch_theme( $theme->get_stylesheet() );
	return true;
}

function _clean_plugins() {
	if ( is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}

	$deactivate = [
		'woocommerce/woocommerce.php',
	];

	deactivate_plugins( $deactivate, true );
	return true;
}

function _clean_data() {
	global $wpdb, $wp_object_cache;
	wp_suspend_cache_addition();

	while ( true ) {
		$query = new WP_Query( [
			'post_type' => 'attachment',
			'post_status' => 'any',
			'posts_per_page' => 100,
			'no_found_rows' => true,
			'fields' => 'ids',
		] );

		if ( count( $query->posts ) < 1 ) {
			break;
		}

		foreach ( $query->posts as $post_id ) {
			wp_delete_attachment( $post_id, true );
		}

		$wpdb->queries = [];
		$wp_object_cache->cache = [];
	}

	$wpdb->query( "TRUNCATE TABLE $wpdb->posts" );
	$wpdb->query( "TRUNCATE TABLE $wpdb->postmeta" );
	$wpdb->query( "TRUNCATE TABLE $wpdb->comments" );
	$wpdb->query( "TRUNCATE TABLE $wpdb->commentmeta" );
	$wpdb->query( "TRUNCATE TABLE $wpdb->links" );
	$wpdb->query( "TRUNCATE TABLE $wpdb->term_relationships" );
	$wpdb->query( "TRUNCATE TABLE $wpdb->term_taxonomy" );
	$wpdb->query( "TRUNCATE TABLE $wpdb->terms" );
	$wpdb->query( "TRUNCATE TABLE $wpdb->termmeta" );

	$wpdb->query( "DELETE FROM $wpdb->users WHERE user_email LIKE '%@bench2.com'" );
	$wpdb->query( "DELETE FROM $wpdb->usermeta WHERE user_ID NOT IN (SELECT ID FROM $wpdb->users)" );
	
	// TODO Delete any other options we've added.
	return true;
}

function _clean_finalize() {
	update_option( 'bench2_status', 'clean' );
	wp_cache_flush();
	return true;
}

function _call() {
	$args = func_get_args();
	$func = array_shift( $args );

	if ( ! function_exists( $func ) ) {
		return null;
	}

	return call_user_func_array( $func, $args );
}

function _lorem( $id ) {
	$lorem = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.';
	
	$offset = hexdec( substr( md5( $id ), 0, 4 ) ) % strlen( $lorem );
	return substr( $lorem, $offset ) . substr( $lorem, 0, $offset );
}
