<?php
/**
 * A routing strategy decides how website messages reach operators in the provider
 * and how operator messages are resolved back to conversations.
 *
 * @package Gapban
 */

namespace Gapban\Routing;

use Gapban\Provider\InboundMessage;

defined( 'ABSPATH' ) || exit;

interface RoutingInterface {

	public function mode(): string;

	/**
	 * Whether this mode can be selected right now (capability-gated).
	 */
	public function available(): bool;

	public function unavailable_reason(): string;

	/**
	 * Deliver one stored message (visitor message or mirrored operator reply).
	 * Must be safe to call again after a partial failure.
	 *
	 * @param array<string, mixed> $message
	 * @param array<string, mixed> $conversation
	 * @throws \Gapban\Provider\ProviderException
	 */
	public function deliver( array $message, array $conversation ): void;

	/**
	 * Resolve which conversation an inbound operator message belongs to.
	 * Returns null when it cannot be resolved exactly — callers must never guess.
	 *
	 * @return array<string, mixed>|null conversation row
	 */
	public function resolve( InboundMessage $inbound ): ?array;
}
