<?php
/**
 * Selects routers and builds immutable per-conversation routing snapshots.
 *
 * Changing the global routing mode or destination only affects conversations
 * created afterwards: each conversation stores its own mode/provider/bot/chat.
 *
 * @package Gapban
 */

namespace Gapban\Routing;

use Gapban\Bale\BaleProvider;
use Gapban\Core\Settings;
use Gapban\Persistence\Db;
use Gapban\Provider\ProviderInterface;

defined( 'ABSPATH' ) || exit;

final class RoutingRegistry {

	private static ?ProviderInterface $provider = null;

	public static function provider(): ProviderInterface {
		if ( null === self::$provider ) {
			self::$provider = new BaleProvider();
		}
		return self::$provider;
	}

	/**
	 * Test seam and reset after a token change.
	 */
	public static function set_provider( ?ProviderInterface $provider ): void {
		self::$provider = $provider;
	}

	/**
	 * @return array<string, RoutingInterface>
	 */
	public static function routers(): array {
		return array(
			'reply'  => new ReplyRouter( self::provider() ),
			'topics' => new TopicRouter(),
		);
	}

	public static function router( string $mode ): ?RoutingInterface {
		return self::routers()[ $mode ] ?? null;
	}

	/**
	 * Validate a requested global mode; unavailable modes are refused.
	 */
	public static function can_select( string $mode ): bool {
		$router = self::router( $mode );
		return $router && $router->available();
	}

	/**
	 * Destination chat for a team, falling back to the global default.
	 */
	public static function destination_for_team( int $team_id ): array {
		if ( $team_id ) {
			$team = Db::row( 'SELECT destination_chat_id, destination_title FROM ' . Db::t( 'teams' ) . ' WHERE id = %d', $team_id );
			if ( $team && '' !== (string) $team['destination_chat_id'] ) {
				return array( (string) $team['destination_chat_id'], (string) $team['destination_title'] );
			}
		}
		return array( (string) Settings::get( 'default_destination_chat' ), (string) Settings::get( 'default_destination_title' ) );
	}

	/**
	 * Immutable routing snapshot stored on a new conversation.
	 *
	 * @return array<string, mixed>
	 */
	public static function snapshot( int $team_id ): array {
		$provider = self::provider();
		[ $chat, $title ] = self::destination_for_team( $team_id );
		$mode = (string) Settings::get( 'routing_mode' );
		if ( ! self::can_select( $mode ) ) {
			$mode = 'reply';
		}
		if ( ! $provider->is_configured() || '' === $chat ) {
			return array(
				'mode'       => 'inbox',
				'provider'   => '',
				'team_id'    => $team_id,
				'reason'     => $provider->is_configured() ? 'no_destination' : 'no_provider',
				'created_at' => gmdate( 'c' ),
			);
		}
		return array(
			'mode'                => $mode,
			'provider'            => $provider->id(),
			'bot_id'              => $provider->bot_id(),
			'destination_chat_id' => $chat,
			'destination_title'   => $title,
			'team_id'             => $team_id,
			'created_at'          => gmdate( 'c' ),
		);
	}

	/**
	 * @param array<string, mixed> $snapshot
	 */
	public static function routes_to_provider( array $snapshot ): bool {
		return ! empty( $snapshot['provider'] ) && ! empty( $snapshot['destination_chat_id'] ) && 'inbox' !== ( $snapshot['mode'] ?? 'inbox' );
	}
}
