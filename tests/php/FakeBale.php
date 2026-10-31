<?php
/**
 * In-process fake of the Bale Bot API, installed through WordPress's
 * pre_http_request filter. It establishes INTERNAL behaviour only; it is not
 * evidence that the real Bale API behaves the same way.
 */

namespace Gapban\Tests;

final class FakeBale {

	public const TOKEN = '123456789:TESTtokenTESTtokenTESTtoken';
	public const BOT_ID = '123456789';

	/** @var array<int, array{method:string, params:array, files:bool}> */
	public array $calls = array();
	public int $next_message_id = 1000;
	public ?string $webhook_url = '';
	/** @var array<int, array<string, mixed>> */
	public array $pending_updates = array();
	/** @var array<string, callable> method => fn(params): mixed (array response or WP_Error) */
	public array $overrides = array();
	/** @var array<string, string> file_path => content */
	public array $files = array();
	public int $offset_seen = -1;

	public function install(): void {
		add_filter( 'pre_http_request', array( $this, 'handle' ), 10, 3 );
	}

	public function uninstall(): void {
		remove_filter( 'pre_http_request', array( $this, 'handle' ), 10 );
	}

	/**
	 * @param mixed $pre
	 * @return mixed
	 */
	public function handle( $pre, array $args, string $url ) {
		if ( ! preg_match( '#^https://tapi\.bale\.ai/(file/)?bot([^/]+)/(.+)$#', $url, $m ) ) {
			return new \WP_Error( 'http_blocked', 'test: unexpected host ' . $url );
		}
		if ( $m[1] ) {
			$content = $this->files[ $m[3] ] ?? null;
			if ( null === $content ) {
				return $this->http( 404, '' );
			}
			if ( ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $content );
			}
			return $this->http( 200, $content );
		}
		$token  = $m[2];
		$method = $m[3];
		$params = array();
		$is_multipart = isset( $args['headers']['Content-Type'] ) && str_starts_with( $args['headers']['Content-Type'], 'multipart/' );
		if ( $is_multipart ) {
			preg_match_all( '/name="([^"]+)"\r\n\r\n(.*?)\r\n--/s', (string) $args['body'], $mm, PREG_SET_ORDER );
			foreach ( $mm as $x ) {
				$params[ $x[1] ] = $x[2];
			}
		} else {
			$params = json_decode( (string) $args['body'], true ) ?: array();
		}
		$this->calls[] = array(
			'method' => $method,
			'params' => $params,
			'files'  => $is_multipart,
		);
		if ( self::TOKEN !== $token ) {
			return $this->json( 401, array( 'ok' => false, 'error_code' => 401, 'description' => 'Unauthorized' ) );
		}
		if ( isset( $this->overrides[ $method ] ) ) {
			$res = ( $this->overrides[ $method ] )( $params );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( null !== $res ) {
				return $this->json( $res['__status'] ?? 200, array_diff_key( $res, array( '__status' => 1 ) ) );
			}
		}
		switch ( $method ) {
			case 'getMe':
				return $this->ok( array( 'id' => (int) self::BOT_ID, 'is_bot' => true, 'first_name' => 'Gapban Test', 'username' => 'gapban_test_bot' ) );
			case 'sendMessage':
			case 'sendPhoto':
			case 'sendDocument':
				$id = $this->next_message_id++;
				return $this->ok( array( 'message_id' => $id, 'chat' => array( 'id' => (int) $params['chat_id'] ), 'date' => time() ) );
			case 'getWebhookInfo':
				return $this->ok( array( 'url' => (string) $this->webhook_url, 'pending_update_count' => 0 ) );
			case 'setWebhook':
				$this->webhook_url = (string) $params['url'];
				return $this->ok( true );
			case 'deleteWebhook':
				$this->webhook_url = '';
				return $this->ok( true );
			case 'getUpdates':
				$offset = (int) ( $params['offset'] ?? 0 );
				$this->offset_seen = $offset;
				if ( '' !== (string) $this->webhook_url ) {
					return $this->json( 409, array( 'ok' => false, 'error_code' => 409, 'description' => 'Conflict: webhook is active' ) );
				}
				$this->pending_updates = array_values( array_filter( $this->pending_updates, static fn( $u ) => $u['update_id'] >= $offset ) );
				return $this->ok( $this->pending_updates );
			case 'getFile':
				$path = 'documents/' . $params['file_id'];
				return isset( $this->files[ $path ] ) ? $this->ok( array( 'file_id' => $params['file_id'], 'file_path' => $path, 'file_size' => strlen( $this->files[ $path ] ) ) ) : $this->json( 400, array( 'ok' => false, 'error_code' => 400, 'description' => 'Bad Request: file not found' ) );
		}
		return $this->json( 404, array( 'ok' => false, 'error_code' => 404, 'description' => 'Not Found: method' ) );
	}

	public function sent( string $method = 'sendMessage' ): array {
		return array_values( array_filter( $this->calls, static fn( $c ) => $c['method'] === $method ) );
	}

	private function ok( $result ): array {
		return $this->json( 200, array( 'ok' => true, 'result' => $result ) );
	}

	private function json( int $status, array $body ): array {
		return $this->http( $status, wp_json_encode( $body ) );
	}

	private function http( int $status, string $body ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array( 'code' => $status, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Build an operator message update replying to $reply_to in $chat.
	 */
	public static function update( int $update_id, string $chat, string $from, string $text, ?string $reply_to = null, array $extra = array(), string $chat_type = 'group' ): array {
		$message = array_merge(
			array(
				'message_id' => 50000 + $update_id,
				'from'       => array( 'id' => (int) $from, 'is_bot' => false, 'first_name' => 'Op' . $from, 'username' => 'op' . $from ),
				'chat'       => array( 'id' => (int) $chat, 'type' => $chat_type, 'title' => 'Support' ),
				'date'       => time(),
				'text'       => $text,
			),
			$extra
		);
		if ( null !== $reply_to ) {
			$message['reply_to_message'] = array( 'message_id' => (int) $reply_to );
		}
		if ( '' === $text ) {
			unset( $message['text'] );
		}
		return array(
			'update_id' => $update_id,
			'message'   => $message,
		);
	}
}
