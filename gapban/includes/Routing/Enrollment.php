<?php
/**
 * Short-lived one-time enrollment challenges.
 *
 *  - purpose "operator": links a Bale user id to an operator record. The operator
 *    sends "/start <code>" (or the bare code) to the bot in a private chat.
 *  - purpose "destination": binds a group/chat as a team's (or the default) support
 *    destination. Someone in the group sends "/gapban_bind <code>".
 *
 * Codes are 10 random base32 characters (~50 bits), stored only as HMACs, valid for
 * 15 minutes and usable once.
 *
 * @package Gapban
 */

namespace Gapban\Routing;

use Gapban\Chat\Audit;
use Gapban\Core\Settings;
use Gapban\Domain\Ids;
use Gapban\Persistence\Db;
use Gapban\Security\Crypto;

defined( 'ABSPATH' ) || exit;

final class Enrollment {

	public const TTL = 900;

	/**
	 * @return array{id:int, code:string, expires_at:string}
	 */
	public static function create( string $purpose, ?int $operator_id, ?int $team_id ): array {
		$code    = Ids::random_base32( 10 );
		$expires = Db::at( time() + self::TTL );
		// Invalidate older unused codes for the same target.
		Db::exec(
			'UPDATE ' . Db::t( 'enrollments' ) . " SET used_at = %s, result = 'superseded' WHERE used_at IS NULL AND purpose = %s AND IFNULL(operator_id,0) = %d AND IFNULL(team_id,0) = %d",
			Db::now(),
			$purpose,
			(int) $operator_id,
			(int) $team_id
		);
		$id = Db::insert(
			'enrollments',
			array(
				'purpose'     => $purpose,
				'code_hash'   => Crypto::hash( $code, 'enroll' ),
				'operator_id' => $operator_id,
				'team_id'     => $team_id,
				'created_by'  => get_current_user_id(),
				'expires_at'  => $expires,
			)
		);
		return array(
			'id'         => $id,
			'code'       => $code,
			'expires_at' => $expires,
		);
	}

	/**
	 * Atomically consume a code. Returns the enrollment row or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function consume( string $code, string $purpose ): ?array {
		$code = strtoupper( trim( $code ) );
		if ( ! preg_match( '/^[0-9A-Z]{10}$/', $code ) ) {
			return null;
		}
		$hash = Crypto::hash( $code, 'enroll' );
		$row  = Db::row( 'SELECT * FROM ' . Db::t( 'enrollments' ) . ' WHERE code_hash = %s AND purpose = %s', $hash, $purpose );
		if ( ! $row || null !== $row['used_at'] || strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			return null;
		}
		$n = Db::exec( 'UPDATE ' . Db::t( 'enrollments' ) . ' SET used_at = %s WHERE id = %d AND used_at IS NULL', Db::now(), (int) $row['id'] );
		return 1 === $n ? $row : null;
	}

	/**
	 * Bind an operator to a Bale user. Returns a result code.
	 */
	public static function bind_operator( array $enrollment, string $bale_user_id, string $username ): string {
		$op = (int) $enrollment['operator_id'];
		$other = (int) Db::value( 'SELECT id FROM ' . Db::t( 'operators' ) . ' WHERE bale_user_id = %s AND id <> %d', $bale_user_id, $op );
		if ( $other ) {
			self::result( (int) $enrollment['id'], 'already_linked' );
			return 'already_linked';
		}
		Db::update(
			'operators',
			array(
				'bale_user_id'  => $bale_user_id,
				'bale_username' => $username,
			),
			array( 'id' => $op )
		);
		self::result( (int) $enrollment['id'], 'linked' );
		Audit::log( 'system', 0, 'operator.linked', 'operator', $op );
		return 'linked';
	}

	public static function bind_destination( array $enrollment, string $chat_id, string $title ): string {
		$team_id = (int) $enrollment['team_id'];
		if ( $team_id ) {
			Db::update(
				'teams',
				array(
					'destination_chat_id' => $chat_id,
					'destination_title'   => $title,
				),
				array( 'id' => $team_id )
			);
		} else {
			Settings::update(
				array(
					'default_destination_chat'  => $chat_id,
					'default_destination_title' => $title,
				)
			);
		}
		self::result( (int) $enrollment['id'], 'bound' );
		Audit::log( 'system', 0, 'destination.bound', 'team', $team_id, array( 'chat' => $chat_id ) );
		return 'bound';
	}

	private static function result( int $id, string $result ): void {
		Db::update( 'enrollments', array( 'result' => $result ), array( 'id' => $id ) );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function status( int $id ): ?array {
		return Db::row( 'SELECT id, purpose, operator_id, team_id, expires_at, used_at, result FROM ' . Db::t( 'enrollments' ) . ' WHERE id = %d', $id );
	}
}
