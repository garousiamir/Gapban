<?php
/**
 * Collision-safe assignment: manual, round-robin and least-loaded strategies,
 * claim and transfer. All writes are compare-and-set on the conversation row.
 *
 * @package Gapban
 */

namespace Gapban\Chat;

use Gapban\Domain\ConversationState;
use Gapban\Persistence\Db;

defined( 'ABSPATH' ) || exit;

final class Assignment {

	/**
	 * Claim an unassigned conversation. Returns false if someone else got it first.
	 */
	public static function claim( int $conversation_id, int $operator_id, string $actor_type, int $actor_id ): bool {
		$n = Db::exec(
			'UPDATE ' . Db::t( 'conversations' ) . " SET assigned_operator_id = %d, assigned_at = %s,
			 status = IF(status = 'queued', 'open', status), version = version + 1, updated_at = %s
			 WHERE id = %d AND assigned_operator_id IS NULL AND status NOT IN ('spam')",
			$operator_id,
			Db::now(),
			Db::now(),
			$conversation_id
		);
		if ( 1 === $n ) {
			Db::update( 'operators', array( 'last_assigned_at' => Db::now() ), array( 'id' => $operator_id ) );
			Audit::log( $actor_type, $actor_id, 'conversation.claimed', 'conversation', $conversation_id, array( 'operator' => $operator_id ) );
			return true;
		}
		return false;
	}

	/**
	 * Transfer from $from_operator (null = unassigned) to $to_operator. Fails if the
	 * current assignee changed meanwhile.
	 */
	public static function transfer( int $conversation_id, ?int $from_operator, ?int $to_operator, string $actor_type, int $actor_id, ?int $team_id = null ): bool {
		$set_team = null !== $team_id ? Db::t( 'conversations' ) . '.team_id' : '';
		$sql      = 'UPDATE ' . Db::t( 'conversations' ) . ' SET assigned_operator_id = ' . ( $to_operator ? '%d' : 'NULL' ) . ', assigned_at = %s,
			status = CASE WHEN status IN (\'spam\',\'closed\') THEN status WHEN ' . ( $to_operator ? '1' : '0' ) . " = 1 THEN IF(status = 'queued', 'open', status) ELSE 'queued' END,"
			. ( $set_team ? ' team_id = %d,' : '' ) . ' version = version + 1, updated_at = %s WHERE id = %d AND ' . ( $from_operator ? 'assigned_operator_id = %d' : 'assigned_operator_id IS NULL' );
		$args = array();
		if ( $to_operator ) {
			$args[] = $to_operator;
		}
		$args[] = Db::now();
		if ( $set_team ) {
			$args[] = $team_id;
		}
		$args[] = Db::now();
		$args[] = $conversation_id;
		if ( $from_operator ) {
			$args[] = $from_operator;
		}
		$n = Db::exec( $sql, ...$args );
		if ( 1 === $n ) {
			Audit::log(
				$actor_type,
				$actor_id,
				'conversation.transferred',
				'conversation',
				$conversation_id,
				array(
					'from' => $from_operator,
					'to'   => $to_operator,
					'team' => $team_id,
				)
			);
			return true;
		}
		return false;
	}

	/**
	 * Apply the team's automatic strategy to a new conversation.
	 */
	public static function auto_assign( int $conversation_id ): ?int {
		$conv = ConversationService::get( $conversation_id );
		if ( ! $conv || ! empty( $conv['assigned_operator_id'] ) || ! ConversationState::is_active( (string) $conv['status'] ) ) {
			return null;
		}
		$team = $conv['team_id'] ? Db::row( 'SELECT * FROM ' . Db::t( 'teams' ) . ' WHERE id = %d', (int) $conv['team_id'] ) : null;
		if ( ! $team || 'manual' === $team['assignment_strategy'] ) {
			return null;
		}
		$candidates = Availability::available_operator_ids( (int) $team['id'] );
		if ( ! $candidates ) {
			return null;
		}
		$ordered = 'round_robin' === $team['assignment_strategy']
			? self::round_robin_order( (int) $team['id'], $candidates )
			: self::least_loaded_order( $candidates );

		foreach ( $ordered as $operator_id ) {
			if ( self::claim( $conversation_id, $operator_id, 'system', 0 ) ) {
				if ( 'round_robin' === $team['assignment_strategy'] ) {
					Db::update( 'teams', array( 'rr_cursor' => $operator_id ), array( 'id' => (int) $team['id'] ) );
				}
				return $operator_id;
			}
			return null; // Someone claimed it concurrently.
		}
		return null;
	}

	/**
	 * @param int[] $candidates
	 * @return int[]
	 */
	public static function round_robin_order( int $team_id, array $candidates ): array {
		sort( $candidates );
		$cursor = (int) Db::value( 'SELECT rr_cursor FROM ' . Db::t( 'teams' ) . ' WHERE id = %d', $team_id );
		$after  = array_values( array_filter( $candidates, static fn( $id ) => $id > $cursor ) );
		$before = array_values( array_filter( $candidates, static fn( $id ) => $id <= $cursor ) );
		return array_values( array_filter( self::under_capacity( array_merge( $after, $before ) ) ) );
	}

	/**
	 * @param int[] $candidates
	 * @return int[]
	 */
	public static function least_loaded_order( array $candidates ): array {
		$loads = self::loads( $candidates );
		$ids   = self::under_capacity( $candidates );
		usort(
			$ids,
			static fn( $a, $b ) => ( $loads[ $a ] ?? 0 ) <=> ( $loads[ $b ] ?? 0 ) ?: $a <=> $b
		);
		return $ids;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, int>
	 */
	private static function loads( array $ids ): array {
		if ( ! $ids ) {
			return array();
		}
		$rows = Db::rows(
			'SELECT assigned_operator_id AS id, COUNT(*) AS n FROM ' . Db::t( 'conversations' ) . " WHERE status IN ('queued','open','waiting') AND assigned_operator_id IN (" . Db::in( $ids ) . ') GROUP BY assigned_operator_id',
			...$ids
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['id'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function under_capacity( array $ids ): array {
		if ( ! $ids ) {
			return array();
		}
		$loads = self::loads( $ids );
		$caps  = array();
		foreach ( Db::rows( 'SELECT id, max_concurrent FROM ' . Db::t( 'operators' ) . ' WHERE id IN (' . Db::in( $ids ) . ')', ...$ids ) as $r ) {
			$caps[ (int) $r['id'] ] = (int) $r['max_concurrent'];
		}
		return array_values( array_filter( $ids, static fn( $id ) => ( $loads[ $id ] ?? 0 ) < max( 1, $caps[ $id ] ?? 5 ) ) );
	}
}
