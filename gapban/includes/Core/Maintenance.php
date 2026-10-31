<?php
/**
 * Scheduled maintenance: retention cleanup, orphan recovery, table hygiene.
 *
 * @package Gapban
 */

namespace Gapban\Core;

use Gapban\Persistence\Db;
use Gapban\Privacy\Retention;
use Gapban\Queue\JobQueue;
use Gapban\Routing\Delivery;
use Gapban\Security\RateLimiter;
use Gapban\Security\VisitorSessions;

defined( 'ABSPATH' ) || exit;

final class Maintenance {

	private const LAST_RUN = 'gapban_maintenance_at';

	public static function run_if_due(): void {
		$last = (int) get_option( self::LAST_RUN, 0 );
		if ( $last > time() - HOUR_IN_SECONDS ) {
			return;
		}
		update_option( self::LAST_RUN, time(), false );
		self::run();
	}

	/**
	 * @return array<string, int>
	 */
	public static function run(): array {
		$out                     = array();
		$out['requeued']         = Delivery::requeue_orphans();
		$out['jobs_deleted']     = JobQueue::cleanup();
		$out['sessions_deleted'] = VisitorSessions::cleanup();
		RateLimiter::cleanup();
		$out['inbound_deleted']  = Db::exec(
			'DELETE FROM ' . Db::t( 'inbound_updates' ) . " WHERE status IN ('done','ignored','failed') AND received_at < %s",
			Db::at( time() - (int) Settings::get( 'inbound_retention_days' ) * DAY_IN_SECONDS )
		);
		Db::exec( 'DELETE FROM ' . Db::t( 'locks' ) . ' WHERE expires_at < %d', time() - DAY_IN_SECONDS );
		Db::exec( 'DELETE FROM ' . Db::t( 'enrollments' ) . ' WHERE expires_at < %s', Db::at( time() - 30 * DAY_IN_SECONDS ) );
		$out += Retention::run();
		return $out;
	}
}
