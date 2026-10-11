<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * The abilities that run or write code, and so are never added to a surface by name.
 *
 * The `full` profile lists them, the profiles that name them (wp-cli, content-model) list them, and
 * nothing else does. Tool-profile extras refuse them, the public surface ignores a stored extra that
 * names one, and execute-ability runs one only when the session's surface lists it.
 */
final class FullProfileOnlyAbilities {

	/**
	 * @var list<string>
	 */
	private const NAMES = [
		// PHP in the loaded runtime.
		'stonewright/php-execute',
		// Sandbox code that WordPress loads.
		'stonewright/sandbox-write',
		'stonewright/sandbox-edit',
		'stonewright/sandbox-delete',
		'stonewright/sandbox-activate',
		'stonewright/sandbox-deactivate',
		'stonewright/sandbox-toggle',
		// Command runners behind the companion.
		'stonewright/wp-cli-run',
		'stonewright/wp-cli-batch-run',
		'stonewright/wp-cli-job-start',
		// Custom code providers (snippets, Customizer CSS, theme files).
		'stonewright/custom-code-provider',
		// Widgets that register PHP.
		'stonewright/elementor-widget-define',
		'stonewright/elementor-widget-register',
		'stonewright/elementor-create-custom-widget',
		'stonewright/elementor-v4-atomic-widget-define',
	];

	/**
	 * @return list<string>
	 */
	public static function names(): array {
		return self::NAMES;
	}

	public static function is_restricted( string $ability_name ): bool {
		return in_array( strtolower( trim( $ability_name ) ), self::NAMES, true );
	}
}
