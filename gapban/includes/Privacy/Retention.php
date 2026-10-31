<?php
/**
 * Retention, cascade deletion and orphan cleanup.
 *
 * Cascade for a conversation: messages → attachments (files + rows) → provider
 * mappings → participants → tags → ratings → pending jobs → audit rows of the
 * conversation. Copies already delivered to Bale are outside WordPress and are not
 * deleted by this (see docs/privacy.md).
 *
 * @package Gapban
 */

namespace Gapban\Privacy;

use Gapban\Core\Settings;
use Gapban\Persistence\Db;
use Gapban\Security\AttachmentStorage;

defined( 'ABSPATH' ) || exit;

final class Retention {

	/**
	 * @return array<string, int>
	 */
	public static function run(): array {
		$out  = array(
			'conversations_deleted' => 0,
			'attachments_deleted'   => 0,
			'visitors_deleted'      => 0,
		);
		$days = (int) Settings::get( 'retention_days' );
		if ( $days > 0 ) {
			$ids = Db::rows(
				'SELECT id FROM ' . Db::t( 'conversations' ) . " WHERE status IN ('closed','spam') AND COALESCE(last_message_at, created_at) < %s LIMIT 200",
				Db::at( time() - $days * DAY_IN_SECONDS )
			);
			foreach ( $ids as $r ) {
				self::delete_conversation( (int) $r['id'] );
				++$out['conversations_deleted'];
			}
		}

		$att_days = (int) Settings::get( 'attachment_retention_days' );
		if ( $att_days > 0 ) {
			$rows = Db::rows( 'SELECT id, storage_name FROM ' . Db::t( 'attachments' ) . ' WHERE created_at < %s LIMIT 500', Db::at( time() - $att_days * DAY_IN_SECONDS ) );
			$out['attachments_deleted'] += self::delete_attachment_rows( $rows );
		}
		// Uploads never attached to a message (abandoned or failed sends).
		$orphans = Db::rows( 'SELECT id, storage_name FROM ' . Db::t( 'attachments' ) . ' WHERE message_id IS NULL AND created_at < %s LIMIT 500', Db::at( time() - DAY_IN_SECONDS ) );
		$out['attachments_deleted'] += self::delete_attachment_rows( $orphans );

		// Visitors with no conversations and no live session after 30 days.
		$v                       = Db::t( 'visitors' );
		$out['visitors_deleted'] = Db::exec(
			"DELETE FROM {$v} WHERE last_seen_at < %s
			 AND NOT EXISTS (SELECT 1 FROM " . Db::t( 'conversations' ) . " c WHERE c.visitor_id = {$v}.id)
			 AND NOT EXISTS (SELECT 1 FROM " . Db::t( 'sessions' ) . " s WHERE s.visitor_id = {$v}.id AND s.revoked_at IS NULL AND s.expires_at > %s)",
			Db::at( time() - 30 * DAY_IN_SECONDS ),
			Db::now()
		);
		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private static function delete_attachment_rows( array $rows ): int {
		foreach ( $rows as $r ) {
			AttachmentStorage::delete( (string) $r['storage_name'] );
			Db::exec( 'DELETE FROM ' . Db::t( 'attachments' ) . ' WHERE id = %d', (int) $r['id'] );
		}
		return count( $rows );
	}

	public static function delete_conversation( int $id ): void {
		$atts = Db::rows( 'SELECT id, storage_name FROM ' . Db::t( 'attachments' ) . ' WHERE conversation_id = %d', $id );
		self::delete_attachment_rows( $atts );
		foreach ( array( 'messages', 'provider_mappings', 'participants', 'conversation_tags', 'ratings' ) as $table ) {
			Db::exec( 'DELETE FROM ' . Db::t( $table ) . ' WHERE conversation_id = %d', $id );
		}
		Db::exec( 'DELETE FROM ' . Db::t( 'jobs' ) . " WHERE order_key = %s AND status IN ('pending','dead')", 'c:' . $id );
		Db::exec( 'DELETE FROM ' . Db::t( 'audit_events' ) . " WHERE object_type = 'conversation' AND object_id = %d", $id );
		Db::exec( 'DELETE FROM ' . Db::t( 'conversations' ) . ' WHERE id = %d', $id );
	}

	public static function delete_visitor( int $visitor_id ): int {
		$ids = Db::rows( 'SELECT id FROM ' . Db::t( 'conversations' ) . ' WHERE visitor_id = %d', $visitor_id );
		foreach ( $ids as $r ) {
			self::delete_conversation( (int) $r['id'] );
		}
		Db::exec( 'DELETE FROM ' . Db::t( 'sessions' ) . ' WHERE visitor_id = %d', $visitor_id );
		Db::exec( 'DELETE FROM ' . Db::t( 'rule_events' ) . ' WHERE visitor_id = %d', $visitor_id );
		Db::exec( 'DELETE FROM ' . Db::t( 'visitors' ) . ' WHERE id = %d', $visitor_id );
		return count( $ids );
	}
}
