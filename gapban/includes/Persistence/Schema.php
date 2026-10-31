<?php
/**
 * Database schema and versioned migrations.
 *
 * @package Gapban
 */

namespace Gapban\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Owns every custom table. Table creation goes through dbDelta so that
 * columns and indexes added in later versions are applied idempotently;
 * data migrations that dbDelta cannot express are listed in migrations().
 */
final class Schema {

	public const OPTION = 'gapban_db_version';

	/**
	 * Logical table names (without prefix).
	 *
	 * @var string[]
	 */
	public const TABLES = array(
		'visitors',
		'sessions',
		'conversations',
		'participants',
		'messages',
		'attachments',
		'operators',
		'teams',
		'team_members',
		'provider_mappings',
		'inbound_updates',
		'jobs',
		'rules',
		'rule_events',
		'tags',
		'conversation_tags',
		'ratings',
		'audit_events',
		'enrollments',
		'rate_limits',
		'locks',
		'knowledge',
	);

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'gapban_' . $name;
	}

	/**
	 * Create or upgrade tables, then run pending data migrations.
	 */
	public static function migrate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$installed = (int) get_option( self::OPTION, 0 );
		if ( $installed >= GAPBAN_DB_VERSION ) {
			return;
		}

		foreach ( self::definitions() as $sql ) {
			dbDelta( $sql );
		}

		foreach ( self::migrations() as $version => $callback ) {
			if ( $installed < $version ) {
				$callback( $wpdb );
			}
		}

		update_option( self::OPTION, GAPBAN_DB_VERSION, false );
	}

	/**
	 * Data migrations keyed by the schema version that introduced them.
	 *
	 * @return array<int, callable>
	 */
	private static function migrations(): array {
		return array(
			2 => static function ( $wpdb ): void {
				// v2 introduced conversations.routing_snapshot; older rows get an explicit legacy snapshot.
				$table = self::table( 'conversations' );
				$wpdb->query( "UPDATE {$table} SET routing_snapshot = '{\"mode\":\"reply\",\"legacy\":true}' WHERE routing_snapshot IS NULL OR routing_snapshot = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			},
			3 => static function ( $wpdb ): void {
				// v3 adds the default "General" team when none exists.
				$table = self::table( 'teams' );
				$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( 0 === $count ) {
					$wpdb->insert(
						$table,
						array(
							'name'                => 'General',
							'slug'                => 'general',
							'assignment_strategy' => 'manual',
							'active'              => 1,
							'sort_order'          => 0,
							'created_at'          => gmdate( 'Y-m-d H:i:s' ),
						)
					);
				}
			},
		);
	}

	/**
	 * @return string[]
	 */
	public static function definitions(): array {
		global $wpdb;
		$c = $wpdb->get_charset_collate();
		$t = static fn( string $n ): string => self::table( $n );

		return array(
			"CREATE TABLE {$t('visitors')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_id char(26) NOT NULL,
  wp_user_id bigint(20) unsigned DEFAULT NULL,
  name varchar(120) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(32) NOT NULL DEFAULT '',
  locale varchar(16) NOT NULL DEFAULT '',
  blocked tinyint(1) NOT NULL DEFAULT 0,
  blocked_reason varchar(190) NOT NULL DEFAULT '',
  consent_at datetime DEFAULT NULL,
  custom_fields longtext NULL,
  first_seen_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY public_id (public_id),
  KEY wp_user_id (wp_user_id),
  KEY last_seen_at (last_seen_at)
) {$c};",
			"CREATE TABLE {$t('sessions')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_id char(26) NOT NULL,
  visitor_id bigint(20) unsigned NOT NULL,
  secret_hash char(64) NOT NULL,
  ip_hash char(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  last_used_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  revoked_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY public_id (public_id),
  KEY visitor_id (visitor_id),
  KEY expires_at (expires_at)
) {$c};",
			"CREATE TABLE {$t('conversations')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_id char(26) NOT NULL,
  reference varchar(16) NOT NULL,
  visitor_id bigint(20) unsigned NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'queued',
  priority tinyint(3) NOT NULL DEFAULT 0,
  team_id bigint(20) unsigned DEFAULT NULL,
  assigned_operator_id bigint(20) unsigned DEFAULT NULL,
  routing_mode varchar(16) NOT NULL DEFAULT 'reply',
  provider varchar(16) NOT NULL DEFAULT '',
  routing_snapshot longtext NULL,
  subject varchar(190) NOT NULL DEFAULT '',
  page_url varchar(500) NOT NULL DEFAULT '',
  page_title varchar(190) NOT NULL DEFAULT '',
  campaign_rule_id bigint(20) unsigned DEFAULT NULL,
  offline tinyint(1) NOT NULL DEFAULT 0,
  unread_admin int(10) unsigned NOT NULL DEFAULT 0,
  visitor_read_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
  admin_read_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
  visitor_typing_until datetime DEFAULT NULL,
  operator_typing_until datetime DEFAULT NULL,
  last_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
  last_message_at datetime DEFAULT NULL,
  first_human_response_at datetime DEFAULT NULL,
  assigned_at datetime DEFAULT NULL,
  closed_at datetime DEFAULT NULL,
  reopened_count int(10) unsigned NOT NULL DEFAULT 0,
  version int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY public_id (public_id),
  UNIQUE KEY reference (reference),
  KEY visitor_status (visitor_id,status),
  KEY status_last (status,last_message_at),
  KEY operator_status (assigned_operator_id,status),
  KEY team_status (team_id,status),
  KEY created_at (created_at)
) {$c};",
			"CREATE TABLE {$t('participants')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  conversation_id bigint(20) unsigned NOT NULL,
  kind varchar(16) NOT NULL,
  ref_id bigint(20) unsigned NOT NULL,
  joined_at datetime NOT NULL,
  left_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY member (conversation_id,kind,ref_id)
) {$c};",
			"CREATE TABLE {$t('messages')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_id char(26) NOT NULL,
  conversation_id bigint(20) unsigned NOT NULL,
  author_type varchar(16) NOT NULL,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(16) NOT NULL DEFAULT 'text',
  body longtext NOT NULL,
  is_internal tinyint(1) NOT NULL DEFAULT 0,
  source varchar(16) NOT NULL DEFAULT 'widget',
  client_key varchar(64) DEFAULT NULL,
  delivery_status varchar(16) NOT NULL DEFAULT 'none',
  delivery_error varchar(190) NOT NULL DEFAULT '',
  provider_accepted_at datetime DEFAULT NULL,
  edited_at datetime DEFAULT NULL,
  meta longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY public_id (public_id),
  UNIQUE KEY client_key (conversation_id,client_key),
  KEY conversation_cursor (conversation_id,id),
  KEY delivery_status (delivery_status),
  KEY created_at (created_at)
) {$c};",
			"CREATE TABLE {$t('attachments')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_id char(26) NOT NULL,
  conversation_id bigint(20) unsigned NOT NULL,
  message_id bigint(20) unsigned DEFAULT NULL,
  uploader_type varchar(16) NOT NULL,
  uploader_id bigint(20) unsigned NOT NULL DEFAULT 0,
  storage_name varchar(80) NOT NULL,
  original_name varchar(190) NOT NULL,
  mime varchar(100) NOT NULL,
  size bigint(20) unsigned NOT NULL,
  sha256 char(64) NOT NULL,
  width int(10) unsigned NOT NULL DEFAULT 0,
  height int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY public_id (public_id),
  KEY conversation_id (conversation_id),
  KEY message_id (message_id),
  KEY created_at (created_at)
) {$c};",
			"CREATE TABLE {$t('operators')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  wp_user_id bigint(20) unsigned DEFAULT NULL,
  display_name varchar(120) NOT NULL,
  bale_user_id varchar(32) DEFAULT NULL,
  bale_username varchar(64) NOT NULL DEFAULT '',
  status varchar(16) NOT NULL DEFAULT 'active',
  availability varchar(16) NOT NULL DEFAULT 'offline',
  away_until datetime DEFAULT NULL,
  heartbeat_at datetime DEFAULT NULL,
  max_concurrent int(10) unsigned NOT NULL DEFAULT 5,
  last_assigned_at datetime DEFAULT NULL,
  schedule longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY bale_user_id (bale_user_id),
  KEY wp_user_id (wp_user_id)
) {$c};",
			"CREATE TABLE {$t('teams')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(120) NOT NULL,
  slug varchar(64) NOT NULL,
  description varchar(255) NOT NULL DEFAULT '',
  destination_chat_id varchar(32) NOT NULL DEFAULT '',
  destination_title varchar(190) NOT NULL DEFAULT '',
  assignment_strategy varchar(16) NOT NULL DEFAULT 'manual',
  rr_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
  public tinyint(1) NOT NULL DEFAULT 1,
  active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) {$c};",
			"CREATE TABLE {$t('team_members')} (
  team_id bigint(20) unsigned NOT NULL,
  operator_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (team_id,operator_id),
  KEY operator_id (operator_id)
) {$c};",
			"CREATE TABLE {$t('provider_mappings')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  provider varchar(16) NOT NULL,
  bot_id varchar(32) NOT NULL,
  chat_id varchar(32) NOT NULL,
  provider_message_id varchar(32) NOT NULL,
  conversation_id bigint(20) unsigned NOT NULL,
  message_id bigint(20) unsigned DEFAULT NULL,
  part_index smallint(5) unsigned NOT NULL DEFAULT 0,
  direction varchar(8) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY provider_message (provider,bot_id,chat_id,provider_message_id),
  KEY conversation_id (conversation_id),
  KEY message_id (message_id)
) {$c};",
			"CREATE TABLE {$t('inbound_updates')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  provider varchar(16) NOT NULL,
  bot_id varchar(32) NOT NULL,
  update_id varchar(32) NOT NULL,
  payload longtext NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'pending',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  error varchar(255) NOT NULL DEFAULT '',
  received_at datetime NOT NULL,
  processed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY provider_update (provider,bot_id,update_id),
  KEY status (status,id),
  KEY received_at (received_at)
) {$c};",
			"CREATE TABLE {$t('jobs')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(32) NOT NULL,
  order_key varchar(64) NOT NULL DEFAULT '',
  payload longtext NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'pending',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  max_attempts smallint(5) unsigned NOT NULL DEFAULT 8,
  available_at datetime NOT NULL,
  locked_by char(32) DEFAULT NULL,
  locked_until datetime DEFAULT NULL,
  uncertain tinyint(1) NOT NULL DEFAULT 0,
  last_error varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status_available (status,available_at),
  KEY order_key (order_key,id),
  KEY locked_until (status,locked_until)
) {$c};",
			"CREATE TABLE {$t('rules')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  priority int(11) NOT NULL DEFAULT 10,
  definition longtext NOT NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY enabled_priority (enabled,priority)
) {$c};",
			"CREATE TABLE {$t('rule_events')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  rule_id bigint(20) unsigned NOT NULL,
  visitor_id bigint(20) unsigned DEFAULT NULL,
  event varchar(16) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY rule_event (rule_id,event,created_at),
  KEY visitor_rule (visitor_id,rule_id,created_at)
) {$c};",
			"CREATE TABLE {$t('tags')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(64) NOT NULL,
  slug varchar(64) NOT NULL,
  color varchar(7) NOT NULL DEFAULT '#6b7280',
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) {$c};",
			"CREATE TABLE {$t('conversation_tags')} (
  conversation_id bigint(20) unsigned NOT NULL,
  tag_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (conversation_id,tag_id),
  KEY tag_id (tag_id)
) {$c};",
			"CREATE TABLE {$t('ratings')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  conversation_id bigint(20) unsigned NOT NULL,
  operator_id bigint(20) unsigned DEFAULT NULL,
  score tinyint(3) unsigned NOT NULL,
  comment text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY conversation_id (conversation_id),
  KEY created_at (created_at)
) {$c};",
			"CREATE TABLE {$t('audit_events')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  actor_type varchar(16) NOT NULL,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(64) NOT NULL,
  object_type varchar(32) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  details text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY object_ref (object_type,object_id),
  KEY created_at (created_at)
) {$c};",
			"CREATE TABLE {$t('enrollments')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  purpose varchar(16) NOT NULL,
  code_hash char(64) NOT NULL,
  operator_id bigint(20) unsigned DEFAULT NULL,
  team_id bigint(20) unsigned DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  expires_at datetime NOT NULL,
  used_at datetime DEFAULT NULL,
  result varchar(190) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY code_hash (code_hash)
) {$c};",
			"CREATE TABLE {$t('rate_limits')} (
  bucket char(64) NOT NULL,
  window_start int(10) unsigned NOT NULL,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (bucket),
  KEY window_start (window_start)
) {$c};",
			"CREATE TABLE {$t('locks')} (
  name varchar(64) NOT NULL,
  owner char(32) NOT NULL,
  expires_at int(10) unsigned NOT NULL,
  PRIMARY KEY  (name)
) {$c};",
			"CREATE TABLE {$t('knowledge')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(16) NOT NULL,
  title varchar(190) NOT NULL,
  shortcut varchar(64) NOT NULL DEFAULT '',
  body text NOT NULL,
  url varchar(500) NOT NULL DEFAULT '',
  active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY type_active (type,active,sort_order)
) {$c};",
		);
	}

	public static function drop_all(): void {
		global $wpdb;
		foreach ( self::TABLES as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		delete_option( self::OPTION );
	}
}
