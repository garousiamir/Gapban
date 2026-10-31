<?php
/**
 * Append-only audit trail.
 *
 * @package Gapban
 */

namespace Gapban\Chat;

use Gapban\Persistence\Db;

defined( 'ABSPATH' ) || exit;

final class Audit {

	/**
	 * @param array<string, mixed> $details must never contain secrets or message bodies.
	 */
	public static function log( string $actor_type, int $actor_id, string $action, string $object_type = '', int $object_id = 0, array $details = array() ): void {
		try {
			Db::insert(
				'audit_events',
				array(
					'actor_type'  => $actor_type,
					'actor_id'    => $actor_id,
					'action'      => substr( $action, 0, 64 ),
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'details'     => $details ? wp_json_encode( $details ) : null,
					'created_at'  => Db::now(),
				)
			);
		} catch ( \Throwable $e ) {
			// Auditing must never break the primary action.
			unset( $e );
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_object( string $type, int $id, int $limit = 100 ): array {
		return Db::rows( 'SELECT * FROM ' . Db::t( 'audit_events' ) . ' WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT %d', $type, $id, $limit );
	}

	public static function admin_actor(): array {
		return array( 'user', get_current_user_id() );
	}
}
