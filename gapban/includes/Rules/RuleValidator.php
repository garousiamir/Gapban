<?php
/**
 * Validates and normalizes targeting/automation rule definitions.
 *
 * Rules are declarative data only: there is no expression language and nothing
 * executable. Evaluation happens in the widget (src/shared/rules.ts) and in the
 * admin preview using the same evaluator; permissions never depend on rules.
 *
 * @package Gapban
 */

namespace Gapban\Rules;

use Gapban\Core\Locale;

defined( 'ABSPATH' ) || exit;

final class RuleValidator {

	public const MAX_DEPTH      = 4;
	public const MAX_CONDITIONS = 60;

	/**
	 * condition type => value kind.
	 */
	public const CONDITIONS = array(
		'site'               => 'none',
		'page_id'            => 'ids',
		'post_type'          => 'slugs',
		'template'           => 'strings',
		'taxonomy_term'      => 'taxonomy',
		'page_kind'          => 'enum:front,home,archive,search,404,singular,page,post',
		'url'                => 'url',
		'query_param'        => 'query',
		'logged_in'          => 'bool',
		'role'               => 'slugs',
		'device'             => 'enum:mobile,tablet,desktop',
		'viewport_width'     => 'compare',
		'language'           => 'strings',
		'returning_visitor'  => 'bool',
		'referrer'           => 'referrer',
		'utm'                => 'query',
		'availability'       => 'enum:online,away,offline',
		'business_hours'     => 'bool',
		'campaign_seen'      => 'campaign',
		'conversation_state' => 'enum:none,active,closed',
		'chat_opened'        => 'bool',
		'wc_page'            => 'enum:product,product_category,shop,cart,checkout,account',
		'wc_product_cat'     => 'ids',
		'wc_cart_count'      => 'compare',
		'wc_cart_value'      => 'compare',
	);

	public const TRIGGERS = array( 'immediate', 'time_on_page', 'scroll_depth', 'inactivity', 'exit_intent' );

	public const ACTIONS = array( 'show_launcher', 'hide_launcher', 'show_teaser', 'open_chat', 'automated_message', 'select_department', 'greeting_variant' );

	/**
	 * @param mixed $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function validate( $input ) {
		if ( ! is_array( $input ) ) {
			return self::error( 'definition_not_object' );
		}
		$count = 0;

		$name = mb_substr( sanitize_text_field( (string) ( $input['name'] ?? '' ) ), 0, 190 );
		if ( '' === $name ) {
			return self::error( 'name_required' );
		}

		$conditions = self::group( $input['conditions'] ?? array( 'op' => 'and', 'items' => array( array( 'type' => 'site' ) ) ), 1, $count );
		if ( is_wp_error( $conditions ) ) {
			return $conditions;
		}
		$exclusions = self::group( $input['exclusions'] ?? array( 'op' => 'or', 'items' => array() ), 1, $count );
		if ( is_wp_error( $exclusions ) ) {
			return $exclusions;
		}

		$trigger_in = is_array( $input['trigger'] ?? null ) ? $input['trigger'] : array();
		$type       = (string) ( $trigger_in['type'] ?? 'immediate' );
		if ( ! in_array( $type, self::TRIGGERS, true ) ) {
			return self::error( 'invalid_trigger' );
		}
		$trigger = array(
			'type'    => $type,
			'seconds' => max( 0, min( 3600, self::int( $trigger_in['seconds'] ?? 0 ) ) ),
			'percent' => max( 1, min( 100, self::int( $trigger_in['percent'] ?? 50 ) ) ),
		);

		$actions = array();
		foreach ( (array) ( $input['actions'] ?? array() ) as $a ) {
			if ( ! is_array( $a ) || ! in_array( $a['type'] ?? '', self::ACTIONS, true ) ) {
				return self::error( 'invalid_action' );
			}
			$action = array( 'type' => $a['type'] );
			if ( in_array( $a['type'], array( 'show_teaser', 'automated_message' ), true ) ) {
				$action['text'] = mb_substr( sanitize_textarea_field( (string) ( $a['text'] ?? '' ) ), 0, 500 );
				if ( '' === $action['text'] ) {
					return self::error( 'action_text_required' );
				}
			}
			if ( 'select_department' === $a['type'] ) {
				$action['team_id'] = self::int( $a['team_id'] ?? 0 );
			}
			if ( 'greeting_variant' === $a['type'] ) {
				$action['title']    = mb_substr( sanitize_text_field( (string) ( $a['title'] ?? '' ) ), 0, 190 );
				$action['subtitle'] = mb_substr( sanitize_text_field( (string) ( $a['subtitle'] ?? '' ) ), 0, 190 );
			}
			$actions[] = $action;
		}
		if ( ! $actions ) {
			return self::error( 'actions_required' );
		}
		$types = array_column( $actions, 'type' );
		if ( in_array( 'show_launcher', $types, true ) && in_array( 'hide_launcher', $types, true ) ) {
			return self::error( 'conflicting_actions' );
		}

		$f         = is_array( $input['frequency'] ?? null ) ? $input['frequency'] : array();
		$expires   = (string) ( $f['expires_at'] ?? '' );
		$frequency = array(
			'per_page'           => max( 0, min( 100, self::int( $f['per_page'] ?? 1 ) ) ),
			'per_session'        => max( 0, min( 100, self::int( $f['per_session'] ?? 1 ) ) ),
			'per_visitor'        => max( 0, min( 1000, self::int( $f['per_visitor'] ?? 0 ) ) ),
			'cooldown_hours'     => max( 0, min( 8760, self::int( $f['cooldown_hours'] ?? 24 ) ) ),
			'expires_at'         => ( '' !== $expires && strtotime( $expires ) ) ? gmdate( 'c', (int) strtotime( $expires ) ) : '',
			'stop_after_dismiss' => ! isset( $f['stop_after_dismiss'] ) || (bool) $f['stop_after_dismiss'],
		);

		return array(
			'name'       => $name,
			'enabled'    => ! isset( $input['enabled'] ) || (bool) $input['enabled'],
			'priority'   => max( -1000, min( 1000, self::int( $input['priority'] ?? 10 ) ) ),
			'conditions' => $conditions,
			'exclusions' => $exclusions,
			'trigger'    => $trigger,
			'actions'    => $actions,
			'frequency'  => $frequency,
			'mobile'     => in_array( $input['mobile'] ?? 'allow', array( 'allow', 'skip' ), true ) ? ( $input['mobile'] ?? 'allow' ) : 'allow',
		);
	}

	/**
	 * @param mixed $g
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function group( $g, int $depth, int &$count ) {
		if ( $depth > self::MAX_DEPTH ) {
			return self::error( 'too_deep' );
		}
		if ( ! is_array( $g ) ) {
			return self::error( 'invalid_group' );
		}
		$op = in_array( $g['op'] ?? 'and', array( 'and', 'or' ), true ) ? $g['op'] : 'and';
		$items = array();
		foreach ( (array) ( $g['items'] ?? array() ) as $item ) {
			if ( ++$count > self::MAX_CONDITIONS ) {
				return self::error( 'too_many_conditions' );
			}
			if ( is_array( $item ) && isset( $item['items'] ) ) {
				$sub = self::group( $item, $depth + 1, $count );
				if ( is_wp_error( $sub ) ) {
					return $sub;
				}
				$items[] = $sub;
				continue;
			}
			$c = self::condition( $item );
			if ( is_wp_error( $c ) ) {
				return $c;
			}
			$items[] = $c;
		}
		return array(
			'op'    => $op,
			'items' => $items,
		);
	}

	/**
	 * @param mixed $c
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function condition( $c ) {
		if ( ! is_array( $c ) || ! isset( self::CONDITIONS[ $c['type'] ?? '' ] ) ) {
			return self::error( 'invalid_condition' );
		}
		$type = $c['type'];
		$kind = self::CONDITIONS[ $type ];
		$out  = array(
			'type' => $type,
			'not'  => ! empty( $c['not'] ),
		);
		$v    = $c['value'] ?? null;

		if ( str_starts_with( $kind, 'enum:' ) ) {
			$allowed = explode( ',', substr( $kind, 5 ) );
			$values  = array_values( array_intersect( $allowed, array_map( 'strval', (array) $v ) ) );
			if ( ! $values ) {
				return self::error( 'condition_value_required' );
			}
			$out['value'] = $values;
			return $out;
		}

		switch ( $kind ) {
			case 'none':
				break;
			case 'bool':
				$out['value'] = (bool) filter_var( $v, FILTER_VALIDATE_BOOLEAN );
				break;
			case 'ids':
				$out['value'] = array_slice( array_values( array_unique( array_filter( array_map( static fn( $x ) => self::int( $x ), (array) $v ) ) ) ), 0, 500 );
				if ( ! $out['value'] ) {
					return self::error( 'condition_value_required' );
				}
				break;
			case 'slugs':
				$out['value'] = array_slice( array_values( array_filter( array_map( static fn( $x ) => sanitize_key( (string) $x ), (array) $v ) ) ), 0, 100 );
				if ( ! $out['value'] ) {
					return self::error( 'condition_value_required' );
				}
				break;
			case 'strings':
				$out['value'] = array_slice( array_values( array_filter( array_map( static fn( $x ) => mb_substr( sanitize_text_field( (string) $x ), 0, 190 ), (array) $v ) ) ), 0, 100 );
				if ( ! $out['value'] ) {
					return self::error( 'condition_value_required' );
				}
				break;
			case 'taxonomy':
				$out['taxonomy'] = sanitize_key( (string) ( $c['taxonomy'] ?? '' ) );
				$out['value']    = array_slice( array_values( array_filter( array_map( static fn( $x ) => self::int( $x ), (array) $v ) ) ), 0, 500 );
				if ( '' === $out['taxonomy'] || ! $out['value'] ) {
					return self::error( 'condition_value_required' );
				}
				break;
			case 'url':
				$match = in_array( $c['match'] ?? 'path_wildcard', array( 'exact', 'path_wildcard', 'contains' ), true ) ? ( $c['match'] ?? 'path_wildcard' ) : 'path_wildcard';
				$value = mb_substr( trim( (string) $v ), 0, 300 );
				if ( '' === $value || preg_match( '/[\s<>"\'`]/', $value ) ) {
					return self::error( 'invalid_url_pattern' );
				}
				$out['match'] = $match;
				$out['value'] = $value;
				break;
			case 'query':
				$key = sanitize_key( (string) ( $c['key'] ?? '' ) );
				if ( '' === $key ) {
					return self::error( 'condition_value_required' );
				}
				$out['key']   = $key;
				$out['match'] = in_array( $c['match'] ?? 'equals', array( 'exists', 'equals', 'contains' ), true ) ? ( $c['match'] ?? 'equals' ) : 'equals';
				$out['value'] = mb_substr( sanitize_text_field( (string) $v ), 0, 190 );
				break;
			case 'referrer':
				$out['match'] = in_array( $c['match'] ?? 'contains', array( 'contains', 'host', 'none' ), true ) ? ( $c['match'] ?? 'contains' ) : 'contains';
				$out['value'] = mb_substr( sanitize_text_field( (string) $v ), 0, 190 );
				break;
			case 'compare':
				$out['compare'] = in_array( $c['compare'] ?? 'gte', array( 'gt', 'gte', 'lt', 'lte', 'eq' ), true ) ? ( $c['compare'] ?? 'gte' ) : 'gte';
				$num            = Locale::normalize_digits( (string) $v );
				if ( ! is_numeric( $num ) ) {
					return self::error( 'condition_number_required' );
				}
				$out['value'] = (float) $num;
				break;
			case 'campaign':
				$out['rule_id']      = self::int( $c['rule_id'] ?? 0 );
				$out['within_hours'] = max( 1, min( 8760, self::int( $v ?? 24 ) ) );
				$out['value']        = $out['within_hours'];
				break;
		}
		return $out;
	}

	/**
	 * @param mixed $v
	 */
	private static function int( $v ): int {
		return (int) Locale::normalize_digits( (string) ( is_scalar( $v ) ? $v : 0 ) );
	}

	private static function error( string $code ): \WP_Error {
		$messages = array(
			'definition_not_object'     => __( 'The rule is not a valid object.', 'gapban' ),
			'name_required'             => __( 'The rule needs a name.', 'gapban' ),
			'invalid_trigger'           => __( 'Unknown trigger.', 'gapban' ),
			'invalid_action'            => __( 'Unknown action.', 'gapban' ),
			'action_text_required'      => __( 'This action needs a message text.', 'gapban' ),
			'actions_required'          => __( 'Add at least one action.', 'gapban' ),
			'conflicting_actions'       => __( 'A rule cannot both show and hide the launcher.', 'gapban' ),
			'too_deep'                  => __( 'Condition groups are nested too deeply (max 4 levels).', 'gapban' ),
			'invalid_group'             => __( 'Invalid condition group.', 'gapban' ),
			'too_many_conditions'       => __( 'Too many conditions (max 60).', 'gapban' ),
			'invalid_condition'         => __( 'Unknown condition type.', 'gapban' ),
			'condition_value_required'  => __( 'A condition is missing its value.', 'gapban' ),
			'invalid_url_pattern'       => __( 'The URL pattern is invalid.', 'gapban' ),
			'condition_number_required' => __( 'A numeric condition needs a number.', 'gapban' ),
		);
		return new \WP_Error( 'gapban_rule_' . $code, $messages[ $code ] ?? $code, array( 'status' => 422 ) );
	}
}
