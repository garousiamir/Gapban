<?php
/**
 * WP-CLI commands.
 *
 * @package Gapban
 */

namespace Gapban\Core;

use Gapban\Bale\BaleClient;
use Gapban\Bale\BaleProvider;
use Gapban\Bale\Poller;
use Gapban\Bale\WebhookManager;
use Gapban\Queue\JobQueue;
use Gapban\Queue\Worker;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the Gapban live chat plugin.
 */
final class Cli {

	/**
	 * Process queued jobs. Run every minute from the host's crontab.
	 *
	 * ## OPTIONS
	 *
	 * [--seconds=<seconds>]
	 * : Time budget. Default 50.
	 *
	 * ## EXAMPLES
	 *
	 *     * * * * * cd /path/to/site && wp gapban worker --seconds=50 --quiet
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public function worker( array $args, array $assoc ): void {
		update_option( 'gapban_last_worker_run', time(), false );
		$result = Worker::run( (float) ( $assoc['seconds'] ?? 50 ), 1000 );
		Maintenance::run_if_due();
		\WP_CLI::log( sprintf( 'processed=%d failed=%d', $result['processed'], $result['failed'] ) );
	}

	/**
	 * Poll Bale with getUpdates (only when no webhook is set). One poller at a time.
	 *
	 * ## OPTIONS
	 *
	 * [--seconds=<seconds>]
	 * : Time budget. Default 50.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public function poll( array $args, array $assoc ): void {
		$result = Poller::run( (float) ( $assoc['seconds'] ?? 50 ) );
		\WP_CLI::log( sprintf( 'status=%s received=%d', $result['status'], $result['received'] ) );
	}

	/**
	 * Show connection, webhook and queue status.
	 */
	public function status(): void {
		$client = new BaleClient();
		\WP_CLI::log( 'token: ' . ( $client->has_token() ? 'configured' : 'missing' ) );
		\WP_CLI::log( 'transport: ' . Settings::get( 'transport' ) );
		if ( $client->has_token() ) {
			try {
				$me = ( new BaleProvider( $client ) )->verify();
				\WP_CLI::log( 'bot: @' . $me['username'] . ' (' . $me['id'] . ')' );
				$hook = WebhookManager::status( $client );
				\WP_CLI::log( 'webhook: ' . ( $hook['set'] ? ( $hook['owned'] ? 'ours' : 'foreign (' . $hook['foreign_host'] . ')' ) : 'none' ) . ' pending=' . $hook['pending_update_count'] );
			} catch ( \Throwable $e ) {
				\WP_CLI::warning( \Gapban\Security\Redactor::text( $e->getMessage() ) );
			}
		}
		foreach ( JobQueue::stats() as $k => $v ) {
			\WP_CLI::log( "queue.$k: $v" );
		}
	}

	/**
	 * Run retention and cleanup now.
	 */
	public function maintenance(): void {
		foreach ( Maintenance::run() as $k => $v ) {
			\WP_CLI::log( "$k: $v" );
		}
	}

	/**
	 * Re-encrypt stored secrets with the current key (after setting GAPBAN_ENCRYPTION_KEY;
	 * keep the old key in GAPBAN_PREVIOUS_ENCRYPTION_KEY until this has run).
	 *
	 * @subcommand rotate-key
	 */
	public function rotate_key(): void {
		$n = Settings::rotate_secrets();
		\WP_CLI::success( sprintf( '%d secret(s) re-encrypted with key %s', $n, \Gapban\Security\Crypto::current_key_id() ) );
	}
}
