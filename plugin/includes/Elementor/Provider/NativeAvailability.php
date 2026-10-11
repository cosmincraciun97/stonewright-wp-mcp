<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Provider;

/**
 * Whether stonewright-elementor-native-execute is available on this site right now: the live
 * `native_elementor` report, read once per request, run through {@see NativeExecuteGate}.
 *
 * The tool lists use it to put native-execute on the essential surface and near the top of the
 * elementor-design profile only when Elementor's native abilities are available and certified.
 */
final class NativeAvailability {

	/** @var array<string,mixed>|null */
	private static ?array $report = null;

	/** @return array<string,mixed> */
	public static function report(): array {
		if ( null !== self::$report ) {
			return self::$report;
		}
		// Elementor registers its abilities on the same hook as ours. Before that hook has run, or while it runs, the registered set is
		// incomplete: say "not available yet" and do not remember it, and never be the call that starts the registry early.
		if ( function_exists( 'did_action' ) && ( 0 === did_action( 'wp_abilities_api_init' ) || ( function_exists( 'doing_action' ) && doing_action( 'wp_abilities_api_init' ) ) ) ) {
			return [ 'state' => 'no_abilities_registered', 'certified' => [] ];
		}
		try {
			self::$report = ( new ProviderRouter() )->native_elementor();
		} catch ( \Throwable $failure ) {
			unset( $failure );
			self::$report = [ 'state' => 'not_installed', 'certified' => [] ];
		}
		return self::$report;
	}

	public static function is_available(): bool {
		return true === NativeExecuteGate::check( self::report() );
	}

	/**
	 * @internal Tests only: replaces the live report, or clears the remembered one when null.
	 *
	 * @param array<string,mixed>|null $report
	 */
	public static function use_report_for_tests( ?array $report ): void {
		self::$report = $report;
	}
}
