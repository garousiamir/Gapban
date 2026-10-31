<?php
/**
 * Durable job queue with atomic claims, leases, ordered keys and dead-lettering.
 *
 * Status lifecycle:
 *   pending → running → done
 *                     → pending (retryable, backoff)
 *                     → dead    (permanent / exhausted / uncertain+hold)
 *
 * Ordering: jobs sharing an order_key (one key per conversation) run strictly in id
 * order — a job is claimable only when no earlier job with the same key is pending
 * or running. Dead jobs do not block later jobs; they wait for an authorized retry.
 *
 * @package Gapban
 */

namespace Gapban\Queue;

use Gapban\Core\Settings;
use Gapban\Domain\Ids;
use Gapban\Persistence\Db;
use Gapban\Provider\ProviderException;

defined( 'ABSPATH' ) || exit;

final class JobQueue {

	public const LEASE_SECONDS = 120;

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function enqueue( string $type, array $payload, string $order_key = '', int $delay = 0, int $max_attempts = 8 ): int {
		$now = time();
		return Db::insert(
			'jobs',
			array(
				'type'         => $type,
				'order_key'    => $order_key,
				'payload'      => wp_json_encode( $payload ),
				'status'       => 'pending',
				'max_attempts' => $max_attempts,
				'available_at' => Db::at( $now + $delay ),
				'created_at'   => Db::at( $now ),
				'updated_at'   => Db::at( $now ),
			)
		);
	}

	public static function worker_id(): string {
		return strtolower( Ids::random_base32( 32 ) );
	}

	/**
	 * Claim up to $limit runnable jobs for $worker.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function claim( string $worker, int $limit = 10, ?string $order_key = null ): array {
		$t     = Db::t( 'jobs' );
		$now   = Db::now();
		$where = $order_key ? $GLOBALS['wpdb']->prepare( ' AND j.order_key = %s', $order_key ) : '';
		$ids   = Db::rows(
			"SELECT j.id FROM {$t} j
			 WHERE j.status = 'pending' AND j.available_at <= %s {$where}
			 AND NOT EXISTS (
			   SELECT 1 FROM {$t} p WHERE p.order_key = j.order_key AND j.order_key <> ''
			   AND p.id < j.id AND p.status IN ('pending','running')
			 )
			 ORDER BY j.id ASC LIMIT %d",
			$now,
			$limit * 3
		);

		$claimed = array();
		foreach ( $ids as $row ) {
			if ( count( $claimed ) >= $limit ) {
				break;
			}
			$n = Db::exec(
				"UPDATE {$t} SET status = 'running', locked_by = %s, locked_until = %s, attempts = attempts + 1, updated_at = %s
				 WHERE id = %d AND status = 'pending'",
				$worker,
				Db::at( time() + self::LEASE_SECONDS ),
				$now,
				(int) $row['id']
			);
			if ( 1 === $n ) {
				$job            = Db::row( "SELECT * FROM {$t} WHERE id = %d", (int) $row['id'] );
				$job['payload'] = json_decode( (string) $job['payload'], true ) ?: array();
				$claimed[]      = $job;
			}
		}
		return $claimed;
	}

	public static function complete( int $id, string $worker ): void {
		Db::exec(
			'UPDATE ' . Db::t( 'jobs' ) . " SET status = 'done', locked_by = NULL, locked_until = NULL, last_error = '', updated_at = %s WHERE id = %d AND locked_by = %s",
			Db::now(),
			$id,
			$worker
		);
	}

	/**
	 * Record a failure and decide what happens next.
	 *
	 * @param array<string, mixed> $job
	 * @return string resulting status
	 */
	public static function fail( array $job, string $worker, \Throwable $error ): string {
		$kind     = $error instanceof ProviderException ? $error->kind : ProviderException::RETRYABLE;
		$attempts = (int) $job['attempts'];
		$max      = (int) $job['max_attempts'];
		$message  = mb_substr( $kind . ': ' . $error->getMessage(), 0, 250 );
		$status   = 'pending';
		$delay    = 0;
		$uncertain = (int) $job['uncertain'];

		switch ( $kind ) {
			case ProviderException::PERMANENT:
			case ProviderException::AUTH:
				$status = 'dead';
				break;
			case ProviderException::RATE_LIMITED:
				$delay = max( 1, $error instanceof ProviderException ? $error->retry_after : 5 ) + random_int( 0, 2 );
				// Throttling is not the job's fault; do not burn attempts.
				$attempts = max( 0, $attempts - 1 );
				break;
			case ProviderException::UNCERTAIN:
				$uncertain = 1;
				if ( 'hold' === Settings::get( 'uncertain_send_policy' ) ) {
					$status = 'dead';
				} else {
					$delay = self::backoff( $attempts );
				}
				break;
			default:
				$delay = self::backoff( $attempts );
		}
		if ( 'pending' === $status && $attempts >= $max ) {
			$status = 'dead';
		}

		Db::exec(
			'UPDATE ' . Db::t( 'jobs' ) . ' SET status = %s, attempts = %d, uncertain = %d, available_at = %s, locked_by = NULL, locked_until = NULL, last_error = %s, updated_at = %s WHERE id = %d AND locked_by = %s',
			$status,
			$attempts,
			$uncertain,
			Db::at( time() + $delay ),
			$message,
			Db::now(),
			(int) $job['id'],
			$worker
		);
		return $status;
	}

	/**
	 * Exponential backoff with full jitter, capped at 15 minutes.
	 */
	public static function backoff( int $attempts ): int {
		$cap  = 900;
		$base = min( $cap, (int) ( 5 * ( 2 ** max( 0, $attempts - 1 ) ) ) );
		return random_int( (int) ceil( $base / 2 ), max( (int) ceil( $base / 2 ), $base ) );
	}

	/**
	 * Return jobs whose worker died (lease expired) to the queue, flagged uncertain:
	 * the remote call may have succeeded before the crash.
	 */
	public static function recover_expired(): int {
		$t    = Db::t( 'jobs' );
		$hold = 'hold' === Settings::get( 'uncertain_send_policy' );
		return Db::exec(
			"UPDATE {$t} SET status = %s, uncertain = 1, locked_by = NULL, locked_until = NULL,
			 last_error = 'uncertain: worker lease expired', available_at = %s, updated_at = %s
			 WHERE status = 'running' AND locked_until < %s",
			$hold ? 'dead' : 'pending',
			Db::now(),
			Db::now(),
			Db::now()
		);
	}

	/**
	 * Authorized manual retry of a dead job.
	 */
	public static function retry( int $id ): bool {
		return 1 === Db::exec(
			'UPDATE ' . Db::t( 'jobs' ) . " SET status = 'pending', attempts = 0, available_at = %s, updated_at = %s WHERE id = %d AND status = 'dead'",
			Db::now(),
			Db::now(),
			$id
		);
	}

	public static function discard( int $id ): bool {
		return 1 === Db::exec(
			'UPDATE ' . Db::t( 'jobs' ) . " SET status = 'discarded', updated_at = %s WHERE id = %d AND status = 'dead'",
			Db::now(),
			$id
		);
	}

	/**
	 * @return array<string, int>
	 */
	public static function stats(): array {
		$rows = Db::rows( 'SELECT status, COUNT(*) AS n FROM ' . Db::t( 'jobs' ) . ' GROUP BY status' );
		$out  = array(
			'pending'   => 0,
			'running'   => 0,
			'done'      => 0,
			'dead'      => 0,
			'discarded' => 0,
		);
		foreach ( $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		$oldest            = Db::value( 'SELECT MIN(available_at) FROM ' . Db::t( 'jobs' ) . " WHERE status = 'pending'" );
		$out['oldest_age'] = $oldest ? max( 0, time() - (int) strtotime( $oldest . ' UTC' ) ) : 0;
		return $out;
	}

	public static function cleanup(): int {
		return Db::exec( 'DELETE FROM ' . Db::t( 'jobs' ) . " WHERE status IN ('done','discarded') AND updated_at < %s", Db::at( time() - 7 * DAY_IN_SECONDS ) );
	}
}
