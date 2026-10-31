<?php
/**
 * Bale implementation of the provider adapter.
 *
 * @package Gapban
 */

namespace Gapban\Bale;

use Gapban\Provider\InboundMessage;
use Gapban\Provider\ProviderException;
use Gapban\Provider\ProviderInterface;

defined( 'ABSPATH' ) || exit;

final class BaleProvider implements ProviderInterface {

	public const ID                = 'bale';
	public const IDENTITY_OPTION   = 'gapban_bot_identity';
	public const OBSERVED_OPTION   = 'gapban_capability_observations';

	private BaleClient $client;

	public function __construct( ?BaleClient $client = null ) {
		$this->client = $client ?? new BaleClient();
	}

	public function id(): string {
		return self::ID;
	}

	public function client(): BaleClient {
		return $this->client;
	}

	public function is_configured(): bool {
		return $this->client->has_token();
	}

	public function bot_id(): string {
		$identity = get_option( self::IDENTITY_OPTION );
		if ( is_array( $identity ) && ! empty( $identity['id'] ) && ( $identity['token_bot_id'] ?? '' ) === $this->client->token_bot_id() ) {
			return (string) $identity['id'];
		}
		return $this->client->token_bot_id();
	}

	/**
	 * Verify the token with getMe and remember the bot identity.
	 *
	 * @return array{id:string, username:string, first_name:string}
	 * @throws ProviderException
	 */
	public function verify(): array {
		$me = $this->client->call( 'getMe' );
		if ( ! is_array( $me ) || ! isset( $me['id'] ) ) {
			throw new ProviderException( ProviderException::PERMANENT, 'getMe_unexpected_shape' );
		}
		$identity = array(
			'id'           => self::id_string( $me['id'] ),
			'username'     => sanitize_text_field( (string) ( $me['username'] ?? '' ) ),
			'first_name'   => sanitize_text_field( (string) ( $me['first_name'] ?? '' ) ),
			'token_bot_id' => $this->client->token_bot_id(),
			'verified_at'  => gmdate( 'Y-m-d H:i:s' ),
		);
		update_option( self::IDENTITY_OPTION, $identity, false );
		self::observe( 'getMe' );
		return $identity;
	}

	public function send_text( string $chat_id, string $text, ?string $reply_to = null ): string {
		$params = array(
			'chat_id' => $chat_id,
			'text'    => $text,
		);
		if ( $reply_to ) {
			$params['reply_to_message_id'] = $reply_to;
		}
		$result = $this->client->call( 'sendMessage', $params );
		self::observe( 'sendMessage' );
		return self::message_id_from( $result );
	}

	public function send_file( string $chat_id, string $kind, string $path, string $filename, string $mime, string $caption = '', ?string $reply_to = null ): string {
		$method = 'photo' === $kind ? 'sendPhoto' : 'sendDocument';
		$field  = 'photo' === $kind ? 'photo' : 'document';
		$params = array(
			'chat_id' => $chat_id,
			'caption' => '' === $caption ? null : $caption,
		);
		if ( $reply_to ) {
			$params['reply_to_message_id'] = $reply_to;
		}
		$result = $this->client->call(
			$method,
			$params,
			array(
				$field => array(
					'path'     => $path,
					'filename' => $filename,
					'mime'     => $mime,
				),
			),
			60
		);
		self::observe( $method );
		return self::message_id_from( $result );
	}

	public function download_file( string $file_id, int $max_bytes ): array {
		$file = $this->client->call( 'getFile', array( 'file_id' => $file_id ) );
		if ( ! is_array( $file ) || empty( $file['file_path'] ) ) {
			throw new ProviderException( ProviderException::PERMANENT, 'getFile_no_path' );
		}
		if ( isset( $file['file_size'] ) && (int) $file['file_size'] > $max_bytes ) {
			throw new ProviderException( ProviderException::PERMANENT, 'download_size' );
		}
		$result = $this->client->download( (string) $file['file_path'], $max_bytes );
		self::observe( 'getFile' );
		return $result;
	}

	/**
	 * @param mixed $result
	 */
	private static function message_id_from( $result ): string {
		if ( ! is_array( $result ) || ! isset( $result['message_id'] ) ) {
			// The send may have happened but we cannot map it.
			throw new ProviderException( ProviderException::UNCERTAIN, 'missing_message_id' );
		}
		return self::id_string( $result['message_id'] );
	}

	/**
	 * Provider ids may exceed PHP_INT_MAX on 32-bit builds and JS safe integers; keep strings.
	 *
	 * @param mixed $value
	 */
	public static function id_string( $value ): string {
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			return number_format( $value, 0, '.', '' );
		}
		$value = (string) $value;
		return preg_match( '/^-?\d{1,20}$/', $value ) ? $value : '';
	}

	/**
	 * Normalize a raw update. Returns null for updates Gapban ignores.
	 *
	 * @param array<string, mixed> $update
	 */
	public static function normalize( array $update ): ?InboundMessage {
		$edited = false;
		if ( isset( $update['message'] ) && is_array( $update['message'] ) ) {
			$m = $update['message'];
		} elseif ( isset( $update['edited_message'] ) && is_array( $update['edited_message'] ) ) {
			$m      = $update['edited_message'];
			$edited = true;
		} else {
			return null;
		}

		$chat = is_array( $m['chat'] ?? null ) ? $m['chat'] : array();
		$from = is_array( $m['from'] ?? null ) ? $m['from'] : array();

		$kind      = InboundMessage::KIND_OTHER;
		$text      = '';
		$file_id   = null;
		$file_name = '';
		$file_mime = '';
		$file_size = 0;

		if ( isset( $m['text'] ) && is_string( $m['text'] ) ) {
			$kind = InboundMessage::KIND_TEXT;
			$text = $m['text'];
		} elseif ( ! empty( $m['photo'] ) && is_array( $m['photo'] ) ) {
			$kind = InboundMessage::KIND_PHOTO;
			// Largest size is last in the array by convention; pick max file_size defensively.
			$best = null;
			foreach ( $m['photo'] as $size ) {
				if ( is_array( $size ) && ! empty( $size['file_id'] ) && ( null === $best || (int) ( $size['file_size'] ?? 0 ) >= (int) ( $best['file_size'] ?? 0 ) ) ) {
					$best = $size;
				}
			}
			$file_id   = $best ? (string) $best['file_id'] : null;
			$file_size = $best ? (int) ( $best['file_size'] ?? 0 ) : 0;
			$file_mime = 'image/jpeg';
			$file_name = 'photo.jpg';
		} else {
			foreach ( array( 'document' => InboundMessage::KIND_DOCUMENT, 'voice' => InboundMessage::KIND_VOICE, 'audio' => InboundMessage::KIND_AUDIO ) as $field => $k ) {
				if ( ! empty( $m[ $field ] ) && is_array( $m[ $field ] ) && ! empty( $m[ $field ]['file_id'] ) ) {
					$kind      = $k;
					$file_id   = (string) $m[ $field ]['file_id'];
					$file_name = (string) ( $m[ $field ]['file_name'] ?? ( 'voice' === $field ? 'voice.ogg' : 'file' ) );
					$file_mime = (string) ( $m[ $field ]['mime_type'] ?? '' );
					$file_size = (int) ( $m[ $field ]['file_size'] ?? 0 );
					break;
				}
			}
		}
		if ( InboundMessage::KIND_OTHER === $kind && ( isset( $m['new_chat_members'] ) || isset( $m['left_chat_member'] ) || isset( $m['new_chat_title'] ) || isset( $m['group_chat_created'] ) || isset( $m['pinned_message'] ) ) ) {
			$kind = InboundMessage::KIND_SERVICE;
		}
		if ( '' === $text && isset( $m['caption'] ) && is_string( $m['caption'] ) ) {
			$text = $m['caption'];
		}

		$reply_to = null;
		if ( isset( $m['reply_to_message'] ) && is_array( $m['reply_to_message'] ) && isset( $m['reply_to_message']['message_id'] ) ) {
			$reply_to = self::id_string( $m['reply_to_message']['message_id'] );
		}

		$name = trim( (string) ( $from['first_name'] ?? '' ) . ' ' . (string) ( $from['last_name'] ?? '' ) );

		return new InboundMessage(
			self::id_string( $update['update_id'] ?? '' ),
			self::id_string( $chat['id'] ?? '' ),
			(string) ( $chat['type'] ?? '' ),
			self::id_string( $m['message_id'] ?? '' ),
			self::id_string( $from['id'] ?? '' ),
			! empty( $from['is_bot'] ),
			mb_substr( sanitize_text_field( $name ), 0, 120 ),
			mb_substr( sanitize_text_field( (string) ( $from['username'] ?? '' ) ), 0, 64 ),
			$kind,
			$text,
			$reply_to ?: null,
			$file_id,
			$file_name,
			$file_mime,
			$file_size,
			$edited,
			mb_substr( sanitize_text_field( (string) ( $chat['title'] ?? '' ) ), 0, 190 ),
			(int) ( $m['date'] ?? 0 )
		);
	}

	/**
	 * Record that an operation was actually observed working on this installation.
	 */
	public static function observe( string $operation ): void {
		$all = get_option( self::OBSERVED_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		if ( empty( $all[ $operation ] ) || strtotime( $all[ $operation ] . ' UTC' ) < time() - HOUR_IN_SECONDS ) {
			$all[ $operation ] = gmdate( 'Y-m-d H:i:s' );
			update_option( self::OBSERVED_OPTION, $all, false );
		}
	}

	public function capabilities(): array {
		return Capabilities::matrix();
	}
}
