<?php
/**
 * @package Gapban
 */

namespace Gapban\Chat;

defined( 'ABSPATH' ) || exit;

final class ChatException extends \RuntimeException {

	public int $status;

	public function __construct( string $code, int $status = 400 ) {
		parent::__construct( $code );
		$this->status = $status;
	}

	public function to_wp_error(): \WP_Error {
		return new \WP_Error( 'gapban_' . $this->getMessage(), \Gapban\Core\Strings::error( $this->getMessage() ), array( 'status' => $this->status ) );
	}
}
