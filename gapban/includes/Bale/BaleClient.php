<?php
/**
 * Low-level Bale Bot API HTTP client.
 *
 * Endpoint shape (https://tapi.bale.ai/bot<token>/<method>) and file download
 * shape (https://tapi.bale.ai/file/bot<token>/<file_path>) are recorded in
 * docs/bale-capabilities.md together with their verification status. Only the
 * methods listed in self::METHODS are ever called.
 *
 * @package Gapban
 */

namespace Gapban\Bale;

use Gapban\Core\Settings;
use Gapban\Provider\ProviderException;
use Gapban\Security\Redactor;

defined( 'ABSPATH' ) || exit;

final class BaleClient {

	public const DEFAULT_BASE = 'https://tapi.bale.ai';
	public const ALLOWED_HOST = 'tapi.bale.ai';

	/**
	 * Methods used by Gapban, with idempotency (safe to retry after a timeout).
	 */
	public const METHODS = array(
		'getMe'          => true,
		'getUpdates'     => true,
		'getWebhookInfo' => true,
		'setWebhook'     => true,
		'deleteWebhook'  => true,
		'getFile'        => true,
		'getChat'        => true,
		'sendMessage'    => false,
		'sendPhoto'      => false,
		'sendDocument'   => false,
	);

	private string $token;
	private string $base;

	public function __construct( ?string $token = null ) {
		$this->token = $token ?? Settings::bot_token();
		$this->base  = self::base_url();
	}

	/**
	 * The API base. Only https://tapi.bale.ai is accepted in production; a local
	 * mock (127.0.0.1/localhost over http) is accepted only when GAPBAN_DEV_MODE is true.
	 */
	public static function base_url(): string {
		if ( ! defined( 'GAPBAN_BALE_API_BASE' ) ) {
			return self::DEFAULT_BASE;
		}
		$candidate = rtrim( (string) GAPBAN_BALE_API_BASE, '/' );
		$parts     = wp_parse_url( $candidate );
		$host      = strtolower( $parts['host'] ?? '' );
		$scheme    = strtolower( $parts['scheme'] ?? '' );
		if ( 'https' === $scheme && self::ALLOWED_HOST === $host && empty( $parts['path'] ) ) {
			return $candidate;
		}
		$dev = defined( 'GAPBAN_DEV_MODE' ) && GAPBAN_DEV_MODE;
		if ( $dev && in_array( $host, array( '127.0.0.1', 'localhost' ), true ) ) {
			return $candidate;
		}
		return self::DEFAULT_BASE;
	}

	public function has_token(): bool {
		return (bool) preg_match( '/^\d{3,20}:[A-Za-z0-9_\-]{10,200}$/', $this->token );
	}

	/**
	 * The numeric bot id prefix of the token (public, used to scope mappings).
	 */
	public function token_bot_id(): string {
		return $this->has_token() ? (string) strstr( $this->token, ':', true ) : '';
	}

	/**
	 * Call a Bot API method.
	 *
	 * @param array<string, mixed>                                              $params
	 * @param array<string, array{path:string, filename:string, mime:string}> $files
	 * @return mixed decoded "result"
	 * @throws ProviderException
	 */
	public function call( string $method, array $params = array(), array $files = array(), int $timeout = 15 ) {
		if ( ! isset( self::METHODS[ $method ] ) ) {
			throw new ProviderException( ProviderException::PERMANENT, 'method_not_allowed' );
		}
		if ( ! $this->has_token() ) {
			throw new ProviderException( ProviderException::AUTH, 'token_missing' );
		}
		$idempotent = self::METHODS[ $method ];
		$url        = $this->base . '/bot' . $this->token . '/' . $method;

		$args = array(
			'timeout'             => $timeout,
			'redirection'         => 0,
			'httpversion'         => '1.1',
			'user-agent'          => 'Gapban/' . GAPBAN_VERSION . '; WordPress',
			'limit_response_size' => 2 * MB_IN_BYTES,
			'reject_unsafe_urls'  => false,
		);
		if ( $files ) {
			$boundary              = 'gapban' . bin2hex( random_bytes( 12 ) );
			$args['headers']       = array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary );
			$args['body']          = self::multipart( $boundary, $params, $files );
		} else {
			$args['headers'] = array( 'Content-Type' => 'application/json' );
			$args['body']    = wp_json_encode( (object) $params );
		}

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			$message = Redactor::text( $response->get_error_message() );
			$timeout = (bool) preg_match( '/timed out|timeout|cURL error 28/i', $message );
			$connect = (bool) preg_match( '/resolve|connect|refused|cURL error (6|7)\b/i', $message ) && ! $timeout;
			if ( $connect || $idempotent ) {
				throw new ProviderException( ProviderException::RETRYABLE, 'network: ' . $message );
			}
			throw new ProviderException( ProviderException::UNCERTAIN, 'network: ' . $message );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( ! is_array( $json ) ) {
			if ( $code >= 300 && $code < 400 ) {
				throw new ProviderException( ProviderException::PERMANENT, 'unexpected_redirect', $code );
			}
			$kind = ( $code >= 500 || 0 === $code ) && ! $idempotent ? ProviderException::UNCERTAIN : ProviderException::RETRYABLE;
			if ( 401 === $code || 404 === $code ) {
				$kind = ProviderException::AUTH;
			}
			throw new ProviderException( $kind, 'malformed_response http=' . $code, $code );
		}

		if ( ! empty( $json['ok'] ) && array_key_exists( 'result', $json ) ) {
			return $json['result'];
		}

		$error_code  = (int) ( $json['error_code'] ?? $code );
		$description = Redactor::text( (string) ( $json['description'] ?? 'unknown_error' ) );
		$retry_after = (int) ( $json['parameters']['retry_after'] ?? 0 );

		if ( 429 === $error_code ) {
			throw new ProviderException( ProviderException::RATE_LIMITED, $description, 429, max( 1, $retry_after ) );
		}
		if ( 401 === $error_code || ( 404 === $error_code && preg_match( '/not found/i', $description ) && 'getMe' === $method ) ) {
			throw new ProviderException( ProviderException::AUTH, $description, $error_code );
		}
		if ( $error_code >= 500 ) {
			throw new ProviderException( $idempotent ? ProviderException::RETRYABLE : ProviderException::UNCERTAIN, $description, $error_code );
		}
		throw new ProviderException( ProviderException::PERMANENT, $description, $error_code );
	}

	/**
	 * Download a file by its provider path into a temp file, bounded in size and time.
	 *
	 * @return array{path:string, size:int}
	 * @throws ProviderException
	 */
	public function download( string $file_path, int $max_bytes ): array {
		if ( ! preg_match( '#^[A-Za-z0-9_\-./]{1,300}$#', $file_path ) || str_contains( $file_path, '..' ) ) {
			throw new ProviderException( ProviderException::PERMANENT, 'invalid_file_path' );
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp      = wp_tempnam( 'gapban' );
		$response = wp_remote_get(
			$this->base . '/file/bot' . $this->token . '/' . ltrim( $file_path, '/' ),
			array(
				'timeout'             => 30,
				'redirection'         => 0,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => $max_bytes + 1,
				'reject_unsafe_urls'  => false,
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );
			throw new ProviderException( ProviderException::RETRYABLE, 'download: ' . Redactor::text( $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$size = (int) filesize( $tmp );
		if ( 200 !== $code ) {
			wp_delete_file( $tmp );
			throw new ProviderException( $code >= 500 ? ProviderException::RETRYABLE : ProviderException::PERMANENT, 'download_http_' . $code, $code );
		}
		if ( $size > $max_bytes || 0 === $size ) {
			wp_delete_file( $tmp );
			throw new ProviderException( ProviderException::PERMANENT, 'download_size' );
		}
		return array(
			'path' => $tmp,
			'size' => $size,
		);
	}

	/**
	 * @param array<string, mixed>                                              $params
	 * @param array<string, array{path:string, filename:string, mime:string}> $files
	 */
	private static function multipart( string $boundary, array $params, array $files ): string {
		$eol  = "\r\n";
		$body = '';
		foreach ( $params as $name => $value ) {
			if ( null === $value ) {
				continue;
			}
			$body .= '--' . $boundary . $eol;
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
			$body .= ( is_array( $value ) ? wp_json_encode( $value ) : (string) $value ) . $eol;
		}
		foreach ( $files as $name => $file ) {
			$filename = str_replace( array( '"', "\r", "\n" ), '', $file['filename'] );
			$body    .= '--' . $boundary . $eol;
			$body    .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $filename . '"' . $eol;
			$body    .= 'Content-Type: ' . $file['mime'] . $eol . $eol;
			$body    .= (string) file_get_contents( $file['path'] ) . $eol; // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $body . '--' . $boundary . '--' . $eol;
	}
}
