<?php
/**
 * Reporting metrics. Formulas are documented in docs/reports.md.
 *
 * Population: conversations created in [from, to) excluding spam.
 * Human response metrics use conversations.first_human_response_at, which is set
 * only by operator messages — automated greetings and system events never set it.
 *
 * @package Gapban
 */

namespace Gapban\Reporting;

use Gapban\Persistence\Db;

defined( 'ABSPATH' ) || exit;

final class Reports {

	/**
	 * @return array<string, mixed>
	 */
	public static function summary( string $from_utc, string $to_utc ): array {
		$c = Db::t( 'conversations' );

		$total = (int) Db::value( "SELECT COUNT(*) FROM {$c} WHERE created_at >= %s AND created_at < %s AND status <> 'spam'", $from_utc, $to_utc );
		$unans = (int) Db::value( "SELECT COUNT(*) FROM {$c} WHERE created_at >= %s AND created_at < %s AND status <> 'spam' AND first_human_response_at IS NULL", $from_utc, $to_utc );
		$spam  = (int) Db::value( "SELECT COUNT(*) FROM {$c} WHERE created_at >= %s AND created_at < %s AND status = 'spam'", $from_utc, $to_utc );

		$frt   = self::durations( "SELECT TIMESTAMPDIFF(SECOND, created_at, first_human_response_at) AS d FROM {$c} WHERE created_at >= %s AND created_at < %s AND status <> 'spam' AND first_human_response_at IS NOT NULL", $from_utc, $to_utc );
		$res   = self::durations( "SELECT TIMESTAMPDIFF(SECOND, created_at, closed_at) AS d FROM {$c} WHERE created_at >= %s AND created_at < %s AND status = 'closed' AND closed_at IS NOT NULL", $from_utc, $to_utc );
		$wait  = self::durations( "SELECT TIMESTAMPDIFF(SECOND, created_at, assigned_at) AS d FROM {$c} WHERE created_at >= %s AND created_at < %s AND status <> 'spam' AND assigned_at IS NOT NULL", $from_utc, $to_utc );

		$r       = Db::t( 'ratings' );
		$ratings = Db::row( "SELECT COUNT(*) AS n, AVG(r.score) AS avg_score, SUM(r.score >= 4) AS satisfied FROM {$r} r JOIN {$c} c ON c.id = r.conversation_id WHERE c.created_at >= %s AND c.created_at < %s", $from_utc, $to_utc );

		$daily = Db::rows( "SELECT DATE(created_at) AS day, COUNT(*) AS n, SUM(first_human_response_at IS NULL) AS unanswered FROM {$c} WHERE created_at >= %s AND created_at < %s AND status <> 'spam' GROUP BY DATE(created_at) ORDER BY day", $from_utc, $to_utc );

		return array(
			'range'               => array( 'from' => $from_utc, 'to' => $to_utc ),
			'volume'              => $total,
			'spam'                => $spam,
			'unanswered'          => $unans,
			'unanswered_rate'     => $total ? round( $unans / $total, 4 ) : null,
			'first_response'      => $frt,
			'resolution'          => $res,
			'queue_wait'          => $wait,
			'csat'                => array(
				'responses'     => (int) $ratings['n'],
				'average'       => null !== $ratings['avg_score'] ? round( (float) $ratings['avg_score'], 2 ) : null,
				'satisfied_pct' => (int) $ratings['n'] ? round( (int) $ratings['satisfied'] / (int) $ratings['n'], 4 ) : null,
			),
			'daily'               => array_map(
				static fn( $d ) => array( 'day' => $d['day'], 'conversations' => (int) $d['n'], 'unanswered' => (int) $d['unanswered'] ),
				$daily
			),
			'operators'           => self::operators( $from_utc, $to_utc ),
			'teams'               => self::teams( $from_utc, $to_utc ),
			'campaigns'           => self::campaigns( $from_utc, $to_utc ),
		);
	}

	/**
	 * @return array{count:int, average:?int, median:?int, p90:?int}
	 */
	private static function durations( string $sql, string $from, string $to ): array {
		$rows   = Db::rows( $sql . ' LIMIT 20000', $from, $to );
		$values = array_map( static fn( $r ) => max( 0, (int) $r['d'] ), $rows );
		sort( $values );
		$n = count( $values );
		if ( ! $n ) {
			return array( 'count' => 0, 'average' => null, 'median' => null, 'p90' => null );
		}
		$median = 0 === $n % 2 ? (int) round( ( $values[ $n / 2 - 1 ] + $values[ $n / 2 ] ) / 2 ) : $values[ intdiv( $n, 2 ) ];
		return array(
			'count'   => $n,
			'average' => (int) round( array_sum( $values ) / $n ),
			'median'  => $median,
			'p90'     => $values[ min( $n - 1, (int) ceil( 0.9 * $n ) - 1 ) ],
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function operators( string $from, string $to ): array {
		$c = Db::t( 'conversations' );
		$m = Db::t( 'messages' );
		$r = Db::t( 'ratings' );
		$o = Db::t( 'operators' );
		$rows = Db::rows(
			"SELECT o.id, o.display_name,
			  (SELECT COUNT(*) FROM {$c} c WHERE c.assigned_operator_id = o.id AND c.created_at >= %s AND c.created_at < %s AND c.status <> 'spam') AS assigned,
			  (SELECT COUNT(*) FROM {$m} m WHERE m.author_type = 'operator' AND m.author_id = o.id AND m.is_internal = 0 AND m.created_at >= %s AND m.created_at < %s) AS replies,
			  (SELECT AVG(TIMESTAMPDIFF(SECOND, c.created_at, c.first_human_response_at)) FROM {$c} c WHERE c.assigned_operator_id = o.id AND c.first_human_response_at IS NOT NULL AND c.created_at >= %s AND c.created_at < %s) AS avg_first_response,
			  (SELECT AVG(r.score) FROM {$r} r JOIN {$c} c ON c.id = r.conversation_id WHERE r.operator_id = o.id AND c.created_at >= %s AND c.created_at < %s) AS csat,
			  (SELECT COUNT(*) FROM {$c} c WHERE c.assigned_operator_id = o.id AND c.status = 'closed' AND c.closed_at >= %s AND c.closed_at < %s) AS resolved
			 FROM {$o} o ORDER BY o.display_name",
			$from, $to, $from, $to, $from, $to, $from, $to, $from, $to
		);
		return array_map(
			static fn( $x ) => array(
				'id'                 => (int) $x['id'],
				'name'               => $x['display_name'],
				'assigned'           => (int) $x['assigned'],
				'replies'            => (int) $x['replies'],
				'resolved'           => (int) $x['resolved'],
				'avg_first_response' => null !== $x['avg_first_response'] ? (int) round( (float) $x['avg_first_response'] ) : null,
				'csat'               => null !== $x['csat'] ? round( (float) $x['csat'], 2 ) : null,
			),
			$rows
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function teams( string $from, string $to ): array {
		$c    = Db::t( 'conversations' );
		$rows = Db::rows(
			"SELECT t.id, t.name, COUNT(c.id) AS volume, SUM(c.id IS NOT NULL AND c.first_human_response_at IS NULL) AS unanswered,
			  AVG(TIMESTAMPDIFF(SECOND, c.created_at, c.first_human_response_at)) AS avg_first_response
			 FROM " . Db::t( 'teams' ) . " t LEFT JOIN {$c} c ON c.team_id = t.id AND c.created_at >= %s AND c.created_at < %s AND c.status <> 'spam'
			 GROUP BY t.id, t.name ORDER BY t.sort_order, t.id",
			$from,
			$to
		);
		return array_map(
			static fn( $x ) => array(
				'id'                 => (int) $x['id'],
				'name'               => $x['name'],
				'volume'             => (int) $x['volume'],
				'unanswered'         => (int) $x['unanswered'],
				'avg_first_response' => null !== $x['avg_first_response'] ? (int) round( (float) $x['avg_first_response'] ) : null,
			),
			$rows
		);
	}

	/**
	 * Campaign conversion = conversations started from the campaign / times shown.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function campaigns( string $from, string $to ): array {
		$e    = Db::t( 'rule_events' );
		$rows = Db::rows(
			"SELECT r.id, r.name,
			  SUM(e.event = 'shown') AS shown, SUM(e.event = 'clicked') AS clicked,
			  SUM(e.event = 'dismissed') AS dismissed, SUM(e.event = 'converted') AS converted
			 FROM " . Db::t( 'rules' ) . " r LEFT JOIN {$e} e ON e.rule_id = r.id AND e.created_at >= %s AND e.created_at < %s
			 GROUP BY r.id, r.name ORDER BY r.priority DESC, r.id",
			$from,
			$to
		);
		return array_map(
			static function ( $x ) {
				$shown = (int) $x['shown'];
				return array(
					'id'         => (int) $x['id'],
					'name'       => $x['name'],
					'shown'      => $shown,
					'clicked'    => (int) $x['clicked'],
					'dismissed'  => (int) $x['dismissed'],
					'converted'  => (int) $x['converted'],
					'conversion' => $shown ? round( (int) $x['converted'] / $shown, 4 ) : null,
				);
			},
			$rows
		);
	}
}
