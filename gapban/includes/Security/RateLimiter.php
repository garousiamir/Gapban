<?php
/**
 * Fixed-window rate limiter backed by an atomic upsert (works without object cache).
 *
 * @package Gapban
 */

namespace Gapban\Security;

use Gapban\Persistence\Db;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {

	/**
	 * Count a hit and report whether it is within $limit per $window seconds.
	 */
	public static function hit( string $bucket, int $limit, int $window ): bool {
		global $wpdb;
		$key   = hash( 'sha256', $bucket );
		$now   = time();
		$start = $now - ( $now % $window );
		$table = Db::t( 'rate_limits' );

		// Reset the counter when a new window starts, otherwise increment atomically.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (bucket, window_start, hits) VALUES (%s, %d, 1)
				 ON DUPLICATE KEY UPDATE hits = IF(window_start = VALUES(window_start), hits + 1, 1),
				 window_start = VALUES(window_start)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key,
				$start
			)
		);
		$hits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$table} WHERE bucket = %s", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $hits <= $limit;
	}

	public static function cleanup(): void {
		Db::exec( 'DELETE FROM ' . Db::t( 'rate_limits' ) . ' WHERE window_start < %d', time() - DAY_IN_SECONDS );
	}
}
