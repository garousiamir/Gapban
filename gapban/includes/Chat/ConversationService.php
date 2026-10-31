<?php
/**
 * Conversation lifecycle and message persistence.
 *
 * Every message is committed together with its outbound delivery job in one
 * InnoDB transaction, so a crash can never leave an accepted visitor message
 * without a delivery job (see also Recovery::requeue_orphans()).
 *
 * @package Gapban
 */

namespace Gapban\Chat;

use Gapban\Core\Settings;
use Gapban\Domain\ConversationState;
use Gapban\Domain\Ids;
use Gapban\Persistence\Db;
use Gapban\Queue\JobQueue;
use Gapban\Queue\Worker;
use Gapban\Routing\RoutingRegistry;

defined( 'ABSPATH' ) || exit;

final class ConversationService {

	public const AUTHOR_VISITOR    = 'visitor';
	public const AUTHOR_OPERATOR   = 'operator';
	public const AUTHOR_SYSTEM     = 'system';
	public const AUTHOR_AUTOMATION = 'automation';

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		return Db::row( 'SELECT * FROM ' . Db::t( 'conversations' ) . ' WHERE id = %d', $id );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get_by_public_id( string $public_id ): ?array {
		if ( ! Ids::is_public_id( $public_id ) ) {
			return null;
		}
		return Db::row( 'SELECT * FROM ' . Db::t( 'conversations' ) . ' WHERE public_id = %s', $public_id );
	}

	/**
	 * Latest conversation of a visitor (active first, then most recent).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current_for_visitor( int $visitor_id ): ?array {
		return Db::row(
			'SELECT * FROM ' . Db::t( 'conversations' ) . " WHERE visitor_id = %d AND status <> 'spam'
			 ORDER BY (status IN ('queued','open','waiting')) DESC, id DESC LIMIT 1",
			$visitor_id
		);
	}

	/**
	 * Create a conversation with an immutable routing snapshot.
	 *
	 * @param array<string, mixed> $context page_url, page_title, team_id, subject, campaign_rule_id, offline
	 * @return array<string, mixed>
	 */
	public static function create( int $visitor_id, array $context = array() ): array {
		$now     = Db::now();
		$team_id = ! empty( $context['team_id'] ) ? (int) $context['team_id'] : self::default_team_id();
		$snap    = RoutingRegistry::snapshot( $team_id );

		$data = array(
			'public_id'        => Ids::public_id(),
			'reference'        => self::unique_reference(),
			'visitor_id'       => $visitor_id,
			'status'           => ConversationState::QUEUED,
			'team_id'          => $team_id ?: null,
			'routing_mode'     => $snap['mode'],
			'provider'         => (string) ( $snap['provider'] ?? '' ),
			'routing_snapshot' => wp_json_encode( $snap ),
			'subject'          => mb_substr( (string) ( $context['subject'] ?? '' ), 0, 190 ),
			'page_url'         => (string) ( $context['page_url'] ?? '' ),
			'page_title'       => mb_substr( sanitize_text_field( (string) ( $context['page_title'] ?? '' ) ), 0, 190 ),
			'campaign_rule_id' => ! empty( $context['campaign_rule_id'] ) ? (int) $context['campaign_rule_id'] : null,
			'offline'          => ! empty( $context['offline'] ) ? 1 : 0,
			'created_at'       => $now,
			'updated_at'       => $now,
		);
		$id = Db::insert( 'conversations', $data );
		Db::insert(
			'participants',
			array(
				'conversation_id' => $id,
				'kind'            => 'visitor',
				'ref_id'          => $visitor_id,
				'joined_at'       => $now,
			)
		);
		Audit::log( 'visitor', $visitor_id, 'conversation.created', 'conversation', $id, array( 'mode' => $snap['mode'] ) );

		Assignment::auto_assign( $id );
		return self::get( $id );
	}

	private static function unique_reference(): string {
		for ( $i = 0; $i < 5; $i++ ) {
			$ref = Ids::reference();
			if ( ! Db::value( 'SELECT 1 FROM ' . Db::t( 'conversations' ) . ' WHERE reference = %s', $ref ) ) {
				return $ref;
			}
		}
		return 'GB-' . Ids::random_base32( 10 );
	}

	public static function default_team_id(): int {
		return (int) Db::value( 'SELECT id FROM ' . Db::t( 'teams' ) . ' WHERE active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1' );
	}

	/**
	 * Accept a visitor message. Idempotent per (conversation, client_key).
	 *
	 * @param int[] $attachment_ids
	 * @return array<string, mixed> the message row
	 */
	public static function post_visitor_message( array $conversation, int $visitor_id, string $body, string $client_key, array $attachment_ids = array() ): array {
		$cid = (int) $conversation['id'];

		$existing = Db::row( 'SELECT * FROM ' . Db::t( 'messages' ) . ' WHERE conversation_id = %d AND client_key = %s', $cid, $client_key );
		if ( $existing ) {
			return $existing;
		}

		$event = 'visitor_message';
		$next  = ConversationState::next( (string) $conversation['status'], $event );
		if ( null === $next ) {
			throw new ChatException( 'conversation_closed_for_visitor', 409 );
		}
		if ( ConversationState::CLOSED === $conversation['status'] ) {
			if ( ! Settings::get( 'allow_reopen' ) ) {
				throw new ChatException( 'conversation_closed_for_visitor', 409 );
			}
			$next = empty( $conversation['assigned_operator_id'] ) ? ConversationState::QUEUED : ConversationState::OPEN;
		}
		if ( ConversationState::QUEUED === $conversation['status'] ) {
			$next = ConversationState::QUEUED;
		}

		$snap         = json_decode( (string) $conversation['routing_snapshot'], true ) ?: array();
		$routed       = RoutingRegistry::routes_to_provider( $snap );
		$has_attach   = (bool) $attachment_ids;
		$kind         = $has_attach && '' === trim( $body ) ? 'attachment' : 'text';
		$reopening    = ConversationState::CLOSED === $conversation['status'];

		try {
			$message = Db::transaction(
				static function () use ( $cid, $visitor_id, $body, $client_key, $attachment_ids, $kind, $routed, $next, $reopening ) {
					$mid = self::insert_message(
						array(
							'conversation_id' => $cid,
							'author_type'     => self::AUTHOR_VISITOR,
							'author_id'       => $visitor_id,
							'kind'            => $kind,
							'body'            => $body,
							'source'          => 'widget',
							'client_key'      => $client_key,
							'delivery_status' => $routed ? 'queued' : 'none',
						)
					);
					if ( $attachment_ids ) {
						Db::exec(
							'UPDATE ' . Db::t( 'attachments' ) . ' SET message_id = %d WHERE conversation_id = %d AND message_id IS NULL AND uploader_type = %s AND id IN (' . Db::in( $attachment_ids ) . ')',
							$mid,
							$cid,
							'visitor',
							...$attachment_ids
						);
					}
					if ( $routed ) {
						JobQueue::enqueue( 'deliver_message', array( 'message_id' => $mid ), self::order_key( $cid ) );
					}
					Db::exec(
						'UPDATE ' . Db::t( 'conversations' ) . ' SET status = %s, last_message_id = %d, last_message_at = %s, unread_admin = unread_admin + 1,
						 visitor_typing_until = NULL, closed_at = IF(%d = 1, NULL, closed_at), reopened_count = reopened_count + %d, version = version + 1, updated_at = %s WHERE id = %d',
						$next,
						$mid,
						Db::now(),
						$reopening ? 1 : 0,
						$reopening ? 1 : 0,
						Db::now(),
						$cid
					);
					return self::message( $mid );
				}
			);
		} catch ( \Gapban\Persistence\DbException $e ) {
			// A concurrent request with the same client_key won the race.
			$existing = Db::row( 'SELECT * FROM ' . Db::t( 'messages' ) . ' WHERE conversation_id = %d AND client_key = %s', $cid, $client_key );
			if ( $existing ) {
				return $existing;
			}
			throw $e;
		}

		if ( $reopening ) {
			Audit::log( 'visitor', $visitor_id, 'conversation.reopened', 'conversation', $cid );
		}
		if ( $routed ) {
			Worker::after_response( self::order_key( $cid ) );
		}
		do_action( 'gapban_visitor_message', $message, $conversation );
		return $message;
	}

	/**
	 * Accept an operator message from the WordPress inbox or Bale.
	 *
	 * @param array<string, mixed> $conversation
	 * @param array<string, mixed> $meta
	 * @param int[]                $attachment_ids
	 * @return array<string, mixed>
	 */
	public static function post_operator_message( array $conversation, int $operator_id, string $body, string $source, array $meta = array(), array $attachment_ids = array(), ?string $client_key = null ): array {
		$cid = (int) $conversation['id'];
		if ( ConversationState::SPAM === $conversation['status'] ) {
			throw new ChatException( 'conversation_is_spam', 409 );
		}
		if ( $client_key ) {
			$existing = Db::row( 'SELECT * FROM ' . Db::t( 'messages' ) . ' WHERE conversation_id = %d AND client_key = %s', $cid, $client_key );
			if ( $existing ) {
				return $existing;
			}
		}
		$snap   = json_decode( (string) $conversation['routing_snapshot'], true ) ?: array();
		$mirror = 'admin' === $source && Settings::get( 'mirror_admin_replies' ) && RoutingRegistry::routes_to_provider( $snap );
		$next   = ConversationState::next( (string) $conversation['status'], 'operator_message' ) ?? ConversationState::WAITING;
		$kind   = $attachment_ids && '' === trim( $body ) ? 'attachment' : 'text';

		$message = Db::transaction(
			static function () use ( $cid, $operator_id, $body, $source, $meta, $attachment_ids, $mirror, $next, $kind, $client_key ) {
				$mid = self::insert_message(
					array(
						'conversation_id' => $cid,
						'author_type'     => self::AUTHOR_OPERATOR,
						'author_id'       => $operator_id,
						'kind'            => $kind,
						'body'            => $body,
						'source'          => $source,
						'client_key'      => $client_key,
						'delivery_status' => $mirror ? 'queued' : 'none',
						'meta'            => $meta ? wp_json_encode( $meta ) : null,
					)
				);
				if ( $attachment_ids ) {
					Db::exec(
						'UPDATE ' . Db::t( 'attachments' ) . ' SET message_id = %d WHERE conversation_id = %d AND message_id IS NULL AND uploader_type = %s AND id IN (' . Db::in( $attachment_ids ) . ')',
						$mid,
						$cid,
						'operator',
						...$attachment_ids
					);
				}
				if ( $mirror ) {
					JobQueue::enqueue( 'deliver_message', array( 'message_id' => $mid ), self::order_key( $cid ) );
				}
				Db::exec(
					'UPDATE ' . Db::t( 'conversations' ) . ' SET status = %s, last_message_id = %d, last_message_at = %s,
					 first_human_response_at = IFNULL(first_human_response_at, %s), operator_typing_until = NULL,
					 closed_at = IF(status = %s, NULL, closed_at), version = version + 1, updated_at = %s WHERE id = %d',
					$next,
					$mid,
					Db::now(),
					Db::now(),
					ConversationState::CLOSED,
					Db::now(),
					$cid
				);
				Db::exec(
					'INSERT IGNORE INTO ' . Db::t( 'participants' ) . ' (conversation_id, kind, ref_id, joined_at) VALUES (%d, %s, %d, %s)',
					$cid,
					'operator',
					$operator_id,
					Db::now()
				);
				return self::message( $mid );
			}
		);

		// First human reply on an unassigned conversation claims it for that operator.
		if ( empty( $conversation['assigned_operator_id'] ) && $operator_id ) {
			Assignment::claim( $cid, $operator_id, 'operator', $operator_id );
		}
		if ( $mirror ) {
			Worker::after_response( self::order_key( $cid ) );
		}
		do_action( 'gapban_operator_message', $message, $conversation );
		return $message;
	}

	/**
	 * Clearly-labelled automated or system message (never counted as a human response).
	 *
	 * @return array<string, mixed>
	 */
	public static function post_automated( int $conversation_id, string $body, string $author_type = self::AUTHOR_AUTOMATION, array $meta = array() ): array {
		$mid = self::insert_message(
			array(
				'conversation_id' => $conversation_id,
				'author_type'     => $author_type,
				'author_id'       => 0,
				'kind'            => self::AUTHOR_SYSTEM === $author_type ? 'event' : 'text',
				'body'            => $body,
				'source'          => 'automation',
				'delivery_status' => 'none',
				'meta'            => $meta ? wp_json_encode( $meta ) : null,
			)
		);
		Db::exec( 'UPDATE ' . Db::t( 'conversations' ) . ' SET last_message_id = %d, version = version + 1, updated_at = %s WHERE id = %d', $mid, Db::now(), $conversation_id );
		return self::message( $mid );
	}

	/**
	 * Internal note: never shown to visitors and never sent to Bale.
	 *
	 * @return array<string, mixed>
	 */
	public static function add_note( int $conversation_id, int $operator_id, string $body, string $source = 'admin' ): array {
		$mid = self::insert_message(
			array(
				'conversation_id' => $conversation_id,
				'author_type'     => self::AUTHOR_OPERATOR,
				'author_id'       => $operator_id,
				'kind'            => 'note',
				'body'            => $body,
				'is_internal'     => 1,
				'source'          => $source,
				'delivery_status' => 'none',
			)
		);
		Audit::log( 'operator', $operator_id, 'note.added', 'conversation', $conversation_id );
		return self::message( $mid );
	}

	/**
	 * Apply a state event (close, reopen, spam, unspam).
	 */
	public static function transition( int $conversation_id, string $event, string $actor_type, int $actor_id ): array {
		$conv = self::get( $conversation_id );
		if ( ! $conv ) {
			throw new ChatException( 'not_found', 404 );
		}
		$next = ConversationState::next( (string) $conv['status'], $event );
		if ( null === $next ) {
			throw new ChatException( 'invalid_transition', 409 );
		}
		if ( 'reopen' === $event && empty( $conv['assigned_operator_id'] ) ) {
			$next = ConversationState::QUEUED;
		}
		// Optimistic concurrency: only apply if nobody changed the row meanwhile.
		$n = Db::exec(
			'UPDATE ' . Db::t( 'conversations' ) . ' SET status = %s, closed_at = CASE WHEN %d = 1 THEN %s WHEN %d = 1 THEN NULL ELSE closed_at END,
			 reopened_count = reopened_count + %d, version = version + 1, updated_at = %s WHERE id = %d AND version = %d',
			$next,
			ConversationState::CLOSED === $next || ConversationState::SPAM === $next ? 1 : 0,
			Db::now(),
			ConversationState::is_active( $next ) ? 1 : 0,
			'reopen' === $event ? 1 : 0,
			Db::now(),
			$conversation_id,
			(int) $conv['version']
		);
		if ( 1 !== $n ) {
			throw new ChatException( 'conflict', 409 );
		}
		Audit::log( $actor_type, $actor_id, 'conversation.' . $event, 'conversation', $conversation_id, array( 'from' => $conv['status'], 'to' => $next ) );
		if ( in_array( $event, array( 'close', 'reopen' ), true ) ) {
			self::post_automated( $conversation_id, 'close' === $event ? 'conversation_closed' : 'conversation_reopened', self::AUTHOR_SYSTEM );
		}
		if ( 'spam' === $event ) {
			Db::exec( 'UPDATE ' . Db::t( 'jobs' ) . " SET status = 'discarded', updated_at = %s WHERE order_key = %s AND status = 'pending'", Db::now(), self::order_key( $conversation_id ) );
		}
		return self::get( $conversation_id );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function insert_message( array $data ): int {
		$data = array_merge(
			array(
				'public_id'  => Ids::public_id(),
				'created_at' => Db::now(),
				'is_internal'=> 0,
			),
			$data
		);
		return Db::insert( 'messages', $data );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function message( int $id ): array {
		return Db::row( 'SELECT * FROM ' . Db::t( 'messages' ) . ' WHERE id = %d', $id ) ?? array();
	}

	/**
	 * Messages with id > $after (ascending), for cursor polling.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function messages_after( int $conversation_id, int $after, bool $include_internal, int $limit = 50 ): array {
		$internal = $include_internal ? '' : ' AND is_internal = 0';
		return Db::rows(
			'SELECT * FROM ' . Db::t( 'messages' ) . " WHERE conversation_id = %d AND id > %d{$internal} ORDER BY id ASC LIMIT %d",
			$conversation_id,
			$after,
			$limit
		);
	}

	/**
	 * Messages with id < $before (returned ascending), for history pagination.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function messages_before( int $conversation_id, int $before, bool $include_internal, int $limit = 30 ): array {
		$internal = $include_internal ? '' : ' AND is_internal = 0';
		$rows     = Db::rows(
			'SELECT * FROM ' . Db::t( 'messages' ) . " WHERE conversation_id = %d AND id < %d{$internal} ORDER BY id DESC LIMIT %d",
			$conversation_id,
			$before > 0 ? $before : PHP_INT_MAX,
			$limit
		);
		return array_reverse( $rows );
	}

	public static function order_key( int $conversation_id ): string {
		return 'c:' . $conversation_id;
	}

	/**
	 * Attachments for a set of message ids.
	 *
	 * @param int[] $message_ids
	 * @return array<int, array<int, array<string, mixed>>> message id => attachments
	 */
	public static function attachments_for( array $message_ids ): array {
		if ( ! $message_ids ) {
			return array();
		}
		$rows = Db::rows(
			'SELECT id, public_id, message_id, original_name, mime, size, width, height FROM ' . Db::t( 'attachments' ) . ' WHERE message_id IN (' . Db::in( $message_ids ) . ')',
			...$message_ids
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['message_id'] ][] = $r;
		}
		return $out;
	}
}
