<?php
/**
 * Strict allowlist SVG sanitizer for the launcher icon.
 *
 * Only simple vector shapes survive; scripts, event handlers, foreignObject,
 * external references, styles and entities are rejected. Uploaded SVG
 * attachments are never accepted from visitors (see UploadValidator).
 *
 * @package Gapban
 */

namespace Gapban\Security;

defined( 'ABSPATH' ) || exit;

final class SvgSanitizer {

	private const ELEMENTS = array( 'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'title' );

	private const ATTRIBUTES = array(
		'viewbox', 'xmlns', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2',
		'width', 'height', 'points', 'fill', 'stroke', 'stroke-width', 'stroke-linecap',
		'stroke-linejoin', 'fill-rule', 'clip-rule', 'transform', 'opacity', 'fill-opacity', 'stroke-opacity',
	);

	public const MAX_BYTES = 20000;

	/**
	 * @return string|null sanitized markup, '' for empty input, null when rejected.
	 */
	public static function sanitize( string $svg ): ?string {
		$svg = trim( $svg );
		if ( '' === $svg ) {
			return '';
		}
		if ( strlen( $svg ) > self::MAX_BYTES || preg_match( '/<!DOCTYPE|<!ENTITY|<\?xml-stylesheet/i', $svg ) ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$dom  = new \DOMDocument();
		$ok   = $dom->loadXML( $svg, LIBXML_NONET | LIBXML_NOBLANKS );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok || ! $dom->documentElement || 'svg' !== strtolower( $dom->documentElement->localName ) ) {
			return null;
		}
		if ( ! self::clean( $dom->documentElement ) ) {
			return null;
		}
		$dom->documentElement->setAttribute( 'aria-hidden', 'true' );
		$dom->documentElement->setAttribute( 'focusable', 'false' );
		return $dom->saveXML( $dom->documentElement );
	}

	private static function clean( \DOMElement $el ): bool {
		if ( ! in_array( strtolower( $el->localName ), self::ELEMENTS, true ) ) {
			return false;
		}
		$remove = array();
		foreach ( $el->attributes as $attr ) {
			$name  = strtolower( $attr->nodeName );
			$value = (string) $attr->nodeValue;
			if ( ! in_array( $name, self::ATTRIBUTES, true ) ) {
				$remove[] = $attr->nodeName;
				continue;
			}
			if ( preg_match( '/url\s*\(|javascript:|data:|expression|&#|\\\\/i', $value ) ) {
				return false;
			}
		}
		foreach ( $remove as $name ) {
			$el->removeAttribute( $name );
		}
		foreach ( iterator_to_array( $el->childNodes ) as $child ) {
			if ( $child instanceof \DOMElement ) {
				if ( ! self::clean( $child ) ) {
					return false;
				}
			} elseif ( $child instanceof \DOMText ) {
				if ( 'title' !== strtolower( $el->localName ) && '' !== trim( $child->nodeValue ) ) {
					$el->removeChild( $child );
				}
			} else {
				// Comments, CDATA, processing instructions.
				$el->removeChild( $child );
			}
		}
		return true;
	}
}
