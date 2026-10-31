<?php
/**
 * WordPress privacy integration: policy text, personal data exporter and eraser.
 *
 * @package Gapban
 */

namespace Gapban\Privacy;

use Gapban\Chat\Transcript;
use Gapban\Persistence\Db;

defined( 'ABSPATH' ) || exit;

final class PrivacyTools {

	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'policy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
	}

	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p>' . esc_html__( 'When you use the chat on this site, we store your messages, any files you send, the details you enter in the chat form (such as name, email or mobile number), the page you started the chat from, and technical data needed to keep the chat secure (a hashed network address and a random session key stored in your browser).', 'gapban' ) . '</p>'
			. '<p>' . esc_html__( 'To answer you, chat messages and files may be transmitted to our support team through the Bale messenger (bale.ai). Copies delivered to Bale are processed by Bale under its own terms and may not be deleted when we delete the conversation on this site.', 'gapban' ) . '</p>'
			. '<p>' . esc_html__( 'Conversations are kept for the period configured by the site owner and then deleted automatically. You can ask us for a copy of your conversations or for their deletion.', 'gapban' ) . '</p>';
		wp_add_privacy_policy_content( 'Gapban', wp_kses_post( $content ) );
	}

	public static function exporters( array $exporters ): array {
		$exporters['gapban'] = array(
			'exporter_friendly_name' => __( 'Gapban chat conversations', 'gapban' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	public static function erasers( array $erasers ): array {
		$erasers['gapban'] = array(
			'eraser_friendly_name' => __( 'Gapban chat conversations', 'gapban' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @return int[]
	 */
	private static function visitor_ids( string $email ): array {
		$user = get_user_by( 'email', $email );
		$rows = Db::rows(
			'SELECT id FROM ' . Db::t( 'visitors' ) . ' WHERE email = %s OR (wp_user_id IS NOT NULL AND wp_user_id = %d)',
			$email,
			$user ? (int) $user->ID : 0
		);
		return array_map( static fn( $r ) => (int) $r['id'], $rows );
	}

	public static function export( string $email, int $page = 1 ): array {
		$data = array();
		foreach ( self::visitor_ids( $email ) as $vid ) {
			$visitor = Db::row( 'SELECT * FROM ' . Db::t( 'visitors' ) . ' WHERE id = %d', $vid );
			foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'conversations' ) . ' WHERE visitor_id = %d ORDER BY id ASC', $vid ) as $conv ) {
				$data[] = array(
					'group_id'    => 'gapban-conversations',
					'group_label' => __( 'Chat conversations', 'gapban' ),
					'item_id'     => 'gapban-' . $conv['public_id'],
					'data'        => array(
						array( 'name' => __( 'Reference', 'gapban' ), 'value' => $conv['reference'] ),
						array( 'name' => __( 'Name', 'gapban' ), 'value' => (string) $visitor['name'] ),
						array( 'name' => __( 'Email', 'gapban' ), 'value' => (string) $visitor['email'] ),
						array( 'name' => __( 'Mobile', 'gapban' ), 'value' => (string) $visitor['phone'] ),
						array( 'name' => __( 'Started (UTC)', 'gapban' ), 'value' => $conv['created_at'] ),
						array( 'name' => __( 'Transcript', 'gapban' ), 'value' => Transcript::text( $conv, false ) ),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	public static function erase( string $email, int $page = 1 ): array {
		$removed = 0;
		foreach ( self::visitor_ids( $email ) as $vid ) {
			$removed += Retention::delete_visitor( $vid ) + 1;
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => $removed ? array( __( 'Chat conversations were deleted from this site. Copies already delivered to Bale are not affected.', 'gapban' ) ) : array(),
			'done'           => true,
		);
	}
}
