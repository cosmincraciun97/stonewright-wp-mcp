<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Context\SpecializationCatalog;

/**
 * @covers \Stonewright\WpMcp\Context\SpecializationCatalog
 */
final class SpecializationCatalogTest extends TestCase {

	public function test_catalog_covers_content_model_and_catalog_specializations(): void {
		$ids = array_column( SpecializationCatalog::all(), 'id' );

		self::assertContains( 'acf', $ids );
		self::assertContains( 'acpt', $ids );
		self::assertContains( 'meta-box', $ids );
		self::assertContains( 'ase', $ids );
		self::assertContains( 'pods', $ids );
		self::assertContains( 'woocommerce', $ids );
	}

	public function test_matching_returns_relevant_specializations_for_task(): void {
		$matches = SpecializationCatalog::match(
			'Audit ACF field groups, Pods fields, and WooCommerce product variations.',
			'wordpress'
		);
		$ids     = array_column( $matches, 'id' );

		self::assertContains( 'acf', $ids );
		self::assertContains( 'pods', $ids );
		self::assertContains( 'woocommerce', $ids );
	}

	public function test_generic_custom_field_tasks_return_content_model_guidance(): void {
		$matches = SpecializationCatalog::match(
			'Create custom fields, post types, and taxonomies for the project.',
			'fields'
		);
		$ids     = array_column( $matches, 'id' );

		self::assertContains( 'acf', $ids );
		self::assertContains( 'acpt', $ids );
		self::assertContains( 'meta-box', $ids );
		self::assertContains( 'ase', $ids );
		self::assertContains( 'pods', $ids );
		self::assertNotContains( 'woocommerce', $ids );
	}

	public function test_woocommerce_guidance_prefers_typed_tools_and_keeps_wp_wc_for_gaps(): void {
		$woocommerce = array_column( SpecializationCatalog::all(), null, 'id' )['woocommerce'];
		$surface     = (string) $woocommerce['write_surface'];

		self::assertStringContainsString( 'stonewright/wc-status', $surface );
		foreach ( [ 'wc-product', 'wc-variation', 'wc-term', 'wc-attribute', 'wc-catalog-audit' ] as $typed ) {
			self::assertStringContainsString( $typed, $surface );
		}
		self::assertStringContainsString( 'use tokenized wp wc only for a missing typed operation or a long batch', $surface );
		self::assertLessThan( strpos( $surface, 'wp wc' ), strpos( $surface, 'wc-product' ), 'Typed abilities are named before wp wc.' );
		self::assertStringNotContainsString( 'Prefer WooCommerce REST v3 or wp wc commands', $surface );
	}

	public function test_acf_guidance_names_the_typed_field_group_and_value_abilities(): void {
		$acf     = array_column( SpecializationCatalog::all(), null, 'id' )['acf'];
		$surface = (string) $acf['write_surface'];

		foreach ( [ 'acf-field-group-save', 'acf-value-update', 'acf-values-get' ] as $typed ) {
			self::assertStringContainsString( $typed, $surface );
		}
	}

	public function test_each_specialization_has_runtime_operational_guidance(): void {
		foreach ( SpecializationCatalog::all() as $specialization ) {
			self::assertIsArray( $specialization );
			self::assertNotEmpty( $specialization['official_docs'] );
			self::assertContains( 'stonewright/wp-cli-discover', $specialization['discovery_tools'] );
			self::assertContains( 'Never run PHP through WP-CLI eval, shell, package, --exec, or --require entry points; stonewright/php-execute for runtime snippets is on the full profile only.', $specialization['runtime_rules'] );
			self::assertContains( 'Verify by reading back the changed schema or values after every write pass.', $specialization['verification_steps'] );
		}
	}
}
