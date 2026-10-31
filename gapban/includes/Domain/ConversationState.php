<?php
/**
 * Conversation state machine.
 *
 * @package Gapban
 */

namespace Gapban\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * States:
 *   queued  – created, no human has replied or claimed yet.
 *   open    – a human operator is assigned or has replied; ball is in the team's court.
 *   waiting – an operator replied last; waiting for the visitor.
 *   closed  – resolved; visitor may reopen by writing again (if allowed).
 *   spam    – marked as spam; visitor writes are rejected.
 */
final class ConversationState {

	public const QUEUED  = 'queued';
	public const OPEN    = 'open';
	public const WAITING = 'waiting';
	public const CLOSED  = 'closed';
	public const SPAM    = 'spam';

	/**
	 * Allowed transitions: from => [event => to].
	 */
	private const MAP = array(
		self::QUEUED  => array(
			'visitor_message'  => self::QUEUED,
			'operator_message' => self::WAITING,
			'assign'           => self::OPEN,
			'close'            => self::CLOSED,
			'spam'             => self::SPAM,
		),
		self::OPEN    => array(
			'visitor_message'  => self::OPEN,
			'operator_message' => self::WAITING,
			'assign'           => self::OPEN,
			'unassign'         => self::QUEUED,
			'close'            => self::CLOSED,
			'spam'             => self::SPAM,
		),
		self::WAITING => array(
			'visitor_message'  => self::OPEN,
			'operator_message' => self::WAITING,
			'assign'           => self::WAITING,
			'unassign'         => self::QUEUED,
			'close'            => self::CLOSED,
			'spam'             => self::SPAM,
		),
		self::CLOSED  => array(
			'reopen'           => self::OPEN,
			'visitor_message'  => self::OPEN,
			'operator_message' => self::WAITING,
			'spam'             => self::SPAM,
		),
		self::SPAM    => array(
			'unspam' => self::CLOSED,
		),
	);

	public static function all(): array {
		return array_keys( self::MAP );
	}

	public static function next( string $from, string $event ): ?string {
		return self::MAP[ $from ][ $event ] ?? null;
	}

	public static function can( string $from, string $event ): bool {
		return null !== self::next( $from, $event );
	}

	public static function is_active( string $state ): bool {
		return in_array( $state, array( self::QUEUED, self::OPEN, self::WAITING ), true );
	}
}
