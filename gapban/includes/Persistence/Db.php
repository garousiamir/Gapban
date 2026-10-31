<?php
/**
 * Thin helpers around $wpdb.
 *
 * @package Gapban
 */

namespace Gapban\Persistence;

defined( 'ABSPATH' ) || exit;

final class Db {

	public static function t( string $name ): string {
		return Schema::table( $name );
	}

	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function at( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Run $callback inside a transaction. Rolls back and rethrows on failure.
	 *
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public static function transaction( callable $callback ) {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$result = $callback();
			$wpdb->query( 'COMMIT' );
			return $result;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/**
	 * Insert a row and return its id or throw.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function insert( string $table, array $data ): int {
		global $wpdb;
		$ok = $wpdb->insert( self::t( $table ), $data );
		if ( false === $ok ) {
			throw new DbException( 'insert_failed:' . $table . ':' . $wpdb->last_error );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 */
	public static function update( string $table, array $data, array $where ): int {
		global $wpdb;
		$rows = $wpdb->update( self::t( $table ), $data, $where );
		if ( false === $rows ) {
			throw new DbException( 'update_failed:' . $table . ':' . $wpdb->last_error );
		}
		return (int) $rows;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function row( string $sql, ...$args ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- callers pass placeholders.
		$row = $wpdb->get_row( $args ? $wpdb->prepare( $sql, ...$args ) : $sql, ARRAY_A );
		return $row ?: null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows( string $sql, ...$args ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- callers pass placeholders.
		return $wpdb->get_results( $args ? $wpdb->prepare( $sql, ...$args ) : $sql, ARRAY_A ) ?: array();
	}

	/**
	 * @return mixed
	 */
	public static function value( string $sql, ...$args ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- callers pass placeholders.
		return $wpdb->get_var( $args ? $wpdb->prepare( $sql, ...$args ) : $sql );
	}

	public static function exec( string $sql, ...$args ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- callers pass placeholders.
		$r = $wpdb->query( $args ? $wpdb->prepare( $sql, ...$args ) : $sql );
		if ( false === $r ) {
			throw new DbException( 'query_failed:' . $wpdb->last_error );
		}
		return (int) $r;
	}

	/**
	 * Build "IN (%d,%d)" placeholders.
	 *
	 * @param array<int, mixed> $values
	 */
	public static function in( array $values, string $placeholder = '%d' ): string {
		return implode( ',', array_fill( 0, max( 1, count( $values ) ), $placeholder ) );
	}
}
