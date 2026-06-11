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
		$elementor     = $root . '/../skills/elementor-v3-builder/SKILL.md';
		$gutenberg     = $root . '/../skills/gutenberg-fse-builder/SKILL.md';
		$readme        = $root . '/../README.md';
		$woocommerce   = $root . '/../skills/woocommerce-catalog/SKILL.md';

		self::assertFileExists( $content_model );
		self::assertFileExists( $elementor );
		self::assertFileExists( $gutenberg );
		self::assertFileExists( $readme );
		self::assertFileExists( $woocommerce );

		$content_model_body = (string) file_get_contents( $content_model );
		$elementor_body     = (string) file_get_contents( $elementor );
		$gutenberg_body     = (string) file_get_contents( $gutenberg );
		$readme_body        = (string) file_get_contents( $readme );
		$woocommerce_body   = (string) file_get_contents( $woocommerce );

		self::assertStringContainsString( 'name: content-model-integrations', $content_model_body );
		self::assertStringContainsString( 'ACF', $content_model_body );
		self::assertStringContainsString( 'Pods', $content_model_body );
		self::assertStringContainsString( 'stonewright/wp-cli-discover', $content_model_body );
		self::assertStringContainsString( 'name: woocommerce-catalog', $woocommerce_body );
		self::assertStringContainsString( 'product variations', $woocommerce_body );
		self::assertStringContainsString( 'wp wc', $woocommerce_body );
		self::assertStringContainsString( 'stonewright/elementor-v3-get-widget-schema', $elementor_body );
		self::assertStringContainsString( 'Name only major parent containers semantically', $elementor_body );
		self::assertStringContainsString( 'position absolute', $elementor_body );
		self::assertStringContainsString( 'Content, Style, and Advanced', $elementor_body );
		self::assertStringContainsString( 'Block Theme Production Workflow', $gutenberg_body );
		self::assertStringContainsString( 'theme.json', $gutenberg_body );
		self::assertStringContainsString( 'block supports', $gutenberg_body );
		self::assertStringContainsString( 'Create Block Theme', $gutenberg_body );
		self::assertStringContainsString( 'prototype-to-production workflow', $gutenberg_body );
		self::assertStringContainsString( 'MCP tools for WordPress builders', $readme_body );
		self::assertStringContainsString( 'Persistent memory', $readme_body );
		self::assertStringContainsString( 'Elementor widget intelligence', $readme_body );
		self::assertStringContainsString( 'Block themes and Gutenberg', $readme_body );
	}
}
