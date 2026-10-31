<?php
/**
 * getUpdates fallback for sites that cannot receive webhooks.
 *
 * - One poller at a time (database lease lock); overlapping runs exit immediately.
 * - Refuses to run while a webhook is registered (the two delivery modes are exclusive).
 * - Each update is durably recorded before the stored offset advances; the next
 *   getUpdates call with offset = last_update_id + 1 acknowledges them upstream.
 * - Latency equals the cron interval plus the polling loop; run it from host cron
 *   (`wp gapban poll --seconds=55` every minute) for ~1–5 s latency.
 *
 * @package Gapban
 */

namespace Gapban\Bale;

use Gapban\Provider\ProviderException;
use Gapban\Routing\InboundProcessor;
use Gapban\Security\Locks;

defined( 'ABSPATH' ) || exit;

final class Poller {

	public const OFFSET_OPTION = 'gapban_poll_offset';
	private const LOCK         = 'bale_poller';

	/**
	 * @return array{status:string, received:int}
	 */
	public static function run( float $seconds = 50.0, ?BaleProvider $provider = null ): array {
		$owner = Locks::acquire( self::LOCK, (int) ceil( $seconds ) + 30 );
		if ( ! $owner ) {
			return array(
				'status'   => 'already_running',
				'received' => 0,
			);
		}
		$provider = $provider ?? new BaleProvider();
		$client   = $provider->client();
		$received = 0;
		try {
			$hook = WebhookManager::status( $client );
			if ( $hook['set'] ) {
				return array(
					'status'   => 'webhook_active',
					'received' => 0,
				);
			}
			$deadline = microtime( true ) + $seconds;
			$bot_id   = $provider->bot_id();
			while ( microtime( true ) < $deadline ) {
				$offset  = (string) get_option( self::OFFSET_OPTION, '' );
				$params  = array(
					'limit'   => 50,
					'timeout' => 0,
				);
				if ( '' !== $offset ) {
					$params['offset'] = (int) $offset + 1;
				}
				$updates = $client->call( 'getUpdates', $params, array(), 20 );
				BaleProvider::observe( 'getUpdates' );
				if ( ! is_array( $updates ) || ! $updates ) {
					Locks::extend( self::LOCK, $owner, (int) ceil( $deadline - microtime( true ) ) + 30 );
					usleep( 1500000 );
					continue;
				}
				$max = '' === $offset ? null : (int) $offset;
				foreach ( $updates as $update ) {
					if ( ! is_array( $update ) || ! isset( $update['update_id'] ) ) {
						continue;
					}
					InboundProcessor::record( $update, $bot_id );
					++$received;
					$max = null === $max ? (int) $update['update_id'] : max( $max, (int) $update['update_id'] );
				}
				if ( null !== $max ) {
					update_option( self::OFFSET_OPTION, (string) $max, false );
				}
				\Gapban\Queue\Worker::run( 5.0, 50 );
			}
		} catch ( ProviderException $e ) {
			return array(
				'status'   => 'error: ' . $e->kind . ' ' . $e->getMessage(),
				'received' => $received,
			);
		} finally {
			Locks::release( self::LOCK, $owner );
		}
		return array(
			'status'   => 'ok',
			'received' => $received,
		);
	}
}
