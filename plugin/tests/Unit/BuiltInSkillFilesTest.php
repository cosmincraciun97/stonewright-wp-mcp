<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class BuiltInSkillFilesTest extends TestCase {

	public function test_content_model_and_woocommerce_skill_files_exist(): void {
		$root = dirname( __DIR__, 2 );

		$content_model = $root . '/../skills/content-model-integrations/SKILL.md';
		$woocommerce   = $root . '/../skills/woocommerce-catalog/SKILL.md';

		self::assertFileExists( $content_model );
		self::assertFileExists( $woocommerce );

		$content_model_body = (string) file_get_contents( $content_model );
		$woocommerce_body   = (string) file_get_contents( $woocommerce );

		self::assertStringContainsString( 'name: content-model-integrations', $content_model_body );
		self::assertStringContainsString( 'ACF', $content_model_body );
		self::assertStringContainsString( 'Pods', $content_model_body );
		self::assertStringContainsString( 'stonewright/wp-cli-discover', $content_model_body );
		self::assertStringContainsString( 'name: woocommerce-catalog', $woocommerce_body );
		self::assertStringContainsString( 'product variations', $woocommerce_body );
		self::assertStringContainsString( 'wp wc', $woocommerce_body );
	}
}
