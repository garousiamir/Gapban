<?php
/**
 * WordPress inbox API. Cookie authentication + X-WP-Nonce (CSRF) + capabilities;
 * every conversation-scoped route re-checks visibility for the current user.
 *
 * @package Gapban
 */

namespace Gapban\Rest;

use Gapban\Chat\Assignment;
use Gapban\Chat\Audit;
use Gapban\Chat\ChatException;
use Gapban\Chat\ConversationService;
use Gapban\Core\Settings;
use Gapban\Domain\ConversationState;
use Gapban\Persistence\Db;
use Gapban\Privacy\Retention;
use Gapban\Security\Capabilities as Caps;
use Gapban\Security\UploadValidator;
use Gapban\Security\VisitorSessions;

defined( 'ABSPATH' ) || exit;

final class InboxController {

	public static function register(): void {
		$ns = 'gapban/v1';
		$id = '/admin/conversations/(?P<id>\d+)';
		self::route( $ns, '/admin/conversations', 'GET', 'list', array( Caps::VIEW_ASSIGNED, Caps::VIEW_ALL ) );
		self::route( $ns, $id, 'GET', 'get', array( Caps::VIEW_ASSIGNED, Caps::VIEW_ALL ) );
		self::route( $ns, $id, 'DELETE', 'delete', array( Caps::DELETE ) );
		self::route( $ns, $id . '/messages', 'GET', 'messages', array( Caps::VIEW_ASSIGNED, Caps::VIEW_ALL ) );
		self::route( $ns, $id . '/messages', 'POST', 'reply', array( Caps::REPLY ) );
		self::route( $ns, $id . '/notes', 'POST', 'note', array( Caps::REPLY ) );
		self::route( $ns, $id . '/actions', 'POST', 'action', array( Caps::REPLY, Caps::ASSIGN ) );
		self::route( $ns, $id . '/read', 'POST', 'read', array( Caps::VIEW_ASSIGNED, Caps::VIEW_ALL ) );
		self::route( $ns, $id . '/typing', 'POST', 'typing', array( Caps::REPLY ) );
		self::route( $ns, $id . '/uploads', 'POST', 'upload', array( Caps::REPLY ) );
		self::route( $ns, $id . '/transcript', 'GET', 'transcript', array( Caps::EXPORT ) );
		self::route( $ns, '/admin/attachments/(?P<att>[0-9A-HJKMNP-TV-Z]{26})', 'GET', 'download', array( Caps::VIEW_ASSIGNED, Caps::VIEW_ALL ) );
		self::route( $ns, '/admin/visitors/(?P<vid>\d+)/block', 'POST', 'block', array( Caps::ASSIGN, Caps::DELETE ) );
		self::route( $ns, '/admin/heartbeat', 'POST', 'heartbeat', array( Caps::VIEW_ASSIGNED, Caps::VIEW_ALL ) );
		self::route( $ns, '/admin/tags', 'GET', 'tags', array( Caps::VIEW_ASSIGNED, Caps::VIEW_ALL ) );
		self::route( $ns, '/admin/tags', 'POST', 'create_tag', array( Caps::ASSIGN, Caps::MANAGE_SETTINGS ) );
		self::route( $ns, '/admin/canned', 'GET', 'canned', array( Caps::REPLY ) );
	}

	/**
	 * @param string[] $caps any of these grants access
	 */
	private static function route( string $ns, string $path, string $method, string $callback, array $caps ): void {
		register_rest_route(
			$ns,
			$path,
			array(
				'methods'             => $method,
				'callback'            => static function ( \WP_REST_Request $r ) use ( $callback ) {
					try {
						$res = Serializer::response( self::$callback( $r ) );
					} catch ( ChatException $e ) {
						$res = Serializer::response( $e->to_wp_error() );
					}
					$res->header( 'Cache-Control', 'no-store, private' );
					return $res;
				},
				'permission_callback' => static function () use ( $caps ) {
					foreach ( $caps as $cap ) {
						if ( current_user_can( $cap ) ) {
							return true;
						}
					}
					return false;
				},
			)
		);
	}

	/**
	 * Load a conversation the current user may see, or throw 404 (no existence leak).
	 *
	 * @return array<string, mixed>
	 */
	private static function visible( int $id ): array {
		$conv = ConversationService::get( $id );
		if ( ! $conv || ! Caps::can_view_conversation( $conv ) ) {
			throw new ChatException( 'not_found', 404 );
		}
		return $conv;
	}

	private static function actor(): int {
		return Caps::current_operator_id() ?: self::ensure_operator();
	}

	/**
	 * Administrators replying from WordPress get an operator record automatically.
	 */
	private static function ensure_operator(): int {
		$user = wp_get_current_user();
		if ( ! $user->ID ) {
			return 0;
		}
		return Db::insert(
			'operators',
			array(
				'wp_user_id'   => $user->ID,
				'display_name' => $user->display_name ?: $user->user_login,
				'availability' => 'auto', // Presence from the expiring inbox heartbeat only.
				'created_at'   => Db::now(),
			)
		);
	}

	public static function list( \WP_REST_Request $r ) {
		$c       = Db::t( 'conversations' );
		$v       = Db::t( 'visitors' );
		$where   = array( '1=1' );
		$args    = array();
		$status  = (string) $r->get_param( 'status' );
		$filter  = (string) $r->get_param( 'assigned' );
		$search  = trim( (string) $r->get_param( 'q' ) );
		$team    = (int) $r->get_param( 'team' );
		$tag     = (int) $r->get_param( 'tag' );
		$before  = (int) $r->get_param( 'before' );
		$per     = max( 5, min( 100, (int) ( $r->get_param( 'per_page' ) ?: 30 ) ) );

		if ( 'active' === $status || '' === $status ) {
			$where[] = "c.status IN ('queued','open','waiting')";
		} elseif ( in_array( $status, ConversationState::all(), true ) ) {
			$where[] = 'c.status = %s';
			$args[]  = $status;
		}
		if ( ! current_user_can( Caps::VIEW_ALL ) ) {
			$op      = Caps::current_operator_id();
			$where[] = '(c.assigned_operator_id = %d OR (c.assigned_operator_id IS NULL AND c.team_id IN (SELECT team_id FROM ' . Db::t( 'team_members' ) . ' WHERE operator_id = %d)))';
			$args[]  = $op;
			$args[]  = $op;
		}
		if ( 'me' === $filter ) {
			$where[] = 'c.assigned_operator_id = %d';
			$args[]  = Caps::current_operator_id();
		} elseif ( 'none' === $filter ) {
			$where[] = 'c.assigned_operator_id IS NULL';
		}
		if ( $team ) {
			$where[] = 'c.team_id = %d';
			$args[]  = $team;
		}
		if ( $tag ) {
			$where[] = 'EXISTS (SELECT 1 FROM ' . Db::t( 'conversation_tags' ) . ' ct WHERE ct.conversation_id = c.id AND ct.tag_id = %d)';
			$args[]  = $tag;
		}
		if ( '' !== $search ) {
			global $wpdb;
			$like    = '%' . $wpdb->esc_like( mb_substr( $search, 0, 100 ) ) . '%';
			$where[] = '(c.reference LIKE %s OR v.name LIKE %s OR v.email LIKE %s OR v.phone LIKE %s OR EXISTS (SELECT 1 FROM ' . Db::t( 'messages' ) . ' m WHERE m.conversation_id = c.id AND m.body LIKE %s))';
			array_push( $args, $like, $like, $like, $like, $like );
		}
		if ( $before ) {
			$where[] = 'c.id < %d';
			$args[]  = $before;
		}
		$args[] = $per;
		$rows   = Db::rows(
			"SELECT c.*, v.name AS visitor_name, v.email AS visitor_email, v.phone AS visitor_phone, v.blocked AS visitor_blocked,
			  (SELECT m.body FROM " . Db::t( 'messages' ) . ' m WHERE m.id = c.last_message_id) AS preview
			 FROM ' . $c . ' c JOIN ' . $v . ' v ON v.id = c.visitor_id
			 WHERE ' . implode( ' AND ', $where ) . ' ORDER BY c.id DESC LIMIT %d',
			...$args
		);
		$items = array_map( array( Serializer::class, 'conversation_for_admin' ), $rows );
		$tags  = self::tags_for( array_column( $items, 'id' ) );
		foreach ( $items as &$item ) {
			$item['tags'] = $tags[ $item['id'] ] ?? array();
		}
		return array(
			'items'       => $items,
			'next_before' => count( $rows ) === $per ? (int) end( $rows )['id'] : null,
		);
	}

	/**
	 * @param int[] $ids
	 * @return array<int, int[]>
	 */
	private static function tags_for( array $ids ): array {
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		foreach ( Db::rows( 'SELECT conversation_id, tag_id FROM ' . Db::t( 'conversation_tags' ) . ' WHERE conversation_id IN (' . Db::in( $ids ) . ')', ...$ids ) as $r ) {
			$out[ (int) $r['conversation_id'] ][] = (int) $r['tag_id'];
		}
		return $out;
	}

	public static function get( \WP_REST_Request $r ) {
		$conv = self::visible( (int) $r['id'] );
		$row  = Db::row(
			'SELECT c.*, v.name AS visitor_name, v.email AS visitor_email, v.phone AS visitor_phone, v.blocked AS visitor_blocked, v.custom_fields, v.wp_user_id, v.first_seen_at
			 FROM ' . Db::t( 'conversations' ) . ' c JOIN ' . Db::t( 'visitors' ) . ' v ON v.id = c.visitor_id WHERE c.id = %d',
			(int) $conv['id']
		);
		$out                     = Serializer::conversation_for_admin( $row );
		$out['tags']             = self::tags_for( array( (int) $conv['id'] ) )[ (int) $conv['id'] ] ?? array();
		$out['visitor']['custom'] = json_decode( (string) $row['custom_fields'], true ) ?: array();
		$out['visitor']['wp_user_id'] = $row['wp_user_id'] ? (int) $row['wp_user_id'] : null;
		$out['visitor']['first_seen_at'] = Serializer::iso( $row['first_seen_at'] );
		$out['visitor']['previous'] = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'conversations' ) . ' WHERE visitor_id = %d AND id <> %d', (int) $conv['visitor_id'], (int) $conv['id'] );
		$rating                  = Db::row( 'SELECT score, comment, created_at FROM ' . Db::t( 'ratings' ) . ' WHERE conversation_id = %d', (int) $conv['id'] );
		$out['rating']           = $rating ? array( 'score' => (int) $rating['score'], 'comment' => (string) $rating['comment'], 'at' => Serializer::iso( $rating['created_at'] ) ) : null;
		$out['audit']            = array_map(
			static fn( $a ) => array(
				'action'     => $a['action'],
				'actor_type' => $a['actor_type'],
				'actor_id'   => (int) $a['actor_id'],
				'details'    => json_decode( (string) $a['details'], true ),
				'at'         => Serializer::iso( $a['created_at'] ),
			),
			\Gapban\Chat\Audit::for_object( 'conversation', (int) $conv['id'], 50 )
		);
		$out['can_reply'] = Caps::can_reply( $conv );
		return $out;
	}

	public static function messages( \WP_REST_Request $r ) {
		$conv   = self::visible( (int) $r['id'] );
		$after  = (int) $r->get_param( 'after' );
		$before = (int) $r->get_param( 'before' );
		$list   = $before || ! $after
			? ConversationService::messages_before( (int) $conv['id'], $before, true, 40 )
			: ConversationService::messages_after( (int) $conv['id'], $after, true, 100 );
		$conv   = ConversationService::get( (int) $conv['id'] );
		return array(
			'messages'     => Serializer::messages_for_admin( $list ),
			'conversation' => array(
				'status'                  => $conv['status'],
				'version'                 => (int) $conv['version'],
				'assigned_operator_id'    => $conv['assigned_operator_id'] ? (int) $conv['assigned_operator_id'] : null,
				'visitor_typing'          => ! empty( $conv['visitor_typing_until'] ) && strtotime( $conv['visitor_typing_until'] . ' UTC' ) > time(),
				'visitor_read_message_id' => (int) $conv['visitor_read_message_id'],
				'last_message_id'         => (int) $conv['last_message_id'],
			),
			'has_more'     => ( $before || ! $after ) && count( $list ) >= 40,
		);
	}

	public static function reply( \WP_REST_Request $r ) {
		$conv = self::visible( (int) $r['id'] );
		if ( ! Caps::can_reply( $conv ) ) {
			throw new ChatException( 'forbidden', 403 );
		}
		$body = VisitorController::clean_body( (string) $r->get_param( 'body' ) );
		$atts = self::attachment_ids( (array) $r->get_param( 'attachments' ), (int) $conv['id'] );
		if ( '' === $body && ! $atts ) {
			return new \WP_Error( 'gapban_empty', __( 'The message is empty.', 'gapban' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $body ) > (int) Settings::get( 'max_message_length' ) ) {
			return new \WP_Error( 'gapban_too_long', __( 'The message is too long.', 'gapban' ), array( 'status' => 413 ) );
		}
		$key = (string) $r->get_param( 'client_key' );
		$key = preg_match( '/^[A-Za-z0-9_\-]{8,58}$/', $key ) ? 'wp:' . $key : null;
		$msg = ConversationService::post_operator_message( $conv, self::actor(), $body, 'admin', array( 'wp_user_id' => get_current_user_id() ), $atts, $key );
		return array( 'message' => Serializer::messages_for_admin( array( $msg ) )[0] );
	}

	/**
	 * @param array<int, mixed> $public_ids
	 * @return int[]
	 */
	private static function attachment_ids( array $public_ids, int $conversation_id ): array {
		$public_ids = array_values( array_filter( array_slice( $public_ids, 0, 5 ), static fn( $x ) => is_string( $x ) && \Gapban\Domain\Ids::is_public_id( $x ) ) );
		if ( ! $public_ids ) {
			return array();
		}
		$rows = Db::rows(
			'SELECT id FROM ' . Db::t( 'attachments' ) . " WHERE conversation_id = %d AND uploader_type = 'operator' AND message_id IS NULL AND public_id IN (" . Db::in( $public_ids, '%s' ) . ')',
			$conversation_id,
			...$public_ids
		);
		return array_map( static fn( $x ) => (int) $x['id'], $rows );
	}

	public static function note( \WP_REST_Request $r ) {
		$conv = self::visible( (int) $r['id'] );
		$body = VisitorController::clean_body( (string) $r->get_param( 'body' ) );
		if ( '' === $body ) {
			return new \WP_Error( 'gapban_empty', __( 'The note is empty.', 'gapban' ), array( 'status' => 400 ) );
		}
		$msg = ConversationService::add_note( (int) $conv['id'], self::actor(), mb_substr( $body, 0, 5000 ) );
		return array( 'message' => Serializer::messages_for_admin( array( $msg ) )[0] );
	}

	public static function action( \WP_REST_Request $r ) {
		$conv   = self::visible( (int) $r['id'] );
		$action = (string) $r->get_param( 'action' );
		$cid    = (int) $conv['id'];
		$actor  = self::actor();
		$uid    = get_current_user_id();
		$assign = current_user_can( Caps::ASSIGN );

		switch ( $action ) {
			case 'close':
			case 'reopen':
				ConversationService::transition( $cid, $action, 'user', $uid );
				break;
			case 'spam':
			case 'unspam':
				if ( ! $assign ) {
					throw new ChatException( 'forbidden', 403 );
				}
				ConversationService::transition( $cid, $action, 'user', $uid );
				break;
			case 'claim':
				if ( ! Assignment::claim( $cid, $actor, 'user', $uid ) ) {
					throw new ChatException( 'claim_conflict', 409 );
				}
				break;
			case 'transfer':
				if ( ! $assign ) {
					throw new ChatException( 'forbidden', 403 );
				}
				$to   = (int) $r->get_param( 'operator_id' ) ?: null;
				$team = $r->get_param( 'team_id' ) !== null ? (int) $r->get_param( 'team_id' ) : null;
				if ( $to && ! Db::value( 'SELECT 1 FROM ' . Db::t( 'operators' ) . " WHERE id = %d AND status = 'active'", $to ) ) {
					throw new ChatException( 'invalid_operator', 422 );
				}
				$from = $r->get_param( 'expected_operator_id' );
				$from = null === $from || '' === $from ? ( $conv['assigned_operator_id'] ? (int) $conv['assigned_operator_id'] : null ) : ( (int) $from ?: null );
				if ( ! Assignment::transfer( $cid, $from, $to, 'user', $uid, $team ) ) {
					throw new ChatException( 'transfer_conflict', 409 );
				}
				break;
			case 'priority':
				$p = max( 0, min( 3, (int) $r->get_param( 'priority' ) ) );
				Db::update( 'conversations', array( 'priority' => $p ), array( 'id' => $cid ) );
				Audit::log( 'user', $uid, 'conversation.priority', 'conversation', $cid, array( 'priority' => $p ) );
				break;
			case 'tags':
				$tags = array_values( array_unique( array_map( 'intval', (array) $r->get_param( 'tags' ) ) ) );
				Db::exec( 'DELETE FROM ' . Db::t( 'conversation_tags' ) . ' WHERE conversation_id = %d', $cid );
				foreach ( array_slice( $tags, 0, 20 ) as $tag ) {
					if ( Db::value( 'SELECT 1 FROM ' . Db::t( 'tags' ) . ' WHERE id = %d', $tag ) ) {
						Db::exec( 'INSERT IGNORE INTO ' . Db::t( 'conversation_tags' ) . ' (conversation_id, tag_id) VALUES (%d, %d)', $cid, $tag );
					}
				}
				Audit::log( 'user', $uid, 'conversation.tags', 'conversation', $cid, array( 'tags' => $tags ) );
				break;
			default:
				throw new ChatException( 'invalid_action', 400 );
		}
		return self::get( $r );
	}

	public static function read( \WP_REST_Request $r ) {
		$conv = self::visible( (int) $r['id'] );
		$mid  = min( (int) $r->get_param( 'message_id' ), (int) $conv['last_message_id'] );
		// Only the assignee (or any viewer of an unassigned conversation) produces a "seen" marker.
		if ( empty( $conv['assigned_operator_id'] ) || (int) $conv['assigned_operator_id'] === Caps::current_operator_id() ) {
			Db::exec(
				'UPDATE ' . Db::t( 'conversations' ) . ' SET admin_read_message_id = GREATEST(admin_read_message_id, %d), unread_admin = 0 WHERE id = %d',
				$mid,
				(int) $conv['id']
			);
		}
		return array( 'ok' => true );
	}

	public static function typing( \WP_REST_Request $r ) {
		$conv  = self::visible( (int) $r['id'] );
		$until = filter_var( $r->get_param( 'typing' ), FILTER_VALIDATE_BOOLEAN ) ? Db::at( time() + 6 ) : null;
		Db::update( 'conversations', array( 'operator_typing_until' => $until ), array( 'id' => (int) $conv['id'] ) );
		return array( 'ok' => true );
	}

	public static function upload( \WP_REST_Request $r ) {
		$conv  = self::visible( (int) $r['id'] );
		$files = $r->get_file_params();
		$file  = $files['file'] ?? null;
		if ( ! $file || ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'gapban_upload_failed', __( 'The upload failed. Please try again.', 'gapban' ), array( 'status' => 400 ) );
		}
		$name  = UploadValidator::safe_display_name( (string) $file['name'] );
		$check = UploadValidator::validate( $file['tmp_name'], $name, (int) $file['size'] );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		return VisitorController::store_attachment( $file['tmp_name'], true, $name, $check['mime'], (int) $file['size'], (int) $conv['id'], 'operator', self::actor() );
	}

	public static function download( \WP_REST_Request $r ) {
		$att = Db::row( 'SELECT * FROM ' . Db::t( 'attachments' ) . ' WHERE public_id = %s', (string) $r['att'] );
		if ( ! $att ) {
			throw new ChatException( 'not_found', 404 );
		}
		self::visible( (int) $att['conversation_id'] );
		AttachmentStream::send( $att );
	}

	public static function transcript( \WP_REST_Request $r ) {
		$conv = self::visible( (int) $r['id'] );
		Audit::log( 'user', get_current_user_id(), 'conversation.exported', 'conversation', (int) $conv['id'] );
		return array(
			'filename' => $conv['reference'] . '.txt',
			'text'     => \Gapban\Chat\Transcript::text( $conv, (bool) $r->get_param( 'internal' ) ),
		);
	}

	public static function delete( \WP_REST_Request $r ) {
		$conv = self::visible( (int) $r['id'] );
		Retention::delete_conversation( (int) $conv['id'] );
		Audit::log( 'user', get_current_user_id(), 'conversation.deleted', 'conversation_deleted', (int) $conv['id'] );
		return array( 'deleted' => true );
	}

	public static function block( \WP_REST_Request $r ) {
		$vid     = (int) $r['vid'];
		$blocked = filter_var( $r->get_param( 'blocked' ), FILTER_VALIDATE_BOOLEAN );
		$conv    = Db::row( 'SELECT * FROM ' . Db::t( 'conversations' ) . ' WHERE visitor_id = %d ORDER BY id DESC LIMIT 1', $vid );
		if ( ! $conv || ! Caps::can_view_conversation( $conv ) ) {
			throw new ChatException( 'not_found', 404 );
		}
		Db::update(
			'visitors',
			array(
				'blocked'        => $blocked ? 1 : 0,
				'blocked_reason' => mb_substr( sanitize_text_field( (string) $r->get_param( 'reason' ) ), 0, 190 ),
			),
			array( 'id' => $vid )
		);
		if ( $blocked ) {
			VisitorSessions::revoke_visitor( $vid );
		}
		Audit::log( 'user', get_current_user_id(), $blocked ? 'visitor.blocked' : 'visitor.unblocked', 'visitor', $vid );
		return array( 'blocked' => $blocked );
	}

	public static function heartbeat( \WP_REST_Request $r ) {
		$op = self::actor();
		$av = (string) $r->get_param( 'availability' );
		$data = array( 'heartbeat_at' => Db::now() );
		if ( in_array( $av, array( 'online', 'away', 'offline', 'auto' ), true ) ) {
			$data['availability'] = $av;
		}
		Db::update( 'operators', $data, array( 'id' => $op ) );
		$unread = (int) Db::value(
			'SELECT COALESCE(SUM(unread_admin),0) FROM ' . Db::t( 'conversations' ) . " WHERE status IN ('queued','open','waiting') AND (assigned_operator_id = %d OR assigned_operator_id IS NULL)",
			$op
		);
		return array(
			'operator_id'  => $op,
			'availability' => (string) Db::value( 'SELECT availability FROM ' . Db::t( 'operators' ) . ' WHERE id = %d', $op ),
			'unread'       => $unread,
			'queued'       => (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'conversations' ) . " WHERE status = 'queued' AND assigned_operator_id IS NULL" ),
		);
	}

	public static function tags() {
		return array_map(
			static fn( $t ) => array( 'id' => (int) $t['id'], 'name' => $t['name'], 'color' => $t['color'] ),
			Db::rows( 'SELECT * FROM ' . Db::t( 'tags' ) . ' ORDER BY name' )
		);
	}

	public static function create_tag( \WP_REST_Request $r ) {
		$name = mb_substr( sanitize_text_field( (string) $r->get_param( 'name' ) ), 0, 64 );
		if ( '' === $name ) {
			return new \WP_Error( 'gapban_invalid', __( 'Enter a tag name.', 'gapban' ), array( 'status' => 422 ) );
		}
		$slug  = sanitize_title( $name ) ?: md5( $name );
		$color = sanitize_hex_color( (string) $r->get_param( 'color' ) ) ?: '#6b7280';
		Db::exec( 'INSERT IGNORE INTO ' . Db::t( 'tags' ) . ' (name, slug, color) VALUES (%s, %s, %s)', $name, substr( $slug, 0, 64 ), $color );
		return self::tags();
	}

	public static function canned() {
		return array_map(
			static fn( $k ) => array( 'id' => (int) $k['id'], 'title' => $k['title'], 'shortcut' => $k['shortcut'], 'body' => $k['body'] ),
			Db::rows( 'SELECT * FROM ' . Db::t( 'knowledge' ) . " WHERE type = 'canned' AND active = 1 ORDER BY sort_order, id" )
		);
	}
}
