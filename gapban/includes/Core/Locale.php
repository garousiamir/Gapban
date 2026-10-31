<?php
/**
 * Persian/Iranian localization helpers: digits, phones, time zones, Jalali display.
 *
 * Stored and API values always stay canonical (ASCII digits, UTC datetimes).
 *
 * @package Gapban
 */

namespace Gapban\Core;

defined( 'ABSPATH' ) || exit;

final class Locale {

	private const FA_DIGITS = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
	private const AR_DIGITS = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );

	/**
	 * Convert Persian and Arabic-Indic digits to ASCII. Used before validating numeric input.
	 */
	public static function normalize_digits( string $value ): string {
		$ascii = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
		return str_replace( self::AR_DIGITS, $ascii, str_replace( self::FA_DIGITS, $ascii, $value ) );
	}

	public static function to_persian_digits( string $value ): string {
		return str_replace( array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ), self::FA_DIGITS, $value );
	}

	/**
	 * Normalize an Iranian or international phone number to E.164-like "+98..." when
	 * recognisable. This is formatting only; it does not verify ownership.
	 */
	public static function normalize_phone( string $value ): string {
		$v = self::normalize_digits( trim( $value ) );
		$v = preg_replace( '/[\s\-\(\)\.]/', '', $v );
		if ( '' === $v ) {
			return '';
		}
		if ( str_starts_with( $v, '00' ) ) {
			$v = '+' . substr( $v, 2 );
		}
		if ( preg_match( '/^09\d{9}$/', $v ) ) {
			return '+98' . substr( $v, 1 );
		}
		if ( preg_match( '/^9\d{9}$/', $v ) ) {
			return '+98' . $v;
		}
		if ( preg_match( '/^\+989\d{9}$/', $v ) || preg_match( '/^\+[1-9]\d{6,14}$/', $v ) ) {
			return $v;
		}
		if ( preg_match( '/^989\d{9}$/', $v ) ) {
			return '+' . $v;
		}
		return '';
	}

	public static function timezone(): \DateTimeZone {
		$tz = (string) Settings::get( 'timezone' );
		if ( '' !== $tz ) {
			try {
				return new \DateTimeZone( $tz );
			} catch ( \Exception $e ) {
				// Fall through to site timezone.
			}
		}
		return wp_timezone();
	}

	/**
	 * Format a UTC "Y-m-d H:i:s" value for display in the configured zone/calendar.
	 */
	public static function display_datetime( ?string $utc, string $locale = 'fa_IR', bool $jalali = true ): string {
		if ( empty( $utc ) ) {
			return '';
		}
		$dt = new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) );
		$dt = $dt->setTimezone( self::timezone() );
		if ( class_exists( \IntlDateFormatter::class ) ) {
			$intl_locale = str_starts_with( $locale, 'fa' ) ? ( $jalali ? 'fa_IR@calendar=persian' : 'fa_IR@calendar=gregorian' ) : 'en_US';
			$fmt         = new \IntlDateFormatter(
				$intl_locale,
				\IntlDateFormatter::MEDIUM,
				\IntlDateFormatter::SHORT,
				$dt->getTimezone(),
				( str_starts_with( $locale, 'fa' ) && $jalali ) ? \IntlDateFormatter::TRADITIONAL : \IntlDateFormatter::GREGORIAN
			);
			$out = $fmt->format( $dt );
			if ( false !== $out ) {
				return $out;
			}
		}
		return $dt->format( 'Y-m-d H:i' );
	}
}
