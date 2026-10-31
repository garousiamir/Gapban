<?php
/**
 * Output shapes for REST responses. Visitor payloads never include internal notes,
 * operator Bale ids, routing snapshots or other visitors' data.
 *
 * @package Gapban
 */

namespace Gapban\Rest;

use Gapban\Chat\ConversationService;
use Gapban\Persistence\Db;

defined( 'ABSPATH' ) || exit;

final class Serializer {

	/**
	 * Like rest_ensure_response(), but always returns a response object (WP_Error included).
	 *
	 * @param mixed $result
	 */
	public static function response( $result ): \WP_REST_Response {
		if ( is_wp_error( $result ) ) {
			return rest_convert_error_to_response( $result );
		}
		return rest_ensure_response( $result );
	}

	public static function iso( ?string $utc ): ?string {
		return $utc ? gmdate( 'c', (int) strtotime( $utc . ' UTC' ) ) : null;
	}

	/**
	 * @param array<int, array<string, mixed>> $messages
	 * @return array<int, array<string, mixed>>
	 */
	public static function messages_for_visitor( array $messages, array $conversation ): array {
		$ids         = array_map( static fn( $m ) => (int) $m['id'], $messages );
		$attachments = ConversationService::attachments_for( $ids );
		$names       = self::operator_names( $messages );
		$read_upto   = (int) $conversation['admin_read_message_id'];
		$out         = array();
		foreach ( $messages as $m ) {
			if ( (int) $m['is_internal'] ) {
				continue;
			}
			$item = array(
				'id'          => (int) $m['id'],
				'public_id'   => $m['public_id'],
				'author'      => $m['author_type'],
				'author_name' => 'operator' === $m['author_type'] ? ( $names[ (int) $m['author_id'] ] ?? '' ) : '',
				'kind'        => $m['kind'],
				'body'        => (string) $m['body'],
				'created_at'  => self::iso( $m['created_at'] ),
				'client_key'  => 'visitor' === $m['author_type'] ? $m['client_key'] : null,
				'attachments' => array_map( array( self::class, 'attachment' ), $attachments[ (int) $m['id'] ] ?? array() ),
			);
			if ( 'visitor' === $m['author_type'] ) {
				$item['status'] = self::visitor_status( $m, $read_upto );
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * accepted → delivered (provider accepted) → seen (read in the WordPress inbox).
	 * Bale read receipts are not available, so "seen" never comes from Bale.
	 *
	 * @param array<string, mixed> $m
	 */
	private static function visitor_status( array $m, int $read_upto ): string {
		if ( $read_upto >= (int) $m['id'] ) {
			return 'seen';
		}
		if ( 'sent' === $m['delivery_status'] ) {
			return 'delivered';
		}
		return 'accepted';
	}

	/**
	 * @param array<int, array<string, mixed>> $messages
	 * @return array<int, array<string, mixed>>
	 */
	public static function messages_for_admin( array $messages ): array {
		$ids         = array_map( static fn( $m ) => (int) $m['id'], $messages );
		$attachments = ConversationService::attachments_for( $ids );
		$names       = self::operator_names( $messages );
		$out         = array();
		foreach ( $messages as $m ) {
			$meta  = $m['meta'] ? json_decode( (string) $m['meta'], true ) : array();
			$out[] = array(
				'id'                   => (int) $m['id'],
				'public_id'            => $m['public_id'],
				'author'               => $m['author_type'],
				'author_id'            => (int) $m['author_id'],
				'author_name'          => 'operator' === $m['author_type'] ? ( $names[ (int) $m['author_id'] ] ?? '' ) : '',
				'kind'                 => $m['kind'],
				'internal'             => (bool) (int) $m['is_internal'],
				'body'                 => (string) $m['body'],
				'source'               => $m['source'],
				'delivery_status'      => $m['delivery_status'],
				'delivery_error'       => $m['delivery_error'],
				'possible_duplicate'   => ! empty( $meta['possible_duplicate'] ),
				'provider_accepted_at' => self::iso( $m['provider_accepted_at'] ),
				'created_at'           => self::iso( $m['created_at'] ),
				'attachments'          => array_map( array( self::class, 'attachment' ), $attachments[ (int) $m['id'] ] ?? array() ),
			);
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $a
	 * @return array<string, mixed>
	 */
	public static function attachment( array $a ): array {
		return array(
			'id'     => $a['public_id'],
			'name'   => $a['original_name'],
			'mime'   => $a['mime'],
			'size'   => (int) $a['size'],
			'width'  => (int) $a['width'],
			'height' => (int) $a['height'],
			'image'  => str_starts_with( (string) $a['mime'], 'image/' ),
			'audio'  => str_starts_with( (string) $a['mime'], 'audio/' ) || 'video/webm' === $a['mime'],
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $messages
	 * @return array<int, string>
	 */
	private static function operator_names( array $messages ): array {
		$ids = array();
		foreach ( $messages as $m ) {
			if ( 'operator' === $m['author_type'] && (int) $m['author_id'] ) {
				$ids[] = (int) $m['author_id'];
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		foreach ( Db::rows( 'SELECT id, display_name FROM ' . Db::t( 'operators' ) . ' WHERE id IN (' . Db::in( $ids ) . ')', ...$ids ) as $r ) {
			$out[ (int) $r['id'] ] = (string) $r['display_name'];
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $c
	 * @return array<string, mixed>
	 */
	public static function conversation_for_visitor( array $c ): array {
		$now = time();
		return array(
			'id'               => $c['public_id'],
			'reference'        => $c['reference'],
			'status'           => in_array( $c['status'], array( 'closed', 'spam' ), true ) ? 'closed' : $c['status'],
			'last_message_id'  => (int) $c['last_message_id'],
			'operator_typing'  => ! empty( $c['operator_typing_until'] ) && strtotime( $c['operator_typing_until'] . ' UTC' ) > $now,
			'rated'            => (bool) Db::value( 'SELECT 1 FROM ' . Db::t( 'ratings' ) . ' WHERE conversation_id = %d', (int) $c['id'] ),
			'has_human'        => ! empty( $c['first_human_response_at'] ) || ! empty( $c['assigned_operator_id'] ),
			'created_at'       => self::iso( $c['created_at'] ),
		);
	}

	/**
	 * @param array<string, mixed> $c
	 * @return array<string, mixed>
	 */
	public static function conversation_for_admin( array $c ): array {
		$now  = time();
		$snap = json_decode( (string) $c['routing_snapshot'], true ) ?: array();
		return array(
			'id'                      => (int) $c['id'],
			'public_id'               => $c['public_id'],
			'reference'               => $c['reference'],
			'status'                  => $c['status'],
			'priority'                => (int) $c['priority'],
			'team_id'                 => $c['team_id'] ? (int) $c['team_id'] : null,
			'assigned_operator_id'    => $c['assigned_operator_id'] ? (int) $c['assigned_operator_id'] : null,
			'routing'                 => array(
				'mode'        => $snap['mode'] ?? $c['routing_mode'],
				'provider'    => $snap['provider'] ?? '',
				'destination' => (string) ( $snap['destination_title'] ?? '' ),
				'reason'      => (string) ( $snap['reason'] ?? '' ),
			),
			'subject'                 => $c['subject'],
			'page_url'                => $c['page_url'],
			'page_title'              => $c['page_title'],
			'offline'                 => (bool) (int) $c['offline'],
			'unread'                  => (int) $c['unread_admin'],
			'visitor_read_message_id' => (int) $c['visitor_read_message_id'],
			'visitor_typing'          => ! empty( $c['visitor_typing_until'] ) && strtotime( $c['visitor_typing_until'] . ' UTC' ) > $now,
			'last_message_id'         => (int) $c['last_message_id'],
			'last_message_at'         => self::iso( $c['last_message_at'] ),
			'first_human_response_at' => self::iso( $c['first_human_response_at'] ),
			'closed_at'               => self::iso( $c['closed_at'] ),
			'created_at'              => self::iso( $c['created_at'] ),
			'version'                 => (int) $c['version'],
			'visitor'                 => array(
				'id'      => (int) $c['visitor_id'],
				'name'    => (string) ( $c['visitor_name'] ?? '' ),
				'email'   => (string) ( $c['visitor_email'] ?? '' ),
				'phone'   => (string) ( $c['visitor_phone'] ?? '' ),
				'blocked' => (bool) (int) ( $c['visitor_blocked'] ?? 0 ),
			),
			'preview'                 => isset( $c['preview'] ) ? mb_substr( (string) $c['preview'], 0, 140 ) : null,
		);
	}
}
