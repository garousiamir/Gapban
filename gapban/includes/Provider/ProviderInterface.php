<?php
/**
 * Messaging provider adapter. Gapban's domain never talks to a provider directly.
 *
 * @package Gapban
 */

namespace Gapban\Provider;

defined( 'ABSPATH' ) || exit;

interface ProviderInterface {

	public function id(): string;

	/**
	 * Stable bot identifier used to scope mappings and update ids.
	 */
	public function bot_id(): string;

	public function is_configured(): bool;

	/**
	 * Send text. Returns the provider message id.
	 *
	 * @throws ProviderException
	 */
	public function send_text( string $chat_id, string $text, ?string $reply_to = null ): string;

	/**
	 * Send a file. $kind is "photo" or "document".
	 *
	 * @throws ProviderException
	 */
	public function send_file( string $chat_id, string $kind, string $path, string $filename, string $mime, string $caption = '', ?string $reply_to = null ): string;

	/**
	 * Download a provider file into a local temp path (bounded).
	 *
	 * @throws ProviderException
	 */
	public function download_file( string $file_id, int $max_bytes ): array;

	/**
	 * Feature matrix for routing decisions and UI.
	 *
	 * @return array<string, array{status:string, note:string}>
	 */
	public function capabilities(): array;
}
