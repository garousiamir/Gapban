<?php
/**
 * Lease-based named locks in the database (no external lock service needed).
 *
 * @package Gapban
 */

namespace Gapban\Security;

use Gapban\Domain\Ids;
use Gapban\Persistence\Db;

defined( 'ABSPATH' ) || exit;

final class Locks {

	/**
	 * Acquire $name for $ttl seconds. Returns an owner token or null when held by someone else.
	 */
	public static function acquire( string $name, int $ttl ): ?string {
		global $wpdb;
		$owner = strtolower( Ids::random_base32( 32 ) );
		$now   = time();
		$table = Db::t( 'locks' );
		// Insert, or take over an expired lease atomically.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (name, owner, expires_at) VALUES (%s, %s, %d)
				 ON DUPLICATE KEY UPDATE owner = IF(expires_at < %d, VALUES(owner), owner),
				 expires_at = IF(owner = VALUES(owner), VALUES(expires_at), expires_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$name,
				$owner,
				$now + $ttl,
				$now
			)
		);
		$current = Db::value( "SELECT owner FROM {$table} WHERE name = %s", $name );
		return $current === $owner ? $owner : null;
	}

	public static function extend( string $name, string $owner, int $ttl ): bool {
		return 1 === Db::exec( 'UPDATE ' . Db::t( 'locks' ) . ' SET expires_at = %d WHERE name = %s AND owner = %s', time() + $ttl, $name, $owner );
	}

	public static function release( string $name, string $owner ): void {
		Db::exec( 'DELETE FROM ' . Db::t( 'locks' ) . ' WHERE name = %s AND owner = %s', $name, $owner );
	}
}
