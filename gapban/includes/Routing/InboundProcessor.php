<?php
/**
 * Processes durably recorded inbound provider updates.
 *
 * Security order for every update:
 *  1. Ignore anything sent by a bot (including our own echoes) and service events.
 *  2. Enrollment commands are authorized by their one-time code alone.
 *  3. Otherwise the chat must be an approved destination or an operator's private chat.
 *  4. The sender must be an active, linked operator.
 *  5. The conversation is resolved only through an exact reply mapping.
 *  6. The operator must be allowed to act on that conversation.
 *
 * @package Gapban
 */

namespace Gapban\Routing;

use Gapban\Bale\BaleProvider;
use Gapban\Chat\Assignment;
use Gapban\Chat\Audit;
use Gapban\Chat\ChatException;
use Gapban\Chat\ConversationService;
use Gapban\Core\Settings;
use Gapban\Domain\ConversationState;
use Gapban\Domain\Ids;
use Gapban\Persistence\Db;
use Gapban\Provider\InboundMessage;
use Gapban\Provider\ProviderException;
use Gapban\Queue\JobQueue;
use Gapban\Security\AttachmentStorage;
use Gapban\Security\RateLimiter;
use Gapban\Security\UploadValidator;

defined( 'ABSPATH' ) || exit;

final class InboundProcessor {

	/**
	 * Record an update durably. Returns the row id, or 0 if it was a duplicate.
	 *
	 * @param array<string, mixed> $update
	 */
	public static function record( array $update, string $bot_id ): int {
		$update_id = BaleProvider::id_string( $update['update_id'] ?? '' );
		if ( '' === $update_id ) {
			return 0;
		}
		global $wpdb;
		$table = Db::t( 'inbound_updates' );
		$n     = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (provider, bot_id, update_id, payload, status, received_at) VALUES (%s, %s, %s, %s, 'pending', %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				BaleProvider::ID,
				$bot_id,
				$update_id,
				wp_json_encode( $update ),
				Db::now()
			)
		);
		if ( 1 !== (int) $n ) {
			return 0;
		}
		$id   = (int) $wpdb->insert_id;
		$chat = BaleProvider::id_string( $update['message']['chat']['id'] ?? ( $update['edited_message']['chat']['id'] ?? '' ) );
		JobQueue::enqueue( 'process_inbound', array( 'inbound_id' => $id ), 'in:' . $chat );
		return $id;
	}

	/**
	 * Job handler.
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function handle_job( array $payload ): void {
		$row = Db::row( 'SELECT * FROM ' . Db::t( 'inbound_updates' ) . ' WHERE id = %d', (int) $payload['inbound_id'] );
		if ( ! $row || in_array( $row['status'], array( 'done', 'ignored' ), true ) ) {
			return;
		}
		Db::exec( 'UPDATE ' . Db::t( 'inbound_updates' ) . ' SET attempts = attempts + 1 WHERE id = %d', (int) $row['id'] );
		$update = json_decode( (string) $row['payload'], true );
		try {
			$result = is_array( $update ) ? self::process( $update ) : 'malformed';
			Db::update(
				'inbound_updates',
				array(
					'status'       => 'processed' === $result ? 'done' : 'ignored',
					'error'        => 'processed' === $result ? '' : substr( $result, 0, 250 ),
					'processed_at' => Db::now(),
				),
				array( 'id' => (int) $row['id'] )
			);
		} catch ( ProviderException $e ) {
			Db::update( 'inbound_updates', array( 'error' => substr( $e->getMessage(), 0, 250 ) ), array( 'id' => (int) $row['id'] ) );
			if ( ProviderException::PERMANENT === $e->kind ) {
				Db::update( 'inbound_updates', array( 'status' => 'failed' ), array( 'id' => (int) $row['id'] ) );
			}
			throw $e;
		}
	}

	/**
	 * @param array<string, mixed> $update
	 * @return string outcome code ("processed" or a reason it was ignored)
	 */
	public static function process( array $update ): string {
		$in = BaleProvider::normalize( $update );
		if ( ! $in ) {
			return 'unsupported_update';
		}
		if ( $in->sender_is_bot ) {
			return 'bot_sender';
		}
		if ( InboundMessage::KIND_SERVICE === $in->kind ) {
			return 'service_event';
		}
		if ( '' === $in->chat_id || '' === $in->sender_id ) {
			return 'anonymous_sender';
		}
		if ( $in->edited ) {
			Audit::log( 'bale', 0, 'operator.message_edited', 'bale_chat', 0, array( 'chat' => $in->chat_id, 'message' => $in->message_id ) );
			return 'edited_ignored';
		}

		$text = trim( $in->text );

		// 2. Enrollment.
		if ( preg_match( '#^/start(?:@\w+)?\s+([0-9A-Za-z]{10})$#', $text, $m ) || ( $in->is_private() && preg_match( '#^([0-9A-Z]{10})$#', $text, $m ) ) ) {
			return self::enroll_operator( $in, $m[1] );
		}
		if ( preg_match( '#^/gapban_bind(?:@\w+)?\s+([0-9A-Za-z]{10})$#', $text, $m ) ) {
			return self::bind_destination( $in, $m[1] );
		}

		// Connection test: a reply to the setup screen's test message proves receiving works.
		$test = get_option( 'gapban_test_message' );
		if ( is_array( $test ) && $in->reply_to_message_id && (string) $test['chat_id'] === $in->chat_id && (string) $test['message_id'] === $in->reply_to_message_id ) {
			update_option( 'gapban_test_reply_at', gmdate( 'Y-m-d H:i:s' ), false );
			BaleProvider::observe( 'receive_text' );
			BaleProvider::observe( 'reply_metadata' );
			self::feedback( $in, 'test_received', true );
			return 'processed';
		}

		// 3. Approved chat.
		$operator = Db::row( 'SELECT * FROM ' . Db::t( 'operators' ) . " WHERE bale_user_id = %s AND status = 'active'", $in->sender_id );
		$approved = self::is_approved_chat( $in->chat_id ) || ( $in->is_private() && $operator && $in->chat_id === $in->sender_id );
		if ( ! $approved ) {
			return 'chat_not_approved';
		}

		// 4. Known operator.
		if ( ! $operator ) {
			self::feedback( $in, 'not_operator' );
			return 'unknown_sender';
		}
		$operator_id = (int) $operator['id'];

		// Presence commands do not need a conversation.
		if ( preg_match( '#^/(online|away|offline)(?:@\w+)?$#', $text, $m ) ) {
			Db::update( 'operators', array( 'availability' => $m[1], 'away_until' => null ), array( 'id' => $operator_id ) );
			Audit::log( 'operator', $operator_id, 'operator.availability', 'operator', $operator_id, array( 'value' => $m[1] ) );
			self::feedback( $in, 'availability_' . $m[1], true );
			return 'processed';
		}

		// 5. Exact mapping.
		$router = RoutingRegistry::router( 'reply' );
		$conv   = $router ? $router->resolve( $in ) : null;
		if ( ! $conv ) {
			if ( null === $in->reply_to_message_id ) {
				self::feedback( $in, 'reply_required' );
				return 'not_a_reply';
			}
			self::feedback( $in, 'unmapped_reply' );
			return 'unmapped_reply';
		}
		BaleProvider::observe( 'reply_metadata' );
		$cid = (int) $conv['id'];

		// 6. Authorization on the conversation.
		if ( ! self::operator_may_act( $operator_id, $conv ) ) {
			self::feedback( $in, 'not_in_team' );
			return 'operator_not_allowed';
		}

		if ( preg_match( '#^/(close|claim|reopen|spam)(?:@\w+)?$#', $text, $m ) ) {
			return self::command( $m[1], $in, $conv, $operator_id );
		}
		if ( preg_match( '#^/note(?:@\w+)?\s+(.+)$#s', $text, $m ) ) {
			ConversationService::add_note( $cid, $operator_id, mb_substr( $m[1], 0, (int) Settings::get( 'max_message_length' ) ), 'bale' );
			self::map_inbound( $in, $cid, null );
			return 'processed';
		}
		if ( str_starts_with( $text, '/' ) && ! str_contains( $text, ' ' ) ) {
			self::feedback( $in, 'unknown_command' );
			return 'unknown_command';
		}
		if ( ! empty( $conv['assigned_operator_id'] ) && (int) $conv['assigned_operator_id'] !== $operator_id ) {
			self::feedback( $in, 'assigned_to_other' );
			return 'assigned_to_other';
		}
		if ( ConversationState::SPAM === $conv['status'] ) {
			self::feedback( $in, 'conversation_spam' );
			return 'conversation_spam';
		}

		$attachment_ids = array();
		if ( null !== $in->file_id ) {
			$attachment_ids[] = self::fetch_attachment( $in, $cid, $operator_id );
		} elseif ( InboundMessage::KIND_TEXT !== $in->kind ) {
			self::feedback( $in, 'unsupported_content' );
			return 'unsupported_content';
		}
		if ( '' === $text && ! $attachment_ids ) {
			return 'empty';
		}

		$message = ConversationService::post_operator_message(
			$conv,
			$operator_id,
			mb_substr( $in->text, 0, (int) Settings::get( 'max_message_length' ) ),
			'bale',
			array(
				'bale_message_id' => $in->message_id,
				'bale_chat_id'    => $in->chat_id,
			),
			$attachment_ids,
			'bale:' . $in->chat_id . ':' . $in->message_id
		);
		self::map_inbound( $in, $cid, (int) $message['id'] );
		BaleProvider::observe( 'receive_text' );
		return 'processed';
	}

	private static function enroll_operator( InboundMessage $in, string $code ): string {
		if ( ! $in->is_private() ) {
			return 'enroll_requires_private_chat';
		}
		$enrollment = Enrollment::consume( $code, 'operator' );
		if ( ! $enrollment ) {
			self::feedback( $in, 'enroll_invalid', true );
			return 'enroll_invalid';
		}
		$result = Enrollment::bind_operator( $enrollment, $in->sender_id, $in->sender_username );
		self::feedback( $in, 'enroll_' . $result, true );
		return 'processed';
	}

	private static function bind_destination( InboundMessage $in, string $code ): string {
		$enrollment = Enrollment::consume( $code, 'destination' );
		if ( ! $enrollment ) {
			self::feedback( $in, 'enroll_invalid', true );
			return 'enroll_invalid';
		}
		Enrollment::bind_destination( $enrollment, $in->chat_id, $in->chat_title ?: $in->sender_name );
		self::feedback( $in, 'destination_bound', true );
		return 'processed';
	}

	public static function is_approved_chat( string $chat_id ): bool {
		if ( '' === $chat_id ) {
			return false;
		}
		if ( (string) Settings::get( 'default_destination_chat' ) === $chat_id ) {
			return true;
		}
		if ( Db::value( 'SELECT 1 FROM ' . Db::t( 'teams' ) . ' WHERE destination_chat_id = %s LIMIT 1', $chat_id ) ) {
			return true;
		}
		// Former destinations stay approved while conversations relayed there exist.
		return (bool) Db::value(
			'SELECT 1 FROM ' . Db::t( 'provider_mappings' ) . " WHERE provider = %s AND bot_id = %s AND chat_id = %s AND direction = 'out' LIMIT 1",
			BaleProvider::ID,
			RoutingRegistry::provider()->bot_id(),
			$chat_id
		);
	}

	/**
	 * @param array<string, mixed> $conv
	 */
	private static function operator_may_act( int $operator_id, array $conv ): bool {
		if ( (int) $conv['assigned_operator_id'] === $operator_id ) {
			return true;
		}
		$team_id = (int) $conv['team_id'];
		if ( ! $team_id ) {
			return true;
		}
		$has_members = (bool) Db::value( 'SELECT 1 FROM ' . Db::t( 'team_members' ) . ' WHERE team_id = %d LIMIT 1', $team_id );
		if ( ! $has_members ) {
			// A team without explicit members is served by every linked operator.
			return true;
		}
		return (bool) Db::value( 'SELECT 1 FROM ' . Db::t( 'team_members' ) . ' WHERE team_id = %d AND operator_id = %d', $team_id, $operator_id );
	}

	/**
	 * @param array<string, mixed> $conv
	 */
	private static function command( string $command, InboundMessage $in, array $conv, int $operator_id ): string {
		$cid = (int) $conv['id'];
		try {
			switch ( $command ) {
				case 'claim':
					$ok = Assignment::claim( $cid, $operator_id, 'operator', $operator_id );
					self::feedback( $in, $ok ? 'claimed' : ( (int) $conv['assigned_operator_id'] === $operator_id ? 'already_yours' : 'claim_lost' ), true );
					break;
				case 'close':
					ConversationService::transition( $cid, 'close', 'operator', $operator_id );
					self::feedback( $in, 'closed', true );
					break;
				case 'reopen':
					ConversationService::transition( $cid, 'reopen', 'operator', $operator_id );
					self::feedback( $in, 'reopened', true );
					break;
				case 'spam':
					ConversationService::transition( $cid, 'spam', 'operator', $operator_id );
					self::feedback( $in, 'marked_spam', true );
					break;
			}
		} catch ( ChatException $e ) {
			self::feedback( $in, 'invalid_transition', true );
			return 'command_rejected';
		}
		return 'processed';
	}

	/**
	 * Download an operator file into protected storage and return the attachment id.
	 */
	private static function fetch_attachment( InboundMessage $in, int $cid, int $operator_id ): int {
		$max = (int) Settings::get( 'max_upload_mb' ) * MB_IN_BYTES;
		if ( $in->file_size > $max ) {
			self::feedback( $in, 'file_too_large' );
			throw new ProviderException( ProviderException::PERMANENT, 'file_too_large' );
		}
		$file  = RoutingRegistry::provider()->download_file( (string) $in->file_id, $max );
		$name  = UploadValidator::safe_display_name( $in->file_name ?: 'file' );
		$check = UploadValidator::validate( $file['path'], $name, $file['size'], true );
		if ( is_wp_error( $check ) ) {
			wp_delete_file( $file['path'] );
			self::feedback( $in, 'file_type_blocked' );
			throw new ProviderException( ProviderException::PERMANENT, 'file_type_blocked' );
		}
		$storage = AttachmentStorage::store( $file['path'] );
		$dims    = str_starts_with( $check['mime'], 'image/' ) ? @getimagesize( AttachmentStorage::path( $storage ) ) : false; // phpcs:ignore
		return Db::insert(
			'attachments',
			array(
				'public_id'     => Ids::public_id(),
				'conversation_id' => $cid,
				'uploader_type' => 'operator',
				'uploader_id'   => $operator_id,
				'storage_name'  => $storage,
				'original_name' => $name,
				'mime'          => $check['mime'],
				'size'          => $file['size'],
				'sha256'        => hash_file( 'sha256', AttachmentStorage::path( $storage ) ),
				'width'         => $dims ? (int) $dims[0] : 0,
				'height'        => $dims ? (int) $dims[1] : 0,
				'created_at'    => Db::now(),
			)
		);
	}

	/**
	 * Map the operator's own Bale message so replies to it resolve too.
	 */
	private static function map_inbound( InboundMessage $in, int $cid, ?int $message_id ): void {
		$router = RoutingRegistry::router( 'reply' );
		if ( $router instanceof ReplyRouter ) {
			$router->map( RoutingRegistry::provider()->bot_id(), $in->chat_id, $in->message_id, $cid, $message_id, 0, 'in' );
		}
	}

	/**
	 * Queue a short operator-facing notice as a reply. Non-essential notices are
	 * rate limited per chat to avoid feedback loops and spam.
	 */
	public static function feedback( InboundMessage $in, string $code, bool $always = false ): void {
		if ( ! $always && ! RateLimiter::hit( 'feedback:' . $in->chat_id . ':' . $code, 3, 300 ) ) {
			return;
		}
		JobQueue::enqueue(
			'notify',
			array(
				'chat_id'  => $in->chat_id,
				'reply_to' => $in->message_id,
				'code'     => $code,
			),
			'notify:' . $in->chat_id,
			0,
			3
		);
	}

	/**
	 * Operator-facing text for feedback codes (site language).
	 */
	public static function feedback_text( string $code ): string {
		$map = array(
			'not_operator'          => __( 'You are not registered as a Gapban operator, so this message was not delivered.', 'gapban' ),
			'reply_required'        => __( 'To answer a visitor, reply to one of the conversation messages sent by the bot.', 'gapban' ),
			'unmapped_reply'        => __( 'This reply is not linked to any conversation, so it was not delivered. Reply to a message the bot sent for the conversation.', 'gapban' ),
			'not_in_team'           => __( 'You are not a member of this conversation\'s department.', 'gapban' ),
			'assigned_to_other'     => __( 'This conversation is assigned to another operator. Your message was not delivered.', 'gapban' ),
			'conversation_spam'     => __( 'This conversation is marked as spam.', 'gapban' ),
			'unsupported_content'   => __( 'This message type is not supported. Send text, a photo or a file.', 'gapban' ),
			'unknown_command'       => __( 'Unknown command. Available as a reply: /claim /close /reopen /spam /note text. Anywhere: /online /away /offline', 'gapban' ),
			'claimed'               => __( 'The conversation is now assigned to you.', 'gapban' ),
			'already_yours'         => __( 'This conversation is already assigned to you.', 'gapban' ),
			'claim_lost'            => __( 'Another operator has already claimed this conversation.', 'gapban' ),
			'closed'                => __( 'Conversation closed.', 'gapban' ),
			'reopened'              => __( 'Conversation reopened.', 'gapban' ),
			'marked_spam'           => __( 'Conversation marked as spam.', 'gapban' ),
			'invalid_transition'    => __( 'That action is not possible in the conversation\'s current state.', 'gapban' ),
			'enroll_invalid'        => __( 'This code is invalid, expired or already used. Create a new one in WordPress.', 'gapban' ),
			'enroll_linked'         => __( 'Your Bale account is now linked to Gapban.', 'gapban' ),
			'enroll_already_linked' => __( 'This Bale account is already linked to another operator.', 'gapban' ),
			'destination_bound'     => __( 'This chat is now a Gapban support destination. Everyone in this chat can see conversations sent here.', 'gapban' ),
			'availability_online'   => __( 'Your status: online.', 'gapban' ),
			'availability_away'     => __( 'Your status: away.', 'gapban' ),
			'availability_offline'  => __( 'Your status: offline.', 'gapban' ),
			'file_too_large'        => __( 'The file is larger than the allowed size and was not delivered.', 'gapban' ),
			'file_type_blocked'     => __( 'This file type is not allowed and was not delivered.', 'gapban' ),
			'delivery_failed'       => __( 'Your message could not be saved. Please try again.', 'gapban' ),
			'test_received'         => __( 'Receiving works: Gapban got your reply.', 'gapban' ),
		);
		return $map[ $code ] ?? $code;
	}

	/**
	 * Job handler for notices.
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function handle_notify( array $payload ): void {
		$provider = RoutingRegistry::provider();
		try {
			$provider->send_text( (string) $payload['chat_id'], '🤖 ' . self::feedback_text( (string) $payload['code'] ), (string) $payload['reply_to'] );
		} catch ( ProviderException $e ) {
			// Replying to a deleted message fails permanently; retry once without the reply link.
			if ( ProviderException::PERMANENT === $e->kind && ! empty( $payload['reply_to'] ) ) {
				$provider->send_text( (string) $payload['chat_id'], '🤖 ' . self::feedback_text( (string) $payload['code'] ) );
				return;
			}
			throw $e;
		}
	}
}
