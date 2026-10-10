<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Content\CreatePage;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddButton;
use Stonewright\WpMcp\Abilities\Media\UploadMedia;
use Stonewright\WpMcp\Abilities\Media\UploadMediaBatch;
use Stonewright\WpMcp\Abilities\Runtime\PhpExecute;
use Stonewright\WpMcp\Abilities\Search\OembedResolve;
use Stonewright\WpMcp\Abilities\Site\Ping;
use Stonewright\WpMcp\Support\AbilitySourceFacts;

/**
 * The facts recorded for every ability come from its source: whether it can change state and
 * whether it can reach hosts outside the site. Strings and comments never count.
 *
 * @covers \Stonewright\WpMcp\Support\AbilitySourceFacts
 */
final class AbilitySourceFactsTest extends TestCase {

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function write_sources(): array {
		return [
			'an option write'                       => [ '<?php update_option( "a", 1 );', true ],
			'a post insert'                         => [ '<?php wp_insert_post( $args );', true ],
			'a file write'                          => [ '<?php file_put_contents( $path, $data );', true ],
			'a snapshot call'                       => [ '<?php Backup::snapshot_post( $id );', true ],
			'a database insert'                     => [ '<?php $wpdb->insert( $t, $d );', true ],
			'a kernel write wrapper'                => [ '<?php return $this->audit_write( $args, fn() => [] );', true ],
			'a kernel wrapper without a nature'     => [ '<?php return $this->audit( $args, fn() => [] );', false ],
			'a confirmation gate'                   => [ '<?php $e = $this->require_production_safe_token( $args ); ConfirmationToken::verify_or_error( $t, $n, $a );', true ],
			'the confirmation guard trait'          => [ '<?php final class X { use ConfirmationGuard; }', true ],
			'a declared read'                       => [ '<?php return $this->audit_read( $args, fn() => [] );', false ],
			'no mutation at all'                    => [ '<?php return get_option( "a" );', false ],
			'a write named only in a string'        => [ '<?php return "update_option( a, 1 )";', false ],
			'a write named only in a comment'       => [ "<?php\n// update_option( 'a', 1 );\n/* wp_insert_post( \$a ); */\nreturn 1;", false ],
			'a wrapper named only in a doc comment' => [ "<?php\n/** Uses \$this->audit_write( ... ). */\nreturn 1;", false ],
		];
	}

	/**
	 * @dataProvider write_sources
	 */
	public function test_a_source_that_can_change_state_is_a_write( string $source, bool $expected ): void {
		self::assertSame( $expected, AbilitySourceFacts::is_write( $source ) );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function external_sources(): array {
		return [
			'a GET request'                    => [ '<?php wp_remote_get( $url );', true ],
			'a POST request'                   => [ '<?php wp_remote_post( $url, $args );', true ],
			'a safe GET request'               => [ '<?php wp_safe_remote_get( $url );', true ],
			'a download'                       => [ '<?php download_url( $url, 60 );', true ],
			'an oEmbed lookup'                 => [ '<?php wp_oembed_get( $url );', true ],
			'an image sideload'                => [ '<?php media_sideload_image( $url, $id );', true ],
			'the asset sideloader'             => [ '<?php AssetSideloader::sideload( $url );', true ],
			'the asset reference resolver'     => [ '<?php AssetReferences::resolve( $spec, true );', true ],
			'the stock image client'           => [ '<?php new StockImageClient();', true ],
			'a delegate that downloads'        => [ '<?php $worker = new UploadMedia();', true ],
			'reading a response only'          => [ '<?php wp_remote_retrieve_body( $response );', false ],
			'no network'                       => [ '<?php return get_option( "a" );', false ],
			'a request named only in a string' => [ '<?php return "wp_remote_get( $url )";', false ],
			'a request named in a comment'     => [ "<?php\n// wp_remote_get( \$url );\nreturn 1;", false ],
		];
	}

	/**
	 * @dataProvider external_sources
	 */
	public function test_a_source_that_reaches_outside_the_site_is_external( string $source, bool $expected ): void {
		self::assertSame( $expected, AbilitySourceFacts::calls_external( $source ) );
	}

	public function test_the_confirmation_gate_detection(): void {
		foreach ( [ 'use ConfirmationGuard', 'ConfirmationToken::verify_or_error', 'require_confirmation', 'require_sandbox_confirmation', 'confirmation_token_error(', 'production_safe_token_error(', 'audit_write(', 'new BuildPageFromSpec()' ] as $marker ) {
			self::assertTrue( AbilitySourceFacts::has_token_gate( $marker ), $marker );
		}
		self::assertFalse( AbilitySourceFacts::has_token_gate( 'audit_read( $args, $callback )' ) );
	}

	public function test_the_source_of_an_ability_includes_its_parent_classes(): void {
		$source = AbilitySourceFacts::source_with_parents( AddButton::class );

		self::assertStringContainsString( 'class AddButton', $source );
		self::assertStringContainsString( 'class WidgetAbilityBase', $source, 'The shared base of the generated widget abilities holds the write path.' );
		self::assertStringNotContainsString( 'abstract class AbilityKernel', $source, 'The kernel is left out: it names every wrapper.' );
	}

	public function test_facts_of_real_abilities(): void {
		self::assertSame( [ 'write' => false, 'external' => false ], AbilitySourceFacts::detect( Ping::class ) );
		self::assertSame( [ 'write' => true, 'external' => false ], AbilitySourceFacts::detect( CreatePage::class ) );
		self::assertSame( [ 'write' => true, 'external' => false ], AbilitySourceFacts::detect( PhpExecute::class ) );
		self::assertSame( [ 'write' => true, 'external' => false ], AbilitySourceFacts::detect( AddButton::class ) );
		self::assertSame( [ 'write' => true, 'external' => true ], AbilitySourceFacts::detect( UploadMedia::class ) );
		self::assertSame( [ 'write' => true, 'external' => true ], AbilitySourceFacts::detect( UploadMediaBatch::class ) );
		self::assertSame( [ 'write' => false, 'external' => true ], AbilitySourceFacts::detect( OembedResolve::class ) );
	}
}
