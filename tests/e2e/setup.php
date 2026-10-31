<?php
/**
 * Prepares the E2E site: clean Gapban tables, a "pricing" page, the acceptance rule,
 * and an operator for the admin user. Run: WP_DB_NAME=wp php tests/e2e/setup.php
 */
$_SERVER['HTTP_HOST'] = 'localhost:8899';
require ( getenv( 'WP_PATH' ) ?: '/tmp/claude-0/wp' ) . '/wp-load.php';
use Gapban\Persistence\Db;
use Gapban\Persistence\Schema;
global $wpdb;
foreach ( Schema::TABLES as $t ) {
	$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( $t ) );
}
Db::insert( 'teams', array( 'name' => 'پشتیبانی', 'slug' => 'general', 'created_at' => Db::now() ) );
\Gapban\Core\Settings::update( array( 'widget_language' => 'fa', 'prechat_fields' => array( 'name' => 'optional', 'email' => 'optional', 'phone' => 'hidden', 'department' => 'hidden' ) ) );
$page = get_page_by_path( 'pricing' );
$id   = $page ? $page->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Pricing', 'post_name' => 'pricing', 'post_content' => 'Plans' ) );
$def  = \Gapban\Rules\RuleValidator::validate(
	array(
		'name'       => 'Pricing help',
		'conditions' => array( 'op' => 'and', 'items' => array( array( 'type' => 'page_id', 'value' => array( $id ) ), array( 'type' => 'chat_opened', 'value' => false ) ) ),
		'trigger'    => array( 'type' => 'time_on_page', 'seconds' => 15 ),
		'actions'    => array( array( 'type' => 'automated_message', 'text' => 'برای انتخاب پلن مناسب راهنمایی‌تان کنیم؟' ) ),
		'frequency'  => array( 'per_page' => 1, 'per_session' => 1, 'per_visitor' => 0, 'cooldown_hours' => 24, 'stop_after_dismiss' => true ),
	)
);
\Gapban\Rules\RuleEvents::save( $def );
$uid = (int) username_exists( 'admin' );
Db::insert( 'operators', array( 'wp_user_id' => $uid, 'display_name' => 'مریم', 'availability' => 'online', 'created_at' => Db::now() ) );
echo wp_json_encode( array( 'pricing' => get_permalink( $id ) ) );
