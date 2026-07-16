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
		$operating     = $root . '/../skills/agent-operating-rules/SKILL.md';
		$elementor     = $root . '/../skills/elementor-v3-builder/SKILL.md';
		$evidence      = $root . '/../skills/elementor-v3-builder/references/design-evidence.md';
		$gutenberg     = $root . '/../skills/gutenberg-fse-builder/SKILL.md';
		$readme        = $root . '/../README.md';
		$stonewright   = $root . '/../skills/stonewright/SKILL.md';
		$woocommerce   = $root . '/../skills/woocommerce-catalog/SKILL.md';

		self::assertFileExists( $content_model );
		self::assertFileExists( $operating );
		self::assertFileExists( $elementor );
		self::assertFileExists( $evidence );
		self::assertFileExists( $gutenberg );
		self::assertFileExists( $readme );
		self::assertFileExists( $stonewright );
		self::assertFileExists( $woocommerce );

		$content_model_body = (string) file_get_contents( $content_model );
		$operating_body     = (string) file_get_contents( $operating );
		$elementor_body     = (string) file_get_contents( $elementor );
		$evidence_body      = (string) file_get_contents( $evidence );
		$gutenberg_body     = (string) file_get_contents( $gutenberg );
		$readme_body        = (string) file_get_contents( $readme );
		$stonewright_body   = (string) file_get_contents( $stonewright );
		$woocommerce_body   = (string) file_get_contents( $woocommerce );

		self::assertStringContainsString( 'name: content-model-integrations', $content_model_body );
		self::assertStringContainsString( 'name: agent-operating-rules', $operating_body );
		self::assertStringContainsString( 'site Safety Memory', $operating_body );
		self::assertStringContainsString( 'additive only', $operating_body );
		self::assertStringNotContainsString( 'transavia', strtolower( $operating_body ) );
		self::assertStringContainsString( 'ACF', $content_model_body );
		self::assertStringContainsString( 'Pods', $content_model_body );
		self::assertStringContainsString( 'stonewright-wp-cli-discover', $content_model_body );
		self::assertStringContainsString( 'name: woocommerce-catalog', $woocommerce_body );
		self::assertStringContainsString( 'product variations', $woocommerce_body );
		self::assertStringContainsString( 'wp wc', $woocommerce_body );
		self::assertStringContainsString( 'stonewright/elementor-schema', $elementor_body );
		self::assertStringContainsString( 'Name only major parent containers semantically', $elementor_body );
		self::assertStringContainsString( 'position absolute', $elementor_body );
		self::assertStringContainsString( 'Content, Style, and Advanced', $elementor_body );
		self::assertStringContainsString( 'stonewright-design-native-plan', $elementor_body );
		self::assertStringContainsString( 'Do not turn vision output directly into Elementor settings', $elementor_body );
		self::assertStringContainsString( 'Every container, section, column, and widget node must have a', $elementor_body );
		self::assertStringContainsString( 'ElementorData::write()', $elementor_body );
		self::assertStringContainsString( 'customization_proposal', $evidence_body );
		self::assertStringContainsString( 'Empty destinations and `#` are invalid', $evidence_body );
		self::assertStringContainsString( 'Block Theme Production Workflow', $gutenberg_body );
		self::assertStringContainsString( 'theme.json', $gutenberg_body );
		self::assertStringContainsString( 'block supports', $gutenberg_body );
		self::assertStringContainsString( 'Create Block Theme', $gutenberg_body );
		self::assertStringContainsString( 'prototype-to-production workflow', $gutenberg_body );
		self::assertStringContainsString( 'AI agents that design and build Elementor pages safely', $readme_body );
		self::assertStringContainsString( 'Persistent project memory', $readme_body );
		self::assertStringContainsString( 'Elementor widget and schema intelligence', $readme_body );
		self::assertStringContainsString( 'Gutenberg, FSE, templates, patterns, and `theme.json`', $readme_body );
		self::assertStringContainsString( 'stonewright-tool-profile', $stonewright_body );
		self::assertStringContainsString( 'stonewright_essential_tools_mode', $stonewright_body );
		self::assertStringContainsString( 'stonewright/content-bulk-upsert-posts', $stonewright_body );
		self::assertStringContainsString( 'stonewright-wp-cli-batch-run', $stonewright_body );
	}
}
