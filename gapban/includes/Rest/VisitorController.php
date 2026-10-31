<?php
/**
 * Public visitor API (gapban/v1). Authenticated with the X-Gapban-Session bearer;
 * every endpoint is scoped to the authenticated visitor's own conversation.
 *
 * @package Gapban
 */

namespace Gapban\Rest;

use Gapban\Chat\Availability;
use Gapban\Chat\ChatException;
use Gapban\Chat\ConversationService;
use Gapban\Core\Locale;
use Gapban\Core\Settings;
use Gapban\Domain\ConversationState;
use Gapban\Domain\Ids;
use Gapban\Persistence\Db;
use Gapban\Rules\RuleEvents;
use Gapban\Security\AttachmentStorage;
use Gapban\Security\ClientIp;
use Gapban\Security\RateLimiter;
use Gapban\Security\Redactor;
use Gapban\Security\UploadValidator;
use Gapban\Security\VisitorSessions;

defined( 'ABSPATH' ) || exit;

final class VisitorController {

	public const NS = 'gapban/v1';

	public static function register(): void {
		$routes = array(
			array( '/session', 'POST', 'create_session', false ),
			array( '/bootstrap', 'GET', 'bootstrap', false ),
			array( '/conversation', 'GET', 'get_conversation', true ),
			array( '/conversation', 'POST', 'start_conversation', true ),
			array( '/conversation/close', 'POST', 'close_conversation', true ),
			array( '/messages', 'GET', 'list_messages', true ),
			array( '/messages', 'POST', 'send_message', true ),
			array( '/read', 'POST', 'mark_read', true ),
			array( '/typing', 'POST', 'typing', true ),
			array( '/uploads', 'POST', 'upload', true ),
			array( '/attachments/(?P<id>[0-9A-HJKMNP-TV-Z]{26})', 'GET', 'download', true ),
			array( '/rating', 'POST', 'rate', true ),
			array( '/transcript', 'GET', 'transcript', true ),
			array( '/rule-events', 'POST', 'rule_event', false ),
		);
		foreach ( $routes as [ $path, $method, $callback, $auth ] ) {
			register_rest_route(
				self::NS,
				$path,
				array(
					'methods'             => $method,
					'callback'            => self::guard( $callback, $auth ),
					'permission_callback' => '__return_true', // Authorization happens in guard() with the visitor bearer.
				)
			);
		}
	}

	/**
	 * Wrap a handler with session authentication, block checks and no-store headers.
	 */
	private static function guard( string $method, bool $auth ): callable {
		return static function ( \WP_REST_Request $request ) use ( $method, $auth ) {
			$session = VisitorSessions::from_request( $request );
			if ( $auth || null !== $session ) {
				if ( ! $session ) {
					return self::no_store( new \WP_Error( 'gapban_session_invalid', __( 'Your chat session has expired. Please refresh.', 'gapban' ), array( 'status' => 401 ) ) );
				}
				// A session tied to a WordPress account is only valid while that account is logged in.
				if ( ! empty( $session['wp_user_id'] ) && (int) $session['wp_user_id'] !== self::cookie_user_id() ) {
					return self::no_store( new \WP_Error( 'gapban_session_owner', __( 'Your chat session has expired. Please refresh.', 'gapban' ), array( 'status' => 401 ) ) );
				}
				if ( (int) $session['blocked'] ) {
					return self::no_store( new \WP_Error( 'gapban_blocked', __( 'Chat is not available.', 'gapban' ), array( 'status' => 403 ) ) );
				}
			}
			$limit_key = $session ? 's:' . $session['session_id'] : 'ip:' . ClientIp::hash();
			if ( ! RateLimiter::hit( 'req:' . $limit_key, 120, 60 ) ) {
				return self::no_store( new \WP_Error( 'gapban_rate_limited', __( 'Too many requests. Please wait a moment.', 'gapban' ), array( 'status' => 429 ) ) );
			}
			try {
				$result = self::$method( $request, $session );
			} catch ( ChatException $e ) {
				$result = $e->to_wp_error();
			}
			return self::no_store( $result );
		};
	}

	/**
	 * @param mixed $result
	 */
	private static function no_store( $result ): \WP_REST_Response {
		$response = Serializer::response( $result );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}

	/**
	 * The logged-in user from the auth cookie (REST requests without a nonce are
	 * otherwise anonymous). Read-only use; no state changes are authorized by it alone.
	 */
	public static function cookie_user_id(): int {
		$uid = wp_validate_auth_cookie( '', 'logged_in' );
		return $uid ? (int) $uid : 0;
	}

	/* ------------------------------------------------------------------ */

	public static function create_session( \WP_REST_Request $request, ?array $session ) {
		if ( Settings::get( 'honeypot' ) && '' !== (string) $request->get_param( 'website' ) ) {
			return new \WP_Error( 'gapban_rejected', __( 'Request rejected.', 'gapban' ), array( 'status' => 400 ) );
		}
		if ( ! Settings::get( 'widget_enabled' ) ) {
			return new \WP_Error( 'gapban_disabled', __( 'Chat is not available.', 'gapban' ), array( 'status' => 403 ) );
		}
		$per_hour = (int) Settings::get( 'sessions_per_ip_per_hour' );
		if ( ! RateLimiter::hit( 'session:' . ClientIp::hash(), $per_hour, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'gapban_rate_limited', __( 'Too many requests. Please wait a moment.', 'gapban' ), array( 'status' => 429 ) );
		}
		$created = VisitorSessions::create( self::cookie_user_id() ?: null );
		return array(
			'token'      => $created['token'],
			'visitor_id' => $created['visitor_public_id'],
			'expires_at' => Serializer::iso( $created['expires_at'] ),
		);
	}

	/**
	 * Per-visitor dynamic state. Never cached.
	 */
	public static function bootstrap( \WP_REST_Request $request, ?array $session ) {
		$user  = self::cookie_user_id();
		$roles = $user ? array_values( (array) get_userdata( $user )->roles ) : array();
		$out   = array(
			'availability' => Availability::state(),
			'visitor'      => array(
				'logged_in' => (bool) $user,
				'roles'     => $roles,
			),
			'conversation' => null,
			'unread'       => 0,
			'session'      => null !== $session,
		);
		if ( $session && $user && empty( $session['wp_user_id'] ) ) {
			// Guest session while logged in: the widget should start an account session.
			$out['session_upgrade'] = true;
		}
		if ( $session ) {
			$conv = ConversationService::current_for_visitor( (int) $session['visitor_id'] );
			if ( $conv ) {
				$out['conversation'] = Serializer::conversation_for_visitor( $conv );
				$out['unread']       = (int) Db::value(
					'SELECT COUNT(*) FROM ' . Db::t( 'messages' ) . " WHERE conversation_id = %d AND id > %d AND is_internal = 0 AND author_type IN ('operator','automation')",
					(int) $conv['id'],
					(int) $conv['visitor_read_message_id']
				);
			}
			$out['profile'] = array(
				'name'  => (string) $session['name'],
				'email' => (string) $session['email'],
				'phone' => (string) $session['phone'],
			);
		}
		return $out;
	}

	public static function get_conversation( \WP_REST_Request $request, array $session ) {
		$conv = ConversationService::current_for_visitor( (int) $session['visitor_id'] );
		if ( ! $conv ) {
			return array( 'conversation' => null, 'messages' => array() );
		}
		$messages = ConversationService::messages_before( (int) $conv['id'], 0, false, 30 );
		return array(
			'conversation' => Serializer::conversation_for_visitor( $conv ),
			'messages'     => Serializer::messages_for_visitor( $messages, $conv ),
			'has_more'     => count( $messages ) >= 30,
		);
	}

	/**
	 * Start (or return) the visitor's active conversation, saving pre-chat data.
	 */
	public static function start_conversation( \WP_REST_Request $request, array $session ) {
		$visitor_id = (int) $session['visitor_id'];
		$existing   = ConversationService::current_for_visitor( $visitor_id );
		if ( $existing && ConversationState::is_active( (string) $existing['status'] ) ) {
			return array( 'conversation' => Serializer::conversation_for_visitor( $existing ) );
		}

		$profile = self::validate_prechat( $request );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		if ( Settings::get( 'consent_required' ) && ! filter_var( $request->get_param( 'consent' ), FILTER_VALIDATE_BOOLEAN ) ) {
			return new \WP_Error( 'gapban_consent_required', __( 'Please accept the privacy notice to start chatting.', 'gapban' ), array( 'status' => 400 ) );
		}

		$update = array_filter(
			array(
				'name'          => $profile['name'] ?? null,
				'email'         => $profile['email'] ?? null,
				'phone'         => $profile['phone'] ?? null,
				'custom_fields' => ! empty( $profile['custom'] ) ? wp_json_encode( $profile['custom'] ) : null,
				'locale'        => substr( sanitize_text_field( (string) $request->get_param( 'locale' ) ), 0, 16 ) ?: null,
				'consent_at'    => Settings::get( 'consent_required' ) ? Db::now() : null,
			),
			static fn( $v ) => null !== $v && '' !== $v
		);
		if ( $update ) {
			Db::update( 'visitors', $update, array( 'id' => $visitor_id ) );
		}

		$team_id = (int) $request->get_param( 'department' );
		if ( $team_id && ! Db::value( 'SELECT 1 FROM ' . Db::t( 'teams' ) . ' WHERE id = %d AND active = 1 AND public = 1', $team_id ) ) {
			$team_id = 0;
		}
		$state = Availability::state( $team_id ?: null );

		$conv = ConversationService::create(
			$visitor_id,
			array(
				'team_id'          => $team_id,
				'page_url'         => Redactor::page_url( (string) $request->get_param( 'page_url' ) ),
				'page_title'       => (string) $request->get_param( 'page_title' ),
				'campaign_rule_id' => (int) $request->get_param( 'campaign_rule_id' ),
				'offline'          => Availability::ONLINE !== $state['state'],
			)
		);

		$greeting = trim( (string) Settings::get( 'automated_greeting' ) );
		if ( '' !== $greeting ) {
			ConversationService::post_automated( (int) $conv['id'], $greeting, ConversationService::AUTHOR_AUTOMATION, array( 'type' => 'greeting' ) );
		}
		if ( $request->get_param( 'campaign_rule_id' ) ) {
			RuleEvents::record( (int) $request->get_param( 'campaign_rule_id' ), $visitor_id, 'converted' );
		}
		return array( 'conversation' => Serializer::conversation_for_visitor( ConversationService::get( (int) $conv['id'] ) ) );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function validate_prechat( \WP_REST_Request $request ) {
		$fields  = (array) Settings::get( 'prechat_fields' );
		$enabled = (bool) Settings::get( 'prechat_enabled' );
		$out     = array();
		$errors  = array();

		$name = mb_substr( sanitize_text_field( (string) $request->get_param( 'name' ) ), 0, 120 );
		if ( $enabled && 'required' === ( $fields['name'] ?? '' ) && '' === $name ) {
			$errors['name'] = __( 'Please enter your name.', 'gapban' );
		}
		if ( '' !== $name ) {
			$out['name'] = $name;
		}

		$email = trim( (string) $request->get_param( 'email' ) );
		if ( '' !== $email ) {
			if ( ! is_email( $email ) ) {
				$errors['email'] = __( 'Please enter a valid email address.', 'gapban' );
			} else {
				$out['email'] = sanitize_email( $email );
			}
		} elseif ( $enabled && 'required' === ( $fields['email'] ?? '' ) ) {
			$errors['email'] = __( 'Please enter your email address.', 'gapban' );
		}

		$phone_raw = (string) $request->get_param( 'phone' );
		if ( '' !== trim( $phone_raw ) ) {
			$phone = Locale::normalize_phone( $phone_raw );
			if ( '' === $phone ) {
				$errors['phone'] = __( 'Please enter a valid mobile number, e.g. 0912 345 6789.', 'gapban' );
			} else {
				$out['phone'] = $phone;
			}
		} elseif ( $enabled && 'required' === ( $fields['phone'] ?? '' ) ) {
			$errors['phone'] = __( 'Please enter your mobile number.', 'gapban' );
		}

		$custom_in = (array) $request->get_param( 'custom' );
		foreach ( (array) Settings::get( 'custom_fields' ) as $field ) {
			$value = mb_substr( sanitize_text_field( (string) ( $custom_in[ $field['key'] ] ?? '' ) ), 0, 190 );
			if ( 'number' === $field['type'] && '' !== $value ) {
				$value = Locale::normalize_digits( $value );
				if ( ! is_numeric( $value ) ) {
					$errors[ 'custom.' . $field['key'] ] = __( 'Please enter a number.', 'gapban' );
				}
			}
			if ( 'url' === $field['type'] && '' !== $value && ! wp_http_validate_url( $value ) ) {
				$errors[ 'custom.' . $field['key'] ] = __( 'Please enter a valid URL.', 'gapban' );
			}
			if ( $enabled && $field['required'] && '' === $value ) {
				$errors[ 'custom.' . $field['key'] ] = __( 'This field is required.', 'gapban' );
			}
			if ( '' !== $value ) {
				$out['custom'][ $field['label'] ] = $value;
			}
		}

		if ( $errors ) {
			return new \WP_Error( 'gapban_invalid_fields', __( 'Please check the highlighted fields.', 'gapban' ), array( 'status' => 422, 'fields' => $errors ) );
		}
		return $out;
	}

	public static function close_conversation( \WP_REST_Request $request, array $session ) {
		$conv = self::own_conversation( $session );
		if ( ConversationState::is_active( (string) $conv['status'] ) ) {
			ConversationService::transition( (int) $conv['id'], 'close', 'visitor', (int) $session['visitor_id'] );
		}
		return array( 'conversation' => Serializer::conversation_for_visitor( ConversationService::get( (int) $conv['id'] ) ) );
	}

	public static function list_messages( \WP_REST_Request $request, array $session ) {
		$conv   = self::own_conversation( $session );
		$after  = max( 0, (int) $request->get_param( 'after' ) );
		$before = max( 0, (int) $request->get_param( 'before' ) );
		if ( $before ) {
			$messages = ConversationService::messages_before( (int) $conv['id'], $before, false, 30 );
			$has_more = count( $messages ) >= 30;
		} else {
			$messages = ConversationService::messages_after( (int) $conv['id'], $after, false, 50 );
			$has_more = count( $messages ) >= 50;
		}
		return array(
			'conversation' => Serializer::conversation_for_visitor( $conv ),
			'messages'     => Serializer::messages_for_visitor( $messages, $conv ),
			'has_more'     => $has_more,
			'availability' => Availability::state( $conv['team_id'] ? (int) $conv['team_id'] : null ),
		);
	}

	public static function send_message( \WP_REST_Request $request, array $session ) {
		if ( ! RateLimiter::hit( 'msg:' . $session['session_id'], (int) Settings::get( 'messages_per_minute' ), 60 ) ) {
			return new \WP_Error( 'gapban_rate_limited', __( 'You are sending messages too quickly. Please wait a moment.', 'gapban' ), array( 'status' => 429 ) );
		}
		$body = self::clean_body( (string) $request->get_param( 'body' ) );
		$key  = (string) $request->get_param( 'client_key' );
		if ( ! preg_match( '/^[A-Za-z0-9_\-]{8,64}$/', $key ) ) {
			return new \WP_Error( 'gapban_invalid_key', __( 'Invalid request.', 'gapban' ), array( 'status' => 400 ) );
		}
		$attachments = self::own_attachment_ids( (array) $request->get_param( 'attachments' ), $session );
		if ( '' === trim( $body ) && ! $attachments ) {
			return new \WP_Error( 'gapban_empty', __( 'The message is empty.', 'gapban' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $body ) > (int) Settings::get( 'max_message_length' ) ) {
			return new \WP_Error( 'gapban_too_long', __( 'The message is too long.', 'gapban' ), array( 'status' => 413 ) );
		}
		$conv = ConversationService::current_for_visitor( (int) $session['visitor_id'] );
		if ( ! $conv ) {
			throw new ChatException( 'no_conversation', 404 );
		}
		$message = ConversationService::post_visitor_message( $conv, (int) $session['visitor_id'], $body, $key, $attachments );
		$conv    = ConversationService::get( (int) $conv['id'] );
		return array(
			'message'      => Serializer::messages_for_visitor( array( $message ), $conv )[0] ?? null,
			'conversation' => Serializer::conversation_for_visitor( $conv ),
		);
	}

	/**
	 * Normalize line endings, strip control characters, keep emoji/ZWNJ intact.
	 */
	public static function clean_body( string $body ): string {
		$body = str_replace( array( "\r\n", "\r" ), "\n", $body );
		$body = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body ) ?? '';
		$body = wp_check_invalid_utf8( $body, true );
		return trim( preg_replace( "/\n{4,}/", "\n\n\n", $body ) ?? '' );
	}

	/**
	 * @param array<int, mixed> $public_ids
	 * @return int[]
	 */
	private static function own_attachment_ids( array $public_ids, array $session ): array {
		$public_ids = array_values( array_filter( array_slice( $public_ids, 0, 5 ), static fn( $id ) => is_string( $id ) && Ids::is_public_id( $id ) ) );
		if ( ! $public_ids ) {
			return array();
		}
		$conv = ConversationService::current_for_visitor( (int) $session['visitor_id'] );
		if ( ! $conv ) {
			return array();
		}
		$rows = Db::rows(
			'SELECT id FROM ' . Db::t( 'attachments' ) . " WHERE conversation_id = %d AND uploader_type = 'visitor' AND uploader_id = %d AND message_id IS NULL AND public_id IN (" . Db::in( $public_ids, '%s' ) . ')',
			(int) $conv['id'],
			(int) $session['visitor_id'],
			...$public_ids
		);
		return array_map( static fn( $r ) => (int) $r['id'], $rows );
	}

	public static function mark_read( \WP_REST_Request $request, array $session ) {
		$conv = self::own_conversation( $session );
		$mid  = min( (int) $request->get_param( 'message_id' ), (int) $conv['last_message_id'] );
		Db::exec( 'UPDATE ' . Db::t( 'conversations' ) . ' SET visitor_read_message_id = GREATEST(visitor_read_message_id, %d) WHERE id = %d', $mid, (int) $conv['id'] );
		return array( 'ok' => true );
	}

	public static function typing( \WP_REST_Request $request, array $session ) {
		$conv = self::own_conversation( $session );
		if ( ! RateLimiter::hit( 'typing:' . $session['session_id'], 30, 60 ) ) {
			return array( 'ok' => false );
		}
		$until = filter_var( $request->get_param( 'typing' ), FILTER_VALIDATE_BOOLEAN ) ? Db::at( time() + 6 ) : null;
		Db::update( 'conversations', array( 'visitor_typing_until' => $until ), array( 'id' => (int) $conv['id'] ) );
		return array( 'ok' => true );
	}

	public static function upload( \WP_REST_Request $request, array $session ) {
		if ( ! Settings::get( 'allow_attachments' ) ) {
			return new \WP_Error( 'gapban_uploads_disabled', __( 'File uploads are disabled.', 'gapban' ), array( 'status' => 403 ) );
		}
		if ( ! RateLimiter::hit( 'upload:' . $session['session_id'], 10, 600 ) ) {
			return new \WP_Error( 'gapban_rate_limited', __( 'Too many uploads. Please wait a moment.', 'gapban' ), array( 'status' => 429 ) );
		}
		$conv = self::own_conversation( $session );
		$used = (int) Db::value( 'SELECT COALESCE(SUM(size),0) FROM ' . Db::t( 'attachments' ) . " WHERE conversation_id = %d AND uploader_type = 'visitor'", (int) $conv['id'] );
		if ( $used > 10 * (int) Settings::get( 'max_upload_mb' ) * MB_IN_BYTES ) {
			return new \WP_Error( 'gapban_quota', __( 'The upload quota for this conversation is full.', 'gapban' ), array( 'status' => 413 ) );
		}
		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;
		if ( ! $file || ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'gapban_upload_failed', __( 'The upload failed. Please try again.', 'gapban' ), array( 'status' => 400 ) );
		}
		$voice = filter_var( $request->get_param( 'voice' ), FILTER_VALIDATE_BOOLEAN ) && Settings::get( 'allow_voice' );
		$name  = UploadValidator::safe_display_name( (string) $file['name'] );
		$check = UploadValidator::validate( $file['tmp_name'], $name, (int) $file['size'], $voice );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		return self::store_attachment( $file['tmp_name'], true, $name, $check['mime'], (int) $file['size'], (int) $conv['id'], 'visitor', (int) $session['visitor_id'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function store_attachment( string $tmp, bool $uploaded, string $name, string $mime, int $size, int $conversation_id, string $uploader_type, int $uploader_id ): array {
		$hash    = hash_file( 'sha256', $tmp );
		$dims    = str_starts_with( $mime, 'image/' ) ? @getimagesize( $tmp ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$storage = AttachmentStorage::store( $tmp, $uploaded );
		$public  = Ids::public_id();
		Db::insert(
			'attachments',
			array(
				'public_id'       => $public,
				'conversation_id' => $conversation_id,
				'uploader_type'   => $uploader_type,
				'uploader_id'     => $uploader_id,
				'storage_name'    => $storage,
				'original_name'   => $name,
				'mime'            => $mime,
				'size'            => $size,
				'sha256'          => $hash,
				'width'           => $dims ? (int) $dims[0] : 0,
				'height'          => $dims ? (int) $dims[1] : 0,
				'created_at'      => Db::now(),
			)
		);
		return array(
			'attachment' => Serializer::attachment(
				array(
					'public_id'     => $public,
					'original_name' => $name,
					'mime'          => $mime,
					'size'          => $size,
					'width'         => $dims ? (int) $dims[0] : 0,
					'height'        => $dims ? (int) $dims[1] : 0,
				)
			),
		);
	}

	public static function download( \WP_REST_Request $request, array $session ) {
		$att = Db::row(
			'SELECT a.* FROM ' . Db::t( 'attachments' ) . ' a JOIN ' . Db::t( 'conversations' ) . ' c ON c.id = a.conversation_id
			 WHERE a.public_id = %s AND c.visitor_id = %d',
			(string) $request['id'],
			(int) $session['visitor_id']
		);
		// Unsent visitor uploads are visible to their uploader; operator files only once attached to a visible message.
		if ( ! $att || ( 'operator' === $att['uploader_type'] && ( ! $att['message_id'] || (int) Db::value( 'SELECT is_internal FROM ' . Db::t( 'messages' ) . ' WHERE id = %d', (int) $att['message_id'] ) ) ) ) {
			return new \WP_Error( 'gapban_not_found', __( 'File not found.', 'gapban' ), array( 'status' => 404 ) );
		}
		AttachmentStream::send( $att );
	}

	public static function rate( \WP_REST_Request $request, array $session ) {
		if ( ! Settings::get( 'csat_enabled' ) ) {
			return new \WP_Error( 'gapban_disabled', __( 'Ratings are disabled.', 'gapban' ), array( 'status' => 403 ) );
		}
		$conv  = self::own_conversation( $session );
		$score = (int) Locale::normalize_digits( (string) $request->get_param( 'score' ) );
		if ( $score < 1 || $score > 5 ) {
			return new \WP_Error( 'gapban_invalid_score', __( 'Please choose a rating from 1 to 5.', 'gapban' ), array( 'status' => 400 ) );
		}
		if ( empty( $conv['first_human_response_at'] ) ) {
			return new \WP_Error( 'gapban_not_ratable', __( 'You can rate the conversation after an operator has replied.', 'gapban' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Db::t( 'ratings' ) . ' (conversation_id, operator_id, score, comment, created_at) VALUES (%d, %d, %d, %s, %s)
				 ON DUPLICATE KEY UPDATE score = VALUES(score), comment = VALUES(comment), created_at = VALUES(created_at)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(int) $conv['id'],
				(int) $conv['assigned_operator_id'],
				$score,
				mb_substr( sanitize_textarea_field( (string) $request->get_param( 'comment' ) ), 0, 1000 ),
				Db::now()
			)
		);
		return array( 'ok' => true );
	}

	public static function transcript( \WP_REST_Request $request, array $session ) {
		if ( ! Settings::get( 'transcript_enabled' ) ) {
			return new \WP_Error( 'gapban_disabled', __( 'Transcripts are disabled.', 'gapban' ), array( 'status' => 403 ) );
		}
		$conv = self::own_conversation( $session );
		$text = \Gapban\Chat\Transcript::text( $conv, false );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $conv['reference'] . '.txt"' );
		header( 'Cache-Control: no-store, private' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text download.
		exit;
	}

	public static function rule_event( \WP_REST_Request $request, ?array $session ) {
		$rule  = (int) $request->get_param( 'rule_id' );
		$event = (string) $request->get_param( 'event' );
		if ( ! in_array( $event, array( 'shown', 'clicked', 'dismissed' ), true ) || ! $rule ) {
			return new \WP_Error( 'gapban_invalid', __( 'Invalid request.', 'gapban' ), array( 'status' => 400 ) );
		}
		if ( ! RateLimiter::hit( 'rule:' . ClientIp::hash(), 60, 60 ) ) {
			return array( 'ok' => false );
		}
		RuleEvents::record( $rule, $session ? (int) $session['visitor_id'] : null, $event );
		return array( 'ok' => true );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function own_conversation( array $session ): array {
		$conv = ConversationService::current_for_visitor( (int) $session['visitor_id'] );
		if ( ! $conv ) {
			throw new ChatException( 'no_conversation', 404 );
		}
		return $conv;
	}
}
