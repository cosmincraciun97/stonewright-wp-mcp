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

	public function test_each_specialization_has_safe_operational_guidance(): void {
		foreach ( SpecializationCatalog::all() as $specialization ) {
			self::assertIsArray( $specialization );
			self::assertNotEmpty( $specialization['official_docs'] );
			self::assertContains( 'stonewright/wp-cli-discover', $specialization['discovery_tools'] );
			self::assertContains( 'Never use wp eval, wp eval-file, wp shell, wp package, --exec, or --require.', $specialization['safety_rules'] );
			self::assertContains( 'Verify by reading back the changed schema or values after every write pass.', $specialization['verification_steps'] );
		}
	}
}
