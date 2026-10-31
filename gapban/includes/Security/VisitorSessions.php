<?php
/**
 * Visitor bearer sessions.
 *
 * A session token is "<session public id>.<secret>". Only an HMAC of the secret
 * is stored, so a database leak does not expose usable tokens. Tokens travel in
 * the X-Gapban-Session header (never in URLs or cookies), so cached pages never
 * contain them and cross-site requests cannot ride on ambient credentials.
 *
 * @package Gapban
 */

namespace Gapban\Security;

use Gapban\Core\Settings;
use Gapban\Domain\Ids;
use Gapban\Persistence\Db;

defined( 'ABSPATH' ) || exit;

final class VisitorSessions {

	public const HEADER = 'X-Gapban-Session';

	/**
	 * Create a visitor + session. Returns the token (shown once) and ids.
	 *
	 * @return array{token:string, session_id:int, visitor_id:int, visitor_public_id:string, expires_at:string}
	 */
	public static function create( ?int $wp_user_id = null ): array {
		$now        = Db::now();
		$visitor_id = 0;

		// Logged-in users keep one visitor record so history follows the account.
		if ( $wp_user_id ) {
			$visitor_id = (int) Db::value( 'SELECT id FROM ' . Db::t( 'visitors' ) . ' WHERE wp_user_id = %d ORDER BY id ASC LIMIT 1', $wp_user_id );
		}
		if ( ! $visitor_id ) {
			$visitor_id = Db::insert(
				'visitors',
				array(
					'public_id'     => Ids::public_id(),
					'wp_user_id'    => $wp_user_id ?: null,
					'first_seen_at' => $now,
					'last_seen_at'  => $now,
				)
			);
		}

		$secret    = Ids::secret( 32 );
		$public_id = Ids::public_id();
		$ttl_days  = (int) Settings::get( 'session_ttl_days' );
		$expires   = Db::at( time() + $ttl_days * DAY_IN_SECONDS );

		$session_id = Db::insert(
			'sessions',
			array(
				'public_id'    => $public_id,
				'visitor_id'   => $visitor_id,
				'secret_hash'  => Crypto::hash( $secret, 'session' ),
				'ip_hash'      => ClientIp::hash(),
				'created_at'   => $now,
				'last_used_at' => $now,
				'expires_at'   => $expires,
			)
		);

		return array(
			'token'             => $public_id . '.' . $secret,
			'session_id'        => $session_id,
			'visitor_id'        => $visitor_id,
			'visitor_public_id' => (string) Db::value( 'SELECT public_id FROM ' . Db::t( 'visitors' ) . ' WHERE id = %d', $visitor_id ),
			'expires_at'        => $expires,
		);
	}

	/**
	 * Authenticate a token. Returns session + visitor row or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function authenticate( string $token ): ?array {
		if ( strlen( $token ) > 120 || ! str_contains( $token, '.' ) ) {
			return null;
		}
		[ $public_id, $secret ] = explode( '.', $token, 2 );
		if ( ! Ids::is_public_id( $public_id ) || ! preg_match( '/^[A-Za-z0-9_-]{40,60}$/', $secret ) ) {
			return null;
		}
		$row = Db::row(
			'SELECT s.id AS session_id, s.secret_hash, s.expires_at, s.revoked_at, s.last_used_at, v.id AS visitor_id, v.public_id AS visitor_public_id, v.blocked, v.wp_user_id, v.name, v.email, v.phone
			 FROM ' . Db::t( 'sessions' ) . ' s JOIN ' . Db::t( 'visitors' ) . ' v ON v.id = s.visitor_id
			 WHERE s.public_id = %s',
			$public_id
		);
		if ( ! $row || ! hash_equals( (string) $row['secret_hash'], Crypto::hash( $secret, 'session' ) ) ) {
			return null;
		}
		if ( null !== $row['revoked_at'] || strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			return null;
		}
		// Touch at most once per minute to keep writes low.
		if ( strtotime( $row['last_used_at'] . ' UTC' ) < time() - 60 ) {
			Db::update( 'sessions', array( 'last_used_at' => Db::now() ), array( 'id' => (int) $row['session_id'] ) );
			Db::update( 'visitors', array( 'last_seen_at' => Db::now() ), array( 'id' => (int) $row['visitor_id'] ) );
		}
		unset( $row['secret_hash'] );
		return $row;
	}

	public static function from_request( \WP_REST_Request $request ): ?array {
		$token = (string) $request->get_header( self::HEADER );
		return '' === $token ? null : self::authenticate( $token );
	}

	public static function revoke( int $session_id ): void {
		Db::update( 'sessions', array( 'revoked_at' => Db::now() ), array( 'id' => $session_id ) );
	}

	public static function revoke_visitor( int $visitor_id ): void {
		Db::exec( 'UPDATE ' . Db::t( 'sessions' ) . ' SET revoked_at = %s WHERE visitor_id = %d AND revoked_at IS NULL', Db::now(), $visitor_id );
	}

	public static function cleanup(): int {
		return Db::exec( 'DELETE FROM ' . Db::t( 'sessions' ) . ' WHERE expires_at < %s OR (revoked_at IS NOT NULL AND revoked_at < %s)', Db::now(), Db::at( time() - 7 * DAY_IN_SECONDS ) );
	}
}
