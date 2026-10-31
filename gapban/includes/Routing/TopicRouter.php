<?php
/**
 * Topics Mode (one provider topic per conversation).
 *
 * This router is kept so the abstraction is ready, but it is only available when
 * the provider capability matrix confirms topic creation, identifiers and per-topic
 * routing. For Bale this is currently not confirmed, so the mode cannot be
 * selected and no conversation can be created with it.
 *
 * @package Gapban
 */

namespace Gapban\Routing;

use Gapban\Bale\Capabilities;
use Gapban\Provider\InboundMessage;
use Gapban\Provider\ProviderException;

defined( 'ABSPATH' ) || exit;

final class TopicRouter implements RoutingInterface {

	public function mode(): string {
		return 'topics';
	}

	public function available(): bool {
		return Capabilities::topics_supported();
	}

	public function unavailable_reason(): string {
		return Capabilities::topics_explanation();
	}

	public function deliver( array $message, array $conversation ): void {
		throw new ProviderException( ProviderException::PERMANENT, 'topics_mode_unsupported' );
	}

	public function resolve( InboundMessage $inbound ): ?array {
		return null;
	}
}
