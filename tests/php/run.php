<?php
/**
 * Minimal WordPress integration test runner (no PHPUnit download needed).
 *
 * Usage: WP_PATH=/path/to/wordpress WP_DB_NAME=wptest php tests/php/run.php [filter]
 *
 * Boots a real WordPress install with the plugin active against a real MySQL/MariaDB
 * database. Bale HTTP calls are answered by FakeBale (pre_http_request).
 */

$wp_path = getenv( 'WP_PATH' ) ?: '/tmp/claude-0/wp';
define( 'GAPBAN_DISABLE_INLINE_WORKER', true );
define( 'GAPBAN_TESTING', true );
$_SERVER['HTTP_HOST']   = 'localhost:8899';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
require $wp_path . '/wp-load.php';

if ( ! defined( 'GAPBAN_VERSION' ) ) {
	fwrite( STDERR, "Gapban is not active in this WordPress install.\n" );
	exit( 2 );
}
if ( 'wptest' !== DB_NAME ) {
	fwrite( STDERR, "Refusing to run: DB_NAME must be wptest (tests truncate tables).\n" );
	exit( 2 );
}

require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require __DIR__ . '/FakeBale.php';
require __DIR__ . '/TestCase.php';
foreach ( glob( __DIR__ . '/*Test.php' ) as $file ) {
	require $file;
}

$filter  = $argv[1] ?? '';
$passed  = 0;
$failed  = array();
$classes = array_filter( get_declared_classes(), static fn( $c ) => is_subclass_of( $c, \Gapban\Tests\TestCase::class ) );
sort( $classes );
foreach ( $classes as $class ) {
	foreach ( get_class_methods( $class ) as $method ) {
		if ( 0 !== strpos( $method, 'test_' ) ) {
			continue;
		}
		$name = $class . '::' . $method;
		if ( $filter && false === stripos( $name, $filter ) ) {
			continue;
		}
		$test = new $class();
		try {
			$test->set_up();
			$test->$method();
			++$passed;
			echo "\033[32m✔\033[0m {$name}\n";
		} catch ( \Throwable $e ) {
			$failed[] = $name;
			echo "\033[31m✘ {$name}\033[0m\n    " . get_class( $e ) . ': ' . $e->getMessage() . "\n    at " . $e->getFile() . ':' . $e->getLine() . "\n";
		} finally {
			$test->tear_down();
		}
	}
}
echo "\n{$passed} passed, " . count( $failed ) . " failed\n";
exit( $failed ? 1 : 0 );
