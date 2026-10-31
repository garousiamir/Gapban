<?php
/**
 * Bale webhook receiver and the secret host-cron worker URL.
 *
 * The webhook path secret is checked before the body is parsed. The update is
 * durably recorded (unique per bot + update_id) before 200 is returned; processing
 * then continues in a bounded post-response attempt and in the queue.
 *
 * @package Gapban
 */

namespace Gapban\Rest;

use Gapban\Bale\BaleProvider;
use Gapban\Bale\WebhookManager;
use Gapban\Core\Settings;
use Gapban\Queue\Worker;
use Gapban\Routing\InboundProcessor;
use Gapban\Routing\RoutingRegistry;
use Gapban\Security\ClientIp;
use Gapban\Security\RateLimiter;

defined( 'ABSPATH' ) || exit;

final class WebhookController {

	public const MAX_BODY = 1048576;

	public static function register(): void {
		register_rest_route(
			'gapban/v1',
			'/bale/webhook/(?P<secret>[A-Za-z0-9_\-]{20,100})',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'receive' ),
				'permission_callback' => '__return_true', // Authenticated by the secret path segment below.
			)
		);
		register_rest_route(
			'gapban/v1',
			'/cron/(?P<secret>[A-Za-z0-9_\-]{20,100})',
			array(
				'methods'             => array( 'POST', 'GET' ),
				'callback'            => array( self::class, 'cron' ),
				'permission_callback' => '__return_true', // Authenticated by the secret path segment below.
			)
		);
	}

	public static function receive( \WP_REST_Request $request ) {
		if ( ! RateLimiter::hit( 'webhook:' . ClientIp::hash(), 600, 60 ) ) {
			return new \WP_Error( 'gapban_rate_limited', 'rate limited', array( 'status' => 429 ) );
		}
		if ( ! WebhookManager::verify_secret( (string) $request['secret'] ) ) {
			// Uniform response; do not reveal whether the plugin is configured.
			return new \WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.', array( 'status' => 404 ) );
		}
		$raw = $request->get_body();
		if ( strlen( $raw ) > self::MAX_BODY ) {
			return new \WP_Error( 'gapban_too_large', 'payload too large', array( 'status' => 413 ) );
		}
		$update = json_decode( $raw, true );
		if ( ! is_array( $update ) || ! isset( $update['update_id'] ) || ! is_scalar( $update['update_id'] ) ) {
			// Acknowledge malformed bodies so the provider does not retry them forever.
			return rest_ensure_response( array( 'ok' => true, 'ignored' => 'malformed' ) );
		}

		$provider = RoutingRegistry::provider();
		$id       = InboundProcessor::record( $update, $provider->bot_id() );
		BaleProvider::observe( 'webhook_delivery' );
		if ( $id ) {
			$chat = BaleProvider::id_string( $update['message']['chat']['id'] ?? ( $update['edited_message']['chat']['id'] ?? '' ) );
			Worker::after_response( 'in:' . $chat, 10.0 );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function cron( \WP_REST_Request $request ) {
		$secret = Settings::secret( 'cron_secret' );
		if ( ! $secret || ! hash_equals( $secret, (string) $request['secret'] ) ) {
			return new \WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.', array( 'status' => 404 ) );
		}
		if ( ! RateLimiter::hit( 'cron', 10, 60 ) ) {
			return new \WP_Error( 'gapban_rate_limited', 'rate limited', array( 'status' => 429 ) );
		}
		$result = Worker::run( 25.0, 300 );
		\Gapban\Core\Maintenance::run_if_due();
		if ( 'polling' === Settings::get( 'transport' ) ) {
			$result['poll'] = \Gapban\Bale\Poller::run( 20.0 );
		}
		return rest_ensure_response( $result );
	}
}
