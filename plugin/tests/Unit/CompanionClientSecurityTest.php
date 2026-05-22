<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Stonewright\WpMcp\Abilities\QA\AccessibilityCheck;
use Stonewright\WpMcp\Abilities\QA\DiffLayout;
use Stonewright\WpMcp\Abilities\QA\DiffScreenshot;
use Stonewright\WpMcp\Abilities\QA\Lighthouse;
use Stonewright\WpMcp\Abilities\QA\Report;
use Stonewright\WpMcp\Abilities\QA\ResponsiveCheck;
use Stonewright\WpMcp\Abilities\QA\ScreenshotPage;
use Stonewright\WpMcp\Support\CompanionClient;

/**
 * Hardening checks for {@see CompanionClient}.
 *
 * These guard rails enforce Phase 1.2 of the security task:
 *  - the client signature must NOT accept a caller-supplied URL,
 *  - the wire call must go through {@see wp_safe_remote_post()} (not
 *    `wp_remote_post`),
 *  - QA ability schemas must not advertise a `companion_url` property.
 *
 * Whenever this test fails, the bearer token in
 * `stonewright_companion_token` may be leaking to an attacker-chosen origin.
 *
 * @covers \Stonewright\WpMcp\Support\CompanionClient
 */
final class CompanionClientSecurityTest extends TestCase {

	/**
	 * The `post()` method must accept exactly two parameters
	 * (path + body). A third URL parameter would be a regression.
	 */
	public function test_post_does_not_accept_url_parameter(): void {
		$method     = new ReflectionMethod( CompanionClient::class, 'post' );
		$parameters = $method->getParameters();

		$this->assertCount(
			2,
			$parameters,
			'CompanionClient::post() must accept only $path and $body — no URL override.'
		);
		$this->assertSame( 'path', $parameters[0]->getName() );
		$this->assertSame( 'body', $parameters[1]->getName() );

		foreach ( $parameters as $parameter ) {
			$this->assertNotSame(
				'url',
				$parameter->getName(),
				'A "url" parameter on CompanionClient::post() is a security regression.'
			);
		}
	}

	/**
	 * The implementation must call `wp_safe_remote_post` so WordPress's
	 * private-IP safety net kicks in. `wp_remote_post` is forbidden.
	 */
	public function test_post_uses_wp_safe_remote_post(): void {
		$reflection = new ReflectionClass( CompanionClient::class );
		$file       = $reflection->getFileName();
		$this->assertNotFalse( $file );
		$source = file_get_contents( $file );
		$this->assertNotFalse( $source );

		$this->assertStringContainsString(
			'wp_safe_remote_post(',
			$source,
			'CompanionClient must use wp_safe_remote_post.'
		);

		// Strip block comments before checking for the unsafe variant; the file's
		// docblock intentionally names wp_remote_post() to explain why it is NOT
		// used. Only actual function calls in code should fail this assertion.
		$source_no_comments = (string) preg_replace( '#/\*.*?\*/#s', '', $source );
		$source_no_comments = (string) preg_replace( '#//.*$#m', '', $source_no_comments );
		$this->assertDoesNotMatchRegularExpression(
			'/(?<!safe_)wp_remote_post\s*\(/',
			$source_no_comments,
			'wp_remote_post() (unsafe variant) must not appear in CompanionClient.'
		);
	}

	/**
	 * @return array<string, array{0: class-string}>
	 */
	public function provide_qa_abilities(): array {
		return [
			'ScreenshotPage'      => [ ScreenshotPage::class ],
			'DiffScreenshot'      => [ DiffScreenshot::class ],
			'DiffLayout'          => [ DiffLayout::class ],
			'AccessibilityCheck'  => [ AccessibilityCheck::class ],
			'ResponsiveCheck'     => [ ResponsiveCheck::class ],
			'Lighthouse'          => [ Lighthouse::class ],
			'Report'              => [ Report::class ],
		];
	}

	/**
	 * No QA ability may expose a `companion_url` property and every QA
	 * ability schema must keep `additionalProperties: false` so unknown
	 * keys are rejected by the Abilities API.
	 *
	 * @dataProvider provide_qa_abilities
	 *
	 * @param class-string $ability_class
	 */
	public function test_qa_ability_schema_drops_companion_url( string $ability_class ): void {
		$ability = new $ability_class();
		$schema  = $ability->input_schema();

		$this->assertArrayHasKey( 'additionalProperties', $schema, $ability_class );
		$this->assertFalse(
			$schema['additionalProperties'],
			$ability_class . ' input schema must set additionalProperties:false.'
		);

		$properties = $schema['properties'] ?? [];
		$this->assertIsArray( $properties );
		$this->assertArrayNotHasKey(
			'companion_url',
			$properties,
			$ability_class . ' must not advertise a companion_url input property.'
		);
	}

	/**
	 * No ability under Abilities/QA may pass a caller-supplied
	 * `companion_url` into {@see CompanionClient::post()} anywhere
	 * in its source — that path is the one that previously leaked
	 * the bearer token.
	 */
	public function test_qa_sources_no_longer_forward_companion_url(): void {
		$qa_dir = dirname( __DIR__, 2 ) . '/includes/Abilities/QA';
		$files  = glob( $qa_dir . '/*.php' );
		$this->assertNotEmpty( $files, 'No QA ability files were discovered.' );

		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file );
			$this->assertStringNotContainsString(
				"companion_url",
				$source,
				basename( $file ) . ' still references companion_url. Remove every read/forward of that key.'
			);
		}
	}
}
