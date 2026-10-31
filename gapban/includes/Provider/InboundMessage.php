<?php
/**
 * Normalized inbound message from any provider.
 *
 * @package Gapban
 */

namespace Gapban\Provider;

defined( 'ABSPATH' ) || exit;

final class InboundMessage {

	public const KIND_TEXT     = 'text';
	public const KIND_PHOTO    = 'photo';
	public const KIND_DOCUMENT = 'document';
	public const KIND_AUDIO    = 'audio';
	public const KIND_VOICE    = 'voice';
	public const KIND_SERVICE  = 'service';
	public const KIND_OTHER    = 'other';

	public function __construct(
		public string $update_id,
		public string $chat_id,
		public string $chat_type,
		public string $message_id,
		public string $sender_id,
		public bool $sender_is_bot,
		public string $sender_name,
		public string $sender_username,
		public string $kind,
		public string $text,
		public ?string $reply_to_message_id,
		public ?string $file_id = null,
		public string $file_name = '',
		public string $file_mime = '',
		public int $file_size = 0,
		public bool $edited = false,
		public string $chat_title = '',
		public int $date = 0
	) {
	}

	public function is_private(): bool {
		return 'private' === $this->chat_type;
	}
}
