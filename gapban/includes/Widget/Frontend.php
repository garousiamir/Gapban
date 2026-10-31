<?php
namespace Gapban\Widget;

defined( 'ABSPATH' ) || exit;

final class Frontend {
	public static function register(): void {}
	public static function bump_config_version(): void {
		update_option( 'gapban_config_version', time(), true );
	}
}
