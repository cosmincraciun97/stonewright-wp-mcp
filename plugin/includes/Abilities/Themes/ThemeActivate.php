<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Themes;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class ThemeActivate extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
 return 'stonewright/theme-activate'; }
	public function label(): string {
 return __( 'Theme: Activate', 'stonewright' ); }
	public function description(): string {
 return __( 'Activates an installed theme by stylesheet and reads the switch back. Refuses a theme that is not installed. Requires confirmation in production-safe mode.', 'stonewright' ); }
	public function category(): string {
 return 'themes'; }
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'stylesheet'=>[ 'type'=>'string' ],
				'confirmation_token'=>[ 'type'=>'string' ],
			],
			'required'=>[ 'stylesheet' ],
		];
	}
	public function output_schema(): array {
 return [ 'additionalProperties' => true, 'type'=>'object', 'properties'=>[ 'stylesheet'=>[ 'type'=>'string' ], 'active'=>[ 'type'=>'boolean' ] ], 'required'=>[ 'stylesheet', 'active' ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::switch_themes(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit($args, function ( array $args ) {
			$verify=$args;
unset($verify['confirmation_token']);
			$err=$this->confirmation_token_error($args, $verify);
if ( null!==$err ) {
return $err;
            }
			$stylesheet = (string) $args['stylesheet'];
			if ( '' === $stylesheet ) {
				return new \WP_Error( 'stonewright_theme_invalid', 'stylesheet is required.' );
			}
			if ( ! wp_get_theme( $stylesheet )->exists() ) {
				return new \WP_Error(
					'stonewright_theme_not_found',
					'That theme is not installed. List the installed themes with stonewright/theme-list.',
					[
						'status'     => 404,
						'stylesheet' => $stylesheet,
					]
				);
			}
			switch_theme( $stylesheet );
			// Read the switch back: the result says what the site now runs, not what was asked for.
			if ( get_stylesheet() !== $stylesheet ) {
				return new \WP_Error(
					'stonewright_theme_switch_failed',
					'WordPress did not switch to that theme.',
					[
						'status'            => 500,
						'stylesheet'        => $stylesheet,
						'active_stylesheet' => get_stylesheet(),
					]
				);
			}
			return [
				'stylesheet' => $stylesheet,
				'active'     => true,
			];
		});
	}
}
