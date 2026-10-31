<?php
/**
 * Business hours, holidays, temporary away and operator presence.
 *
 * Presence comes only from explicit operator settings (WordPress or the /online,
 * /away commands in Bale) or an expiring WordPress-admin heartbeat. Nothing here
 * pretends an operator is online.
 *
 * @package Gapban
 */

namespace Gapban\Chat;

use Gapban\Core\Locale;
use Gapban\Core\Settings;
use Gapban\Persistence\Db;

defined( 'ABSPATH' ) || exit;

final class Availability {

	public const HEARTBEAT_TTL = 120;

	public const ONLINE  = 'online';
	public const AWAY    = 'away';
	public const OFFLINE = 'offline';

	private const DAYS = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );

	/**
	 * Overall widget state.
	 *
	 * @return array{state:string, reason:string, next_open:string}
	 */
	public static function state( ?int $team_id = null, ?\DateTimeImmutable $at = null ): array {
		$at = $at ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

		$away_until = (string) Settings::get( 'temporary_away_until' );
		if ( '' !== $away_until && strtotime( $away_until . ' UTC' ) > $at->getTimestamp() ) {
			return array(
				'state'     => self::AWAY,
				'reason'    => 'temporary_away',
				'next_open' => $away_until,
			);
		}
		if ( self::is_holiday( $at ) ) {
			return array(
				'state'     => self::OFFLINE,
				'reason'    => 'holiday',
				'next_open' => '',
			);
		}
		if ( Settings::get( 'business_hours_enabled' ) && ! self::within_hours( (array) Settings::get( 'business_hours' ), $at ) ) {
			return array(
				'state'     => self::OFFLINE,
				'reason'    => 'outside_hours',
				'next_open' => '',
			);
		}
		if ( self::available_operator_count( $team_id, $at ) > 0 ) {
			return array(
				'state'     => self::ONLINE,
				'reason'    => 'operators_available',
				'next_open' => '',
			);
		}
		return array(
			'state'     => self::AWAY,
			'reason'    => 'no_operator_available',
			'next_open' => '',
		);
	}

	public static function is_holiday( \DateTimeImmutable $at ): bool {
		$local = $at->setTimezone( Locale::timezone() )->format( 'Y-m-d' );
		foreach ( (array) Settings::get( 'holidays' ) as $h ) {
			if ( ( $h['date'] ?? '' ) === $local ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, array<int, array{0:string,1:string}>> $hours
	 */
	public static function within_hours( array $hours, \DateTimeImmutable $at ): bool {
		$local = $at->setTimezone( Locale::timezone() );
		$day   = self::DAYS[ (int) $local->format( 'w' ) ];
		$time  = $local->format( 'H:i' );
		foreach ( (array) ( $hours[ $day ] ?? array() ) as $range ) {
			if ( $time >= $range[0] && $time < $range[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Operators explicitly online (and not on an expired away), or with a fresh admin heartbeat.
	 */
	public static function available_operator_count( ?int $team_id = null, ?\DateTimeImmutable $at = null ): int {
		return count( self::available_operator_ids( $team_id, $at ) );
	}

	/**
	 * @return int[]
	 */
	public static function available_operator_ids( ?int $team_id = null, ?\DateTimeImmutable $at = null ): array {
		$at        = $at ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$o         = Db::t( 'operators' );
		$heartbeat = gmdate( 'Y-m-d H:i:s', $at->getTimestamp() - self::HEARTBEAT_TTL );
		$now       = $at->format( 'Y-m-d H:i:s' );
		$join      = '';
		$args      = array( $now, $heartbeat );
		if ( $team_id && Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'team_members' ) . ' WHERE team_id = %d', $team_id ) ) {
			$join   = ' JOIN ' . Db::t( 'team_members' ) . ' tm ON tm.operator_id = o.id AND tm.team_id = %d';
			$args   = array( $team_id, $now, $heartbeat );
		}
		$rows = Db::rows(
			"SELECT o.id, o.schedule FROM {$o} o{$join}
			 WHERE o.status = 'active' AND (
			   (o.availability = 'online' AND (o.away_until IS NULL OR o.away_until < %s))
			   OR (o.availability = 'auto' AND o.heartbeat_at >= %s)
			 )",
			...$args
		);
		$ids = array();
		foreach ( $rows as $r ) {
			$schedule = $r['schedule'] ? json_decode( (string) $r['schedule'], true ) : null;
			if ( is_array( $schedule ) && $schedule && ! self::within_hours( $schedule, $at ) ) {
				continue;
			}
			$ids[] = (int) $r['id'];
		}
		return $ids;
	}
}
