<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\WidgetBuilder;

/**
 * The list of widget slugs the widget builder has registered with Elementor.
 *
 * It is the one list the Loader trusts: a widget file in the sandbox draft folder is loaded only
 * when its slug is on it. The option is read on the Elementor widget hook of a request, so it is
 * stored autoloaded (a short list of slugs, see BootOptions).
 */
final class RegisteredWidgets {

	public const OPTION_KEY = 'stonewright_registered_widgets';

	/** Same slug rule as the widget abilities. */
	private const SLUG_REGEX = '/^[a-z][a-z0-9_-]{2,40}$/D';

	/**
	 * @return list<string> The registered slugs that are valid widget slugs, in registration order.
	 */
	public static function all(): array {
		$slugs = [];
		foreach ( (array) get_option( self::OPTION_KEY, [] ) as $slug ) {
			if ( is_string( $slug ) && 1 === preg_match( self::SLUG_REGEX, $slug ) && ! in_array( $slug, $slugs, true ) ) {
				$slugs[] = $slug;
			}
		}
		return $slugs;
	}

	public static function has( string $slug ): bool {
		return in_array( $slug, self::all(), true );
	}

	/** Lists a slug once, autoloaded. */
	public static function add( string $slug ): void {
		if ( 1 !== preg_match( self::SLUG_REGEX, $slug ) || self::has( $slug ) ) {
			return;
		}
		$slugs   = self::all();
		$slugs[] = $slug;
		update_option( self::OPTION_KEY, $slugs, true );
	}
}
