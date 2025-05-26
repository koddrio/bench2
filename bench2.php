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

function _prepare_wordpress( $config ) {
	wp_suspend_cache_addition( true );

	$ops = [
		'theme' => '_prepare_wordpress__theme',
		'users' => '_prepare_wordpress__users',
		'posts' => '_prepare_wordpress__posts',
		'pages' => '_prepare_wordpress__pages',
		'media' => '_prepare_wordpress__media',
	];

	return _prepare( $ops, $config );
}

function _prepare_woocommerce( $config ) {
	wp_suspend_cache_addition( true );
	add_filter( 'pre_wp_mail', '__return_true' );

	$ops = [
		'users' => '_prepare_wordpress__users',
		'posts' => '_prepare_wordpress__posts',
		'pages' => '_prepare_wordpress__pages',
		'media' => '_prepare_wordpress__media',

		'plugin'   => '_prepare_woocommerce__plugin',
		'config'   => '_prepare_woocommerce__config',
		'theme'    => '_prepare_woocommerce__theme',
		'products' => '_prepare_woocommerce__products',
		'orders'   => '_prepare_woocommerce__orders',
	];

	return _prepare( $ops, $config );
}

function _prepare_misc( $config ) {
	return _prepare_wordpress( $config );
}

function _prepare_learndash( $config ) {
	wp_suspend_cache_addition( true );

	$ops = [
		'users' => '_prepare_wordpress__users',
		'posts' => '_prepare_wordpress__posts',
		'pages' => '_prepare_wordpress__pages',
		'media' => '_prepare_wordpress__media',

		'plugin'  => '_prepare_learndash__plugin',
		'config'  => '_prepare_learndash__config',
		'courses' => '_prepare_learndash__courses',
	];

	return _prepare( $ops, $config );
}

function _prepare_wordpress__theme( $config ) {
	$theme = wp_get_theme( 'twentytwentyfive' );
	if ( ! $theme->exists() ) {
		return new WP_Error( 'theme-not-found' );
	}

	switch_theme( $theme->get_stylesheet() );
}

function _prepare_wordpress__users( $config ) {
	return _chunk( $config, $config['users'], function( $i ) use ( $config ) {
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

		return $username;
	} );
}

function _prepare_wordpress__posts( $config ) {
	return _chunk( $config, $config['posts'], function( $i ) {
		$prefix = substr( md5( $i ), 0, 8 );
		wp_insert_post( [
			'post_type' => 'post',
			'post_status' => 'publish',
			'post_title' => $prefix . ': A Bench2 Test Post',	
			'post_content' => _lorem( $i ),
		] );
	} );
}

function _prepare_wordpress__pages( $config ) {
	return _chunk( $config, $config['pages'], function( $i ) {
		$prefix = substr( md5( $i ), 0, 8 );
		wp_insert_post( [
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_title' => $prefix . ': A Bench2 Test Page',
			'post_content' => _lorem( $i ),
		] );
	} );
}

function _prepare_wordpress__media( $config ) {
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

	return _chunk( $config, $config['media'], function( $i ) {
		$prefix = substr( md5( $i ), 0, 8 );
		$filename = (string) ( $i % 10 ) . '.jpg';
		copy( __DIR__ . '/assets/' . $filename, sys_get_temp_dir() . '/' . $filename );
		media_handle_sideload( [
			'name' => $prefix . '.media.' . $i . '.jpg',
			'tmp_name' => sys_get_temp_dir() . '/' . $filename,
		] );
	}, $size=20 );
}

function _prepare_woocommerce__plugin( $config ) {
	if ( is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}

	$activate = [
		'woocommerce/woocommerce.php',
	];

	activate_plugins( $activate, '', false, true );
}

function _prepare_woocommerce__config( $config ) {
	update_option( 'woocommerce_onboarding_profile', [
		'skipped' => true,
	] );

	update_option( 'woocommerce_coming_soon', 'no' );
	update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
	update_option( 'woocommerce_allow_tracking', 'no' );
	update_option( 'woocommerce_task_list_hidden', 'yes' );
	update_option( 'woocommerce_task_list_complete', 'yes' );
	update_option( 'woocommerce_task_list_welcome_modal_dismissed', 'yes' );

	// Cash on delivery settings.
	update_option( 'woocommerce_cod_settings', [
		'enabled'            => 'yes',
		'title'              => 'Cash on delivery',
		'description'        => 'Pay with cash upon delivery',
		'instructions'       => 'Pay with cash upon delivery',
		'enable_for_methods' => [],
		'enable_for_virtual' => 'yes',
	] );

	\WC_Install::create_pages();

	$menu_id = wp_create_nav_menu( 'primary' );

	wp_update_nav_menu_item( $menu_id, 0, [
		'menu-item-title' => 'Home',
		'menu-item-url' => home_url( '/' ),
		'menu-item-status' => 'publish',
	] );

	wp_update_nav_menu_item( $menu_id, 0, [
		'menu-item-title' => 'Shop',
		'menu-item-object' => 'page',
		'menu-item-type' => 'post_type',
		'menu-item-object-id' => get_option( 'woocommerce_shop_page_id' ),
		'menu-item-status' => 'publish',
	] );

	wp_update_nav_menu_item( $menu_id, 0, [
		'menu-item-title' => 'Account',
		'menu-item-object' => 'page',
		'menu-item-type' => 'post_type',
		'menu-item-object-id' => get_option( 'woocommerce_myaccount_page_id' ),
		'menu-item-status' => 'publish',
	] );

	$locations = get_theme_mod( 'nav_menu_locations' );
	$locations['primary'] = $menu_id;
	set_theme_mod( 'nav_menu_locations', $locations );
}

function _prepare_woocommerce__theme( $config ) {
	$theme = wp_get_theme( 'storefront' );
	if ( ! $theme->exists() ) {
		return new WP_Error( 'theme-not-found' );
	}

	switch_theme( $theme->get_stylesheet() );
}

function _prepare_woocommerce__products( $config ) {
	$images = get_posts( [
		'fields'         => 'ids',
		'post_type'      => 'attachment',
		'posts_per_page' => 10,
	] );

	return _chunk( $config, $config['products'], function( $i ) use ( $images ) {
		$prefix = substr( md5( $i ), 0, 8 );
		$product = new \WC_Product_Simple();
		$product->set_name( $prefix . ' bench2 item' );
		$product->set_slug( $prefix . '-bench2-item' );
		$product->set_regular_price( (float) 249.00 % $i + 1.0 );
		$product->set_description( _lorem( $i ) );
		$product->set_short_description( _lorem( $i ) );

		if ( $images ) {
			$product->set_image_id( $images[ $i % count( $images ) ] );
		}

		$product->save();
	} );
}

function _prepare_woocommerce__orders( $config ) {
	global $wpdb;

	$user_ids = $wpdb->get_col( "SELECT ID FROM $wpdb->users
		WHERE user_email LIKE '%@bench2.com'" );

	$product_ids = get_posts( [
		'fields'         => 'ids',
		'post_type'      => 'product',
		'posts_per_page' => 100,
	] );

	return _chunk( $config, $config['orders'], function( $i ) use ( $user_ids, $product_ids ) {
		$order = wc_create_order();
		$order->set_customer_id( (int) $user_ids[ $i % count( $user_ids ) ] );
		$order->add_product( wc_get_product( $product_ids[ $i % count( $product_ids ) ] ) );
		$order->calculate_totals();
		$order->set_status( 'wc-completed' );
		$order->save();
	}, $size=20 );
}

function _prepare_learndash__plugin( $config ) {
	if ( is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}

	$activate = [
		'sfwd-lms/sfwd_lms.php',
	];

	activate_plugins( $activate, '', false, true );
}

function _prepare_learndash__config( $config ) {
	update_option( 'stellarwp_telemetry_learndash_show_optin', '0' );
}

function _prepare_learndash__courses( $config ) {
	return _chunk( $config, $config['courses'], function( $i ) {
		$prefix = substr( md5( $i ), 0, 8 );
		$course_id = wp_insert_post( [
			'post_title'   => $prefix . ' bench2 course',
			'post_content' => _lorem( $i ),
			'post_type'    => 'sfwd-courses',
			'post_status'  => 'publish',
		] );

		update_post_meta( $course_id, '_sfwd-courses', [
			'course_price_type' => 'free',
		] );

		for ( $j = 1; $j <= 10; $j++ ) {
			$lesson_prefix = substr( md5( "lesson:{$i}:{$j}" ), 0, 8 );
			$lesson_id = wp_insert_post( [
				'post_title'   => $lesson_prefix . ' bench2 lesson',
				'post_content' => _lorem( $i + $j ),
				'post_type'    => 'sfwd-lessons',
				'post_status'  => 'publish',
			] );

			update_post_meta( $lesson_id, 'course_id', $course_id );
		}

		for ( $k = 1; $k <= 2; $k++ ) {
			$quiz_prefix = substr( md5( "quiz:{$i}:{$k}" ), 0, 8 );
			$quiz_id = wp_insert_post( [
				'post_title'   => $quiz_prefix . ' bench2 quiz',
				'post_content' => _lorem( $i + $k ),
				'post_type'    => 'sfwd-quiz',
				'post_status'  => 'publish',
			] );

			update_post_meta( $quiz_id, 'course_id', $course_id );
			update_post_meta( $quiz_id, 'ld_course_' . $course_id, $course_id );

			add_filter( 'map_meta_cap', function( $caps, $cap, $user_id, $args ) {
				error_log( $cap );
				if ( $cap === 'wpProQuiz_edit_quiz' ) {
					return [ 'exist' ];
				}

				if ( $cap === 'wpProQuiz_add_quiz' ) {
					return [ 'exist' ];
				}

				return $caps;
			}, 10, 4 );

			$pro_quiz = new \WpProQuiz_Controller_Quiz();
			$pro_quiz->route(
				[
					'action'  => 'addUpdateQuiz',
					'quizId'  => 0,
					'post_id' => $quiz_id,
				],
				[
					'form'    => [],
					'post_ID' => $quiz_id,
				]
			);

			// $quiz_pro_id        = \learndash_get_quiz_pro_id( $quiz_id );
			$question_mapper    = new \WpProQuiz_Model_QuestionMapper();
			$quiz_questions_map = [];

			for ( $l = 1; $l <= 10; $l++ ) {
				$question_prefix = substr( md5( "lesson:{$i}:{$k}:{$l}" ), 0, 8 );	
				$question_args = [
					'action'       => 'new_step',
					'post_title'   => $question_prefix . ' bench2 question',
					'post_content' => _lorem( $i + $k + $l ),
					'post_type'    => 'sfwd-question',
					'post_status'  => 'publish',
				];

				$question_id     = wp_insert_post( $question_args );
				$question_pro_id = learndash_update_pro_question( 0, $question_args );

				update_post_meta( $question_id, 'quiz_id', $quiz_id ); 
				update_post_meta( $question_id, 'question_pro_id', absint( $question_pro_id ) );
				learndash_proquiz_sync_question_fields( $question_id, $question_pro_id );
				learndash_update_setting( $question_id, 'quiz', $quiz_id );

				$answers = [];
				for ( $m = 1; $m <= 5; $m++ ) {
					$answer_prefix = substr( md5( "answer:{$i}:{$k}:{$l}:{$m}" ), 0, 8 );	
					$correct_str   = ( $m === 1 ) ? 'correct' : 'incorrect';

					$answers[] = [
						'_answer'             => $answer_prefix . ' bench2 answer ' . $correct_str,
						'_correct'            => $m === 1,
						'_graded'             => '1',
						'_gradedType'         => 'text',
						'_gradingProgression' => 'not-graded-none',
						'_html'               => false,
						'_points'             => 1,
						'_sortString'         => '',
						'_sortStringHtml'     => false,
						'_type'               => 'answer',
					];
				}

				$question_model = $question_mapper->fetch( $question_pro_id );
				$question_model->set_array_to_object( [
					'_answerData' => $answers,
					'_answerType' => 'single',
					'_question'   => $question_prefix . ' bench2 question',
				] );

				$question_mapper->save( $question_model );
				$quiz_questions_map[ $question_id ] = $question_pro_id;
			}

			update_post_meta( $quiz_id, 'ld_quiz_questions', $quiz_questions_map );
		}
	}, $size=1 );
}

function _prepare_finalize( $config ) {
	update_option( 'bench2_status', 'ready' );
	wp_cache_flush();
	// TODO Maybe warm caches
}

function _prepare( $ops, $config ) {
	// Skip potentially empty ops.
	foreach ( [
		'posts',
		'pages',
		'media',
		'users',
		'products',
		'orders',
		'courses',
	] as $key ) {
		if ( empty( $config[ $key ] ) ) {
			unset( $ops[ $key ] );
		}
	}

	if ( ! empty( $config['op'] ) ) {
		$op = $config['op'];
	}

	if ( $op === 'hello' ) {
		return [ 'next_op' => array_key_first( $ops ) ];
	}

	if ( ! array_key_exists( $op, $ops ) ) {
		return new WP_Error( 'wrong-op' );
	}

	reset( $ops );
	while ( $callback = current( $ops ) ) {
		$_op = key( $ops );
		next( $ops );
		$next_op = key( $ops );

		if ( $_op !== $op ) {
			continue;
		}

		$func = __NAMESPACE__ . '\\' . $callback;

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

		// Allow op re-runs/chunks.
		if ( is_array( $r ) ) {
			if ( empty( $r['next_op'] ) ) {
				$r['next_op'] = $next_op;
			}

			return $r;
		}

		if ( $next_op ) {
			return [ 'next_op' => $next_op ];
		}
	}
}

function _chunk( $config, $num, $callback, $size = 100 ) {
	global $wpdb;

	$start = 1;

	if ( ! empty( $config['op_args'] ) ) {
		$start = intval( $config['op_args'] );
	}

	$end = min( $start + $size -1, $num );
	$data = [];

	for ( $i = $start; $i <= $end; $i++ ) {
		$r = $callback( $i );
		if ( $r ) {
			$data[] = $r;
		}

		if ( $i % 20 == 0 ) {
			$wpdb->queries = [];
			wp_cache_flush_runtime();
		}
	}

	$retval = [ 'op_data' => $data ];

	// Chunk is not finished, re-run this op.
	if ( $end < $num ) {
		$retval['op_args'] = $end + 1;
		$retval['next_op'] = $config['op'];
	}

	return $retval;
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
		'sfwd-lms/sfwd_lms.php',
	];

	deactivate_plugins( $deactivate, true );
	return true;
}

function _clean_data() {
	global $wpdb;
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
		wp_cache_flush_runtime();
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

	$tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}wc_%'" );
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE {$table}" );
	}

	$tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}woocommerce_%'" );
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE {$table}" );
	}

	$tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}actionscheduler_%'" );
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE {$table}" );
	}

	$tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}learndash_%'" );
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE {$table}" );
	}

	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'woocommerce\_%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'wc\_%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'widget\_woocommerce\_%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'widget\_wc\_%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '%ActionScheduler%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'action\_scheduler\_%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'jetpack%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '%supports\_woocommerce'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'wp\_1\_wc\_%'" );

	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '\_transient\_%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '\_site\_transient\_%'" );

	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'storefront\_%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name = 'theme_mods_storefront'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name = 'product_cat_children'" );

	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'wpProQuiz\_%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'learndash%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'widget_sfwd-%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'widget_ld%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'stellarwp%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'ld-%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'ld\_%'" );

	$crons = _get_cron_array();
	foreach ( $crons as $time => $hooks ) {
		foreach ( $hooks as $hook => $events ) {
			foreach ( $events as $event ) {
				$args = [];
				if ( ! empty( $event['args'] ) ) {
					$args = $event['args'];
				}

				if ( substr( $hook, 0, 11 ) == 'woocommerce'
					|| substr( $hook, 0, 3 ) == 'wc_'
					|| $hook == 'generate_category_lookup_table'
					|| substr( $hook, 0, 8 ) == 'wp_1_wc_'
					|| substr( $hook, 0, 7 ) == 'jetpack'
					|| substr( $hook, 0, 16 ) == 'action_scheduler'
				) {
					wp_clear_scheduled_hook( $hook, $args );
					continue;
				}
			}
		}
	}

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
