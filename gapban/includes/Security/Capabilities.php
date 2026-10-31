<?php
/**
 * Granular server-side capabilities.
 *
 * @package Gapban
 */

namespace Gapban\Security;

use Gapban\Persistence\Db;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	public const MANAGE_SETTINGS = 'gapban_manage_settings';
	public const VIEW_ASSIGNED   = 'gapban_view_assigned';
	public const VIEW_ALL        = 'gapban_view_all';
	public const REPLY           = 'gapban_reply';
	public const ASSIGN          = 'gapban_assign';
	public const REPORT          = 'gapban_view_reports';
	public const EXPORT          = 'gapban_export';
	public const DELETE          = 'gapban_delete';
	public const DIAGNOSTICS     = 'gapban_diagnostics';

	public const ALL = array(
		self::MANAGE_SETTINGS,
		self::VIEW_ASSIGNED,
		self::VIEW_ALL,
		self::REPLY,
		self::ASSIGN,
		self::REPORT,
		self::EXPORT,
		self::DELETE,
		self::DIAGNOSTICS,
	);

	public const OPERATOR_ROLE = 'gapban_operator';

	public static function install(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::ALL as $cap ) {
				$admin->add_cap( $cap );
			}
		}
		if ( ! get_role( self::OPERATOR_ROLE ) ) {
			add_role(
				self::OPERATOR_ROLE,
				'Gapban Operator',
				array(
					'read'              => true,
					self::VIEW_ASSIGNED => true,
					self::REPLY         => true,
				)
			);
		}
	}

	public static function uninstall(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::ALL as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		remove_role( self::OPERATOR_ROLE );
	}

	public static function any(): bool {
		foreach ( self::ALL as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The operator record linked to the current WordPress user, if any.
	 */
	public static function current_operator_id(): int {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return 0;
		}
		return (int) Db::value( 'SELECT id FROM ' . Db::t( 'operators' ) . " WHERE wp_user_id = %d AND status = 'active' LIMIT 1", $uid );
	}

	/**
	 * Can the current user see this conversation?
	 *
	 * @param array<string, mixed> $conversation
	 */
	public static function can_view_conversation( array $conversation ): bool {
		if ( current_user_can( self::VIEW_ALL ) ) {
			return true;
		}
		if ( ! current_user_can( self::VIEW_ASSIGNED ) ) {
			return false;
		}
		$op = self::current_operator_id();
		if ( ! $op ) {
			return false;
		}
		if ( (int) $conversation['assigned_operator_id'] === $op ) {
			return true;
		}
		// Unassigned conversations in the operator's teams are visible so they can be claimed.
		if ( empty( $conversation['assigned_operator_id'] ) && ! empty( $conversation['team_id'] ) ) {
			return (bool) Db::value( 'SELECT 1 FROM ' . Db::t( 'team_members' ) . ' WHERE team_id = %d AND operator_id = %d', (int) $conversation['team_id'], $op );
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $conversation
	 */
	public static function can_reply( array $conversation ): bool {
		return current_user_can( self::REPLY ) && self::can_view_conversation( $conversation );
	}
}
