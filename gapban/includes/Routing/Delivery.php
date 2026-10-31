<?php
/**
 * deliver_message job handler and delivery-status bookkeeping.
 *
 * Delivery states on a message:
 *   none      – not routed to a provider (WordPress-only conversation or internal).
 *   queued    – accepted locally, waiting for the provider.
 *   sent      – the provider API accepted every part (not a human read receipt).
 *   retrying  – a transient failure occurred; the queue will retry.
 *   uncertain – a timeout/crash happened after the request may have reached the provider.
 *   failed    – permanently failed or exhausted; visible in the dead-letter view.
 *
 * @package Gapban
 */

namespace Gapban\Routing;

use Gapban\Chat\ConversationService;
use Gapban\Persistence\Db;
use Gapban\Provider\ProviderException;

defined( 'ABSPATH' ) || exit;

final class Delivery {

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $job
	 */
	public static function handle( array $payload, array $job ): void {
		$message = ConversationService::message( (int) $payload['message_id'] );
		if ( ! $message || 'sent' === $message['delivery_status'] || (int) $message['is_internal'] ) {
			return;
		}
		$conversation = ConversationService::get( (int) $message['conversation_id'] );
		if ( ! $conversation ) {
			return;
		}
		$snap   = json_decode( (string) $conversation['routing_snapshot'], true ) ?: array();
		$router = RoutingRegistry::router( (string) ( $snap['mode'] ?? '' ) );
		if ( ! $router ) {
			throw new ProviderException( ProviderException::PERMANENT, 'no_router_for_mode' );
		}

		if ( (int) $job['uncertain'] ) {
			// Retrying after an ambiguous failure may produce a duplicate in the destination chat.
			$meta                       = json_decode( (string) $message['meta'], true ) ?: array();
			$meta['possible_duplicate'] = true;
			Db::update( 'messages', array( 'meta' => wp_json_encode( $meta ) ), array( 'id' => (int) $message['id'] ) );
		}

		$router->deliver( $message, $conversation );

		Db::update(
			'messages',
			array(
				'delivery_status'      => 'sent',
				'delivery_error'       => '',
				'provider_accepted_at' => Db::now(),
			),
			array( 'id' => (int) $message['id'] )
		);
	}

	/**
	 * Reflect job failures on the message so the inbox and widget show honest status.
	 *
	 * @param array<string, mixed> $job
	 */
	public static function on_job_failed( array $job, \Throwable $error, string $status ): void {
		if ( 'deliver_message' !== $job['type'] ) {
			return;
		}
		$kind  = $error instanceof ProviderException ? $error->kind : 'error';
		$state = 'dead' === $status ? ( ProviderException::UNCERTAIN === $kind ? 'uncertain' : 'failed' ) : ( ProviderException::UNCERTAIN === $kind ? 'uncertain' : 'retrying' );
		Db::update(
			'messages',
			array(
				'delivery_status' => $state,
				'delivery_error'  => mb_substr( $kind . ': ' . $error->getMessage(), 0, 190 ),
			),
			array( 'id' => (int) ( $job['payload']['message_id'] ?? 0 ) )
		);
		if ( $error instanceof ProviderException && ProviderException::AUTH === $error->kind ) {
			update_option( 'gapban_token_invalid_at', gmdate( 'Y-m-d H:i:s' ), false );
		}
	}

	/**
	 * Recovery for messages left "queued" without any delivery job (defence in depth;
	 * normal writes are transactional).
	 */
	public static function requeue_orphans(): int {
		$m    = Db::t( 'messages' );
		$j    = Db::t( 'jobs' );
		$rows = Db::rows(
			"SELECT m.id, m.conversation_id FROM {$m} m
			 WHERE m.delivery_status IN ('queued','retrying') AND m.created_at < %s
			 AND NOT EXISTS (SELECT 1 FROM {$j} j WHERE j.type = 'deliver_message' AND j.order_key = CONCAT('c:', m.conversation_id)
			   AND j.status IN ('pending','running') AND j.payload LIKE CONCAT('%%\"message_id\":', m.id, '}%%'))
			 LIMIT 100",
			Db::at( time() - 300 )
		);
		foreach ( $rows as $r ) {
			\Gapban\Queue\JobQueue::enqueue( 'deliver_message', array( 'message_id' => (int) $r['id'] ), ConversationService::order_key( (int) $r['conversation_id'] ) );
		}
		return count( $rows );
	}
}
