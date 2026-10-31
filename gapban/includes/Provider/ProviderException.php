<?php
/**
 * Classified provider failure.
 *
 * @package Gapban
 */

namespace Gapban\Provider;

defined( 'ABSPATH' ) || exit;

final class ProviderException extends \RuntimeException {

	/** The request certainly did not take effect; safe to retry. */
	public const RETRYABLE = 'retryable';
	/** Retrying the same request will not help (bad chat, message too long, blocked). */
	public const PERMANENT = 'permanent';
	/** The request may or may not have been applied remotely (timeout after send, malformed reply). */
	public const UNCERTAIN = 'uncertain';
	/** Throttled; honour retry_after. */
	public const RATE_LIMITED = 'rate_limited';
	/** Credentials rejected. */
	public const AUTH = 'auth';

	public string $kind;
	public int $retry_after;
	public int $provider_code;

	public function __construct( string $kind, string $message, int $provider_code = 0, int $retry_after = 0 ) {
		parent::__construct( $message );
		$this->kind          = $kind;
		$this->provider_code = $provider_code;
		$this->retry_after   = $retry_after;
	}
}
