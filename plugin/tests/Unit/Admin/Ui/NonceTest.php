<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\Nonce;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\Nonce
 */
final class NonceTest extends TestCase {

	public function test_the_field_is_a_hidden_input_with_the_nonce_of_the_action(): void {
		self::assertSame(
			'<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( 'site_a_action' ) . '">',
			Nonce::field( 'site_a_action' )
		);
	}

	public function test_the_field_can_carry_the_name_a_handler_reads(): void {
		self::assertSame(
			'<input type="hidden" name="_stonewright_nonce" value="' . wp_create_nonce( 'stonewright_sandbox' ) . '">',
			Nonce::field( 'stonewright_sandbox', '_stonewright_nonce' )
		);
	}

	public function test_the_field_has_no_id_so_a_page_with_many_forms_has_no_duplicate_ids(): void {
		$html = Nonce::field( 'site_a_action' ) . Nonce::field( 'site_a_action' );

		self::assertStringNotContainsString( ' id=', $html );
		self::assertSame( 2, substr_count( $html, 'name="_wpnonce"' ) );
	}

	public function test_the_name_is_cleaned_like_any_attribute(): void {
		self::assertStringNotContainsString( '"><script', Nonce::field( 'site_a_action', '"><script' ) );
	}
}
