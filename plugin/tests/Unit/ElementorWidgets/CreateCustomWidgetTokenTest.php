<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorWidgets;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorWidget\CreateCustomWidget;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * In production-safe mode the confirmation token of elementor-create-custom-widget
 * is bound to the full argument object, as for the other confirmed abilities.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorWidget\CreateCustomWidget
 */
final class CreateCustomWidgetTokenTest extends TestCase {

	private const ABILITY = 'stonewright/elementor-create-custom-widget';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'production-safe' ];
		$GLOBALS['stonewright_test_user_caps']       = [
			'edit_plugins'   => true,
			'manage_options' => true,
		];
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		$this->remove_staged_widgets();
	}

	protected function tearDown(): void {
		$this->remove_staged_widgets();
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	public function test_token_issued_for_the_full_arguments_is_accepted(): void {
		$args  = $this->create_args();
		$token = ConfirmationToken::issue( self::ABILITY, $args );

		$result = ( new CreateCustomWidget() )->execute( $args + [ 'confirmation_token' => $token ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertFileExists( SandboxFiles::stored_path( 'widget-token-card.pending.php' ) );
	}

	public function test_token_issued_for_different_arguments_is_refused(): void {
		$args  = $this->create_args();
		$other = $args;
		$other['props'][0]['label'] = 'Another label';
		$token = ConfirmationToken::issue( self::ABILITY, $other );

		$result = ( new CreateCustomWidget() )->execute( $args + [ 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_args_mismatch', $result->get_error_code() );
		self::assertFileDoesNotExist( SandboxFiles::stored_path( 'widget-token-card.pending.php' ) );
	}

	public function test_token_issued_for_only_some_arguments_is_refused(): void {
		$args   = $this->create_args();
		$subset = [
			'slug'     => $args['slug'],
			'title'    => $args['title'],
			'template' => $args['template'],
			'activate' => $args['activate'],
		];
		$token  = ConfirmationToken::issue( self::ABILITY, $subset );

		$result = ( new CreateCustomWidget() )->execute( $args + [ 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_args_mismatch', $result->get_error_code() );
	}

	public function test_a_call_without_a_token_is_refused_in_production_safe_mode(): void {
		$result = ( new CreateCustomWidget() )->execute( $this->create_args() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertFileDoesNotExist( SandboxFiles::stored_path( 'widget-token-card.pending.php' ) );
	}

	public function test_token_property_description_names_the_binding(): void {
		$description = (string) ( new CreateCustomWidget() )->input_schema()['properties']['confirmation_token']['description'];

		self::assertStringContainsString( 'every other argument', $description );
	}

	/** @return array<string, mixed> */
	private function create_args(): array {
		return [
			'slug'     => 'token-card',
			'title'    => 'Token Card',
			'props'    => [
				[
					'name'    => 'title',
					'type'    => 'text',
					'label'   => 'Title',
					'default' => '',
				],
			],
			'template' => '{{ title }}',
			'activate' => false,
		];
	}

	private function remove_staged_widgets(): void {
		foreach ( glob( SandboxFiles::stored_path( 'widget-token-*.php' ) ) ?: [] as $file ) {
			@unlink( $file );
		}
	}
}
