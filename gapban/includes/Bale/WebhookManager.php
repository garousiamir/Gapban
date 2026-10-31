<?php
/**
 * Webhook registration, status and ownership-safe removal.
 *
 * Authenticity: Gapban does not assume Bale signs webhook calls or sends a secret
 * header. The webhook URL contains a 256-bit revocable secret path segment; requests
 * without it are rejected before parsing. The secret is redacted from diagnostics.
 * Anyone who learns the URL could post forged updates, so sender and chat allowlists
 * are enforced for every update regardless (see InboundProcessor).
 *
 * @package Gapban
 */

namespace Gapban\Bale;

use Gapban\Core\Settings;
use Gapban\Domain\Ids;
use Gapban\Provider\ProviderException;
use Gapban\Security\Crypto;

defined( 'ABSPATH' ) || exit;

final class WebhookManager {

	public const OWNED_OPTION = 'gapban_webhook_owned';

	public static function secret(): string {
		$secret = Settings::secret( 'webhook_secret' );
		if ( ! $secret ) {
			$secret = Ids::secret( 32 );
			Settings::set_secret( 'webhook_secret', $secret );
		}
		return $secret;
	}

	public static function rotate_secret(): string {
		$secret = Ids::secret( 32 );
		Settings::set_secret( 'webhook_secret', $secret );
		return $secret;
	}

	public static function verify_secret( string $candidate ): bool {
		$secret = Settings::secret( 'webhook_secret' );
		return $secret && hash_equals( $secret, $candidate );
	}

	public static function url(): string {
		return rest_url( 'gapban/v1/bale/webhook/' . self::secret() );
	}

	public static function redacted_url(): string {
		return rest_url( 'gapban/v1/bale/webhook/' ) . '••••••';
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function status( BaleClient $client ): array {
		$info = $client->call( 'getWebhookInfo' );
		$info = is_array( $info ) ? $info : array();
		$url  = (string) ( $info['url'] ?? '' );
		return array(
			'set'                  => '' !== $url,
			'owned'                => '' !== $url && hash_equals( self::url(), $url ),
			'foreign_host'         => '' !== $url && ! hash_equals( self::url(), $url ) ? (string) wp_parse_url( $url, PHP_URL_HOST ) : '',
			'pending_update_count' => (int) ( $info['pending_update_count'] ?? 0 ),
			'last_error_date'      => isset( $info['last_error_date'] ) ? gmdate( 'Y-m-d H:i:s', (int) $info['last_error_date'] ) : '',
			'last_error_message'   => \Gapban\Security\Redactor::text( (string) ( $info['last_error_message'] ?? '' ) ),
			'url'                  => self::redacted_url(),
		);
	}

	/**
	 * Register our webhook. Refuses to replace another application's webhook unless $force.
	 *
	 * @return array<string, mixed>
	 * @throws ProviderException
	 */
	public static function install( BaleClient $client, bool $force = false ): array {
		$url = self::url();
		if ( 0 !== strpos( $url, 'https://' ) && ! ( defined( 'GAPBAN_DEV_MODE' ) && GAPBAN_DEV_MODE ) ) {
			throw new ProviderException( ProviderException::PERMANENT, 'webhook_requires_https' );
		}
		$status = self::status( $client );
		if ( $status['set'] && ! $status['owned'] && ! $force ) {
			return array(
				'installed'    => false,
				'reason'       => 'foreign_webhook',
				'foreign_host' => $status['foreign_host'],
			);
		}
		$client->call( 'setWebhook', array( 'url' => $url ) );
		update_option( self::OWNED_OPTION, Crypto::hash( $url, 'webhook' ), false );
		BaleProvider::observe( 'setWebhook' );
		return array( 'installed' => true );
	}

	/**
	 * Remove the webhook only if it is still ours.
	 *
	 * @throws ProviderException
	 */
	public static function remove( BaleClient $client ): bool {
		$status = self::status( $client );
		if ( ! $status['owned'] ) {
			delete_option( self::OWNED_OPTION );
			return false;
		}
		$client->call( 'deleteWebhook' );
		delete_option( self::OWNED_OPTION );
		return true;
	}

	/**
	 * Best-effort removal on uninstall: never touches a webhook that is not ours.
	 */
	public static function remove_if_owned(): void {
		$owned = get_option( self::OWNED_OPTION );
		if ( ! $owned || ! hash_equals( (string) $owned, Crypto::hash( self::url(), 'webhook' ) ) ) {
			return;
		}
		try {
			self::remove( new BaleClient() );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
