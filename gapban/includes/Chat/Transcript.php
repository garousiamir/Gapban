<?php
/**
 * Plain-text transcripts (visitor download and privacy export).
 *
 * @package Gapban
 */

namespace Gapban\Chat;

use Gapban\Core\Locale;
use Gapban\Core\Settings;
use Gapban\Persistence\Db;

defined( 'ABSPATH' ) || exit;

final class Transcript {

	/**
	 * @param array<string, mixed> $conversation
	 */
	public static function text( array $conversation, bool $include_internal ): string {
		$locale = 'en' === Settings::get( 'widget_language' ) ? 'en_US' : get_locale();
		$jalali = (bool) Settings::get( 'jalali_dates' );
		$lines  = array(
			sprintf( '%s — %s', Settings::get( 'brand_name' ), $conversation['reference'] ),
			sprintf( '%s: %s (UTC: %s)', __( 'Started', 'gapban' ), Locale::display_datetime( $conversation['created_at'], $locale, $jalali ), $conversation['created_at'] ),
			str_repeat( '-', 40 ),
		);
		$last = 0;
		do {
			$batch = ConversationService::messages_after( (int) $conversation['id'], $last, $include_internal, 500 );
			foreach ( $batch as $m ) {
				$last = (int) $m['id'];
				if ( 'event' === $m['kind'] ) {
					continue;
				}
				$who = match ( $m['author_type'] ) {
					'visitor'    => __( 'You', 'gapban' ),
					'operator'   => (string) Db::value( 'SELECT display_name FROM ' . Db::t( 'operators' ) . ' WHERE id = %d', (int) $m['author_id'] ) ?: __( 'Support', 'gapban' ),
					'automation' => __( 'Automated message', 'gapban' ),
					default      => __( 'System', 'gapban' ),
				};
				if ( (int) $m['is_internal'] ) {
					$who .= ' [' . __( 'internal note', 'gapban' ) . ']';
				}
				$atts = Db::rows( 'SELECT original_name FROM ' . Db::t( 'attachments' ) . ' WHERE message_id = %d', (int) $m['id'] );
				$body = (string) $m['body'];
				foreach ( $atts as $a ) {
					$body .= ( '' === $body ? '' : "\n" ) . '[' . __( 'file', 'gapban' ) . ': ' . $a['original_name'] . ']';
				}
				$lines[] = sprintf( '[%s] %s:', Locale::display_datetime( $m['created_at'], $locale, $jalali ), $who );
				$lines[] = $body;
				$lines[] = '';
			}
		} while ( count( $batch ) === 500 );
		return implode( "\n", $lines );
	}
}
