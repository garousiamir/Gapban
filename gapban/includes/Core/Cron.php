<?php
/**
 * WP-Cron integration. WP-Cron is traffic-driven and therefore only a fallback;
 * production sites should run `wp gapban worker` (or the secret cron URL) from the
 * host's real cron every minute — see docs/fa/cron.md.
 *
 * @package Gapban
 */

namespace Gapban\Core;

use Gapban\Queue\Worker;

defined( 'ABSPATH' ) || exit;

final class Cron {

	public const WORKER_HOOK      = 'gapban_worker';
	public const MAINTENANCE_HOOK = 'gapban_maintenance';

	public static function schedules( array $schedules ): array {
		$schedules['gapban_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => 'Gapban: every minute',
		);
		return $schedules;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::WORKER_HOOK ) ) {
			wp_schedule_event( time() + 30, 'gapban_minute', self::WORKER_HOOK );
		}
		if ( ! wp_next_scheduled( self::MAINTENANCE_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::MAINTENANCE_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::WORKER_HOOK );
		wp_clear_scheduled_hook( self::MAINTENANCE_HOOK );
	}

	public static function tick(): void {
		update_option( 'gapban_last_worker_run', time(), false );
		Worker::run( 20.0, 100 );
		if ( 'polling' === Settings::get( 'transport' ) ) {
			\Gapban\Bale\Poller::run( 15.0 );
		}
	}
}
