<?php
/**
 * Rule storage and trigger execution records (shown/clicked/dismissed/converted).
 *
 * @package Gapban
 */

namespace Gapban\Rules;

use Gapban\Persistence\Db;

defined( 'ABSPATH' ) || exit;

final class RuleEvents {

	public static function record( int $rule_id, ?int $visitor_id, string $event ): void {
		if ( ! Db::value( 'SELECT 1 FROM ' . Db::t( 'rules' ) . ' WHERE id = %d', $rule_id ) ) {
			return;
		}
		Db::insert(
			'rule_events',
			array(
				'rule_id'    => $rule_id,
				'visitor_id' => $visitor_id,
				'event'      => $event,
				'created_at' => Db::now(),
			)
		);
	}

	/**
	 * Enabled rules for the widget, ordered by deterministic precedence:
	 * priority descending, then id ascending.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function active_rules(): array {
		$cached = wp_cache_get( 'active_rules', 'gapban' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$rows = Db::rows( 'SELECT id, priority, definition FROM ' . Db::t( 'rules' ) . ' WHERE enabled = 1 ORDER BY priority DESC, id ASC LIMIT 100' );
		$out  = array();
		foreach ( $rows as $r ) {
			$def = json_decode( (string) $r['definition'], true );
			if ( is_array( $def ) ) {
				$def['id'] = (int) $r['id'];
				$out[]     = $def;
			}
		}
		wp_cache_set( 'active_rules', $out, 'gapban', 300 );
		return $out;
	}

	public static function flush(): void {
		wp_cache_delete( 'active_rules', 'gapban' );
	}

	/**
	 * @param array<string, mixed> $def validated definition
	 */
	public static function save( array $def, int $id = 0 ): int {
		$data = array(
			'name'       => $def['name'],
			'enabled'    => $def['enabled'] ? 1 : 0,
			'priority'   => $def['priority'],
			'definition' => wp_json_encode( $def ),
			'updated_at' => Db::now(),
		);
		if ( $id ) {
			Db::exec( 'UPDATE ' . Db::t( 'rules' ) . ' SET version = version + 1 WHERE id = %d', $id );
			Db::update( 'rules', $data, array( 'id' => $id ) );
		} else {
			$data['created_at'] = Db::now();
			$id                 = Db::insert( 'rules', $data );
		}
		self::flush();
		return $id;
	}
}
