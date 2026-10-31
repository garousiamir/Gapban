<?php
/**
 * Reply Mode: every website message is relayed into the conversation's destination
 * chat with a readable reference; operators answer by replying to any relayed
 * message. Resolution uses only exact (provider, bot, chat, message id) mappings.
 *
 * @package Gapban
 */

namespace Gapban\Routing;

use Gapban\Core\Settings;
use Gapban\Persistence\Db;
use Gapban\Provider\InboundMessage;
use Gapban\Provider\ProviderException;
use Gapban\Provider\ProviderInterface;
use Gapban\Security\AttachmentStorage;

defined( 'ABSPATH' ) || exit;

final class ReplyRouter implements RoutingInterface {

	public const ATTACHMENT_PART_OFFSET = 100;

	private ProviderInterface $provider;

	public function __construct( ProviderInterface $provider ) {
		$this->provider = $provider;
	}

	public function mode(): string {
		return 'reply';
	}

	public function available(): bool {
		return true;
	}

	public function unavailable_reason(): string {
		return '';
	}

	public function deliver( array $message, array $conversation ): void {
		$snap = json_decode( (string) $conversation['routing_snapshot'], true ) ?: array();
		$chat = (string) ( $snap['destination_chat_id'] ?? '' );
		$bot  = (string) ( $snap['bot_id'] ?? '' );
		if ( '' === $chat ) {
			throw new ProviderException( ProviderException::PERMANENT, 'no_destination' );
		}
		if ( $bot !== $this->provider->bot_id() ) {
			// The bot was replaced after this conversation started; its mappings would not resolve.
			throw new ProviderException( ProviderException::PERMANENT, 'bot_changed' );
		}
		$mid      = (int) $message['id'];
		$cid      = (int) $conversation['id'];
		$reply_to = $this->thread_anchor( $cid, $chat, $bot );
		$chunks   = self::chunk( $this->compose( $message, $conversation, null === $reply_to ), (int) Settings::get( 'max_text_chunk' ) );
		$total    = count( $chunks );

		foreach ( $chunks as $i => $chunk ) {
			if ( $this->part_sent( $mid, $i ) ) {
				continue;
			}
			if ( '' === trim( $chunk ) ) {
				continue;
			}
			$text = $total > 1 && $i > 0
				? sprintf( '(%d/%d) %s', $i + 1, $total, $conversation['reference'] ) . "\n" . $chunk
				: $chunk;
			$pid  = $this->provider->send_text( $chat, $text, $reply_to );
			$this->map( $bot, $chat, $pid, $cid, $mid, $i, 'out' );
			$reply_to = $reply_to ?? $pid;
		}

		$attachments = Db::rows( 'SELECT * FROM ' . Db::t( 'attachments' ) . ' WHERE message_id = %d ORDER BY id ASC', $mid );
		foreach ( $attachments as $n => $att ) {
			$part = self::ATTACHMENT_PART_OFFSET + $n;
			if ( $this->part_sent( $mid, $part ) ) {
				continue;
			}
			$path = AttachmentStorage::path( (string) $att['storage_name'] );
			if ( ! $path ) {
				throw new ProviderException( ProviderException::PERMANENT, 'attachment_missing' );
			}
			$kind    = in_array( $att['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ? 'photo' : 'document';
			$caption = sprintf( '📎 %s · %s', $conversation['reference'], $this->author_label( $message, $conversation ) );
			$pid     = $this->provider->send_file( $chat, $kind, $path, (string) $att['original_name'], (string) $att['mime'], $caption, $reply_to );
			$this->map( $bot, $chat, $pid, $cid, $mid, $part, 'out' );
		}
	}

	public function resolve( InboundMessage $inbound ): ?array {
		if ( null === $inbound->reply_to_message_id || '' === $inbound->reply_to_message_id ) {
			return null;
		}
		$map = Db::row(
			'SELECT conversation_id FROM ' . Db::t( 'provider_mappings' ) . ' WHERE provider = %s AND bot_id = %s AND chat_id = %s AND provider_message_id = %s',
			$this->provider->id(),
			$this->provider->bot_id(),
			$inbound->chat_id,
			$inbound->reply_to_message_id
		);
		if ( ! $map ) {
			return null;
		}
		$conv = Db::row( 'SELECT * FROM ' . Db::t( 'conversations' ) . ' WHERE id = %d', (int) $map['conversation_id'] );
		if ( ! $conv ) {
			return null;
		}
		$snap = json_decode( (string) $conv['routing_snapshot'], true ) ?: array();
		// The mapping must belong to the conversation's own destination or an operator's private chat.
		if ( (string) ( $snap['destination_chat_id'] ?? '' ) !== $inbound->chat_id && ! $inbound->is_private() ) {
			return null;
		}
		return $conv;
	}

	/**
	 * Record a provider message id → conversation mapping (idempotent).
	 */
	public function map( string $bot, string $chat, string $provider_message_id, int $conversation_id, ?int $message_id, int $part, string $direction ): void {
		if ( '' === $provider_message_id ) {
			return;
		}
		Db::exec(
			'INSERT IGNORE INTO ' . Db::t( 'provider_mappings' ) . ' (provider, bot_id, chat_id, provider_message_id, conversation_id, message_id, part_index, direction, created_at)
			 VALUES (%s, %s, %s, %s, %d, ' . ( $message_id ? '%d' : 'NULL' ) . ', %d, %s, %s)',
			...array_values(
				array_filter(
					array( $this->provider->id(), $bot, $chat, $provider_message_id, $conversation_id, $message_id, $part, $direction, Db::now() ),
					static fn( $v ) => null !== $v
				)
			)
		);
	}

	private function part_sent( int $message_id, int $part ): bool {
		return (bool) Db::value(
			'SELECT 1 FROM ' . Db::t( 'provider_mappings' ) . " WHERE message_id = %d AND part_index = %d AND direction = 'out' LIMIT 1",
			$message_id,
			$part
		);
	}

	/**
	 * The first relayed message of this conversation in this chat, used as reply anchor
	 * so the whole conversation forms one visible reply chain in the group.
	 */
	private function thread_anchor( int $conversation_id, string $chat, string $bot ): ?string {
		$v = Db::value(
			'SELECT provider_message_id FROM ' . Db::t( 'provider_mappings' ) . " WHERE conversation_id = %d AND chat_id = %s AND bot_id = %s AND direction = 'out' ORDER BY id ASC LIMIT 1",
			$conversation_id,
			$chat,
			$bot
		);
		return $v ? (string) $v : null;
	}

	/**
	 * @param array<string, mixed> $message
	 * @param array<string, mixed> $conversation
	 */
	private function compose( array $message, array $conversation, bool $first ): string {
		$ref  = (string) $conversation['reference'];
		$body = (string) $message['body'];

		if ( 'operator' === $message['author_type'] ) {
			$name = (string) Db::value( 'SELECT display_name FROM ' . Db::t( 'operators' ) . ' WHERE id = %d', (int) $message['author_id'] );
			/* translators: 1: conversation reference, 2: operator name */
			return sprintf( __( '↩️ %1$s · reply by %2$s from the WordPress inbox:', 'gapban' ), $ref, $name ?: __( 'operator', 'gapban' ) ) . "\n" . $body;
		}

		$author = $this->author_label( $message, $conversation );
		if ( ! $first ) {
			return '' === $body ? '' : sprintf( '💬 %s · %s:', $ref, $author ) . "\n" . $body;
		}

		$fields  = (array) Settings::get( 'visitor_context_fields' );
		$visitor = Db::row( 'SELECT name, email, phone, custom_fields FROM ' . Db::t( 'visitors' ) . ' WHERE id = %d', (int) $conversation['visitor_id'] ) ?? array();
		/* translators: %s: conversation reference */
		$lines = array( sprintf( __( '🟢 New conversation %s', 'gapban' ), $ref ) );
		if ( in_array( 'name', $fields, true ) ) {
			$lines[] = '👤 ' . $author;
		}
		if ( in_array( 'email', $fields, true ) && ! empty( $visitor['email'] ) ) {
			$lines[] = '✉️ ' . $visitor['email'];
		}
		if ( in_array( 'phone', $fields, true ) && ! empty( $visitor['phone'] ) ) {
			$lines[] = '📞 ' . $visitor['phone'];
		}
		if ( in_array( 'department', $fields, true ) && ! empty( $conversation['team_id'] ) ) {
			$team = (string) Db::value( 'SELECT name FROM ' . Db::t( 'teams' ) . ' WHERE id = %d', (int) $conversation['team_id'] );
			if ( $team ) {
				/* translators: %s: department name */
				$lines[] = sprintf( __( '🏷 Department: %s', 'gapban' ), $team );
			}
		}
		if ( in_array( 'page', $fields, true ) && ! empty( $conversation['page_url'] ) ) {
			/* translators: %s: page URL */
			$lines[] = sprintf( __( '📄 Page: %s', 'gapban' ), $conversation['page_url'] );
		}
		if ( in_array( 'custom', $fields, true ) && ! empty( $visitor['custom_fields'] ) ) {
			foreach ( (array) json_decode( (string) $visitor['custom_fields'], true ) as $label => $value ) {
				$lines[] = '• ' . $label . ': ' . $value;
			}
		}
		if ( ! empty( $conversation['offline'] ) ) {
			$lines[] = __( '🌙 Left while the team was offline', 'gapban' );
		}
		$lines[] = '———';
		$lines[] = $body;
		$lines[] = '———';
		$lines[] = __( 'Reply to this message to answer. Commands (as a reply): /claim /close /note', 'gapban' );
		return implode( "\n", $lines );
	}

	/**
	 * @param array<string, mixed> $message
	 * @param array<string, mixed> $conversation
	 */
	private function author_label( array $message, array $conversation ): string {
		if ( 'operator' === $message['author_type'] ) {
			return (string) Db::value( 'SELECT display_name FROM ' . Db::t( 'operators' ) . ' WHERE id = %d', (int) $message['author_id'] );
		}
		$name = (string) Db::value( 'SELECT name FROM ' . Db::t( 'visitors' ) . ' WHERE id = %d', (int) $conversation['visitor_id'] );
		return '' !== $name && in_array( 'name', (array) Settings::get( 'visitor_context_fields' ), true ) ? $name : __( 'Visitor', 'gapban' );
	}

	/**
	 * Split text into chunks of at most $max characters, preferring line and word breaks.
	 *
	 * @return string[]
	 */
	public static function chunk( string $text, int $max ): array {
		$max = max( 100, $max );
		if ( mb_strlen( $text ) <= $max ) {
			return array( $text );
		}
		$chunks = array();
		while ( mb_strlen( $text ) > $max ) {
			$slice = mb_substr( $text, 0, $max );
			$cut   = max( (int) mb_strrpos( $slice, "\n" ), (int) mb_strrpos( $slice, ' ' ) );
			if ( $cut < $max * 0.5 ) {
				$cut = $max;
			}
			$chunks[] = rtrim( mb_substr( $text, 0, $cut ) );
			$text     = ltrim( mb_substr( $text, $cut ) );
		}
		if ( '' !== $text ) {
			$chunks[] = $text;
		}
		return $chunks;
	}
}
