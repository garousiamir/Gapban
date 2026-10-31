<?php
/**
 * Queue worker. Runs from host cron (WP-CLI or the secret cron URL), WP-Cron,
 * or a bounded post-response attempt right after a message is accepted.
 *
 * @package Gapban
 */

namespace Gapban\Queue;

use Gapban\Provider\ProviderException;

defined( 'ABSPATH' ) || exit;

final class Worker {

	/** @var array<string, callable(array<string,mixed>, array<string,mixed>):void> */
	private static array $handlers = array();

	public static function register( string $type, callable $handler ): void {
		self::$handlers[ $type ] = $handler;
	}

	/**
	 * Process jobs until the time budget or job budget is exhausted.
	 *
	 * @return array{processed:int, failed:int}
	 */
	public static function run( float $seconds = 20.0, int $max_jobs = 200, ?string $order_key = null ): array {
		$deadline  = microtime( true ) + $seconds;
		$worker    = JobQueue::worker_id();
		$processed = 0;
		$failed    = 0;

		if ( null === $order_key ) {
			JobQueue::recover_expired();
		}

		while ( microtime( true ) < $deadline && $processed + $failed < $max_jobs ) {
			$jobs = JobQueue::claim( $worker, 5, $order_key );
			if ( ! $jobs ) {
				break;
			}
			foreach ( $jobs as $job ) {
				try {
					$handler = self::$handlers[ $job['type'] ] ?? null;
					if ( ! $handler ) {
						throw new ProviderException( ProviderException::PERMANENT, 'unknown_job_type' );
					}
					$handler( $job['payload'], $job );
					JobQueue::complete( (int) $job['id'], $worker );
					++$processed;
				} catch ( \Throwable $e ) {
					$status = JobQueue::fail( $job, $worker, $e );
					do_action( 'gapban_job_failed', $job, $e, $status );
					++$failed;
				}
			}
		}
		return array(
			'processed' => $processed,
			'failed'    => $failed,
		);
	}

	/**
	 * Run a bounded attempt for one ordering key after the HTTP response is flushed.
	 */
	public static function after_response( string $order_key, float $seconds = 8.0 ): void {
		static $scheduled = array();
		if ( isset( $scheduled[ $order_key ] ) || ( defined( 'GAPBAN_DISABLE_INLINE_WORKER' ) && GAPBAN_DISABLE_INLINE_WORKER ) ) {
			return;
		}
		$scheduled[ $order_key ] = true;
		register_shutdown_function(
			static function () use ( $order_key, $seconds ): void {
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				} elseif ( function_exists( 'litespeed_finish_request' ) ) {
					litespeed_finish_request();
				}
				try {
					self::run( $seconds, 20, $order_key );
				} catch ( \Throwable $e ) {
					// The durable queue will pick the work up later.
					unset( $e );
				}
			}
		);
	}
}
