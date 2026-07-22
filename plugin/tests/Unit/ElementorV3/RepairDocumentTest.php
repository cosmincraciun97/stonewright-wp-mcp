<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\RepairDocument;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Support\PublicApiContractSnapshot;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\RepairDocument
 */
final class RepairDocumentTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
	}

	public function test_repair_decodes_double_encoded_root_and_persists_through_gate(): void {
		$inner   = [ [ 'id' => 'a1', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ];
		$post_id = 900;
		$this->add_post( $post_id, wp_json_encode( wp_json_encode( $inner ) ), 'Original content' );

		$result = ( new RepairDocument() )->execute( [ 'post_id' => $post_id ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['repaired'] );
		self::assertContains( 'double_encoded_root', $result['fixes_applied'] );
		self::assertNotSame( '', (string) $result['snapshot_id'] );

		$stored = stripslashes( (string) $GLOBALS['stonewright_test_posts'][ $post_id ]->meta['_elementor_data'] );
		self::assertSame( $inner, json_decode( $stored, true ) );
		self::assertSame( 'Original content', $GLOBALS['stonewright_test_posts'][ $post_id ]->post_content );
	}

	public function test_repair_reindexes_duplicate_and_missing_ids_without_touching_content(): void {
		$post_id = 901;
		$tree    = [
			[ 'id' => 'dup', 'elType' => 'container', 'settings' => [ 'x' => 1 ], 'elements' => [] ],
			[ 'id' => 'dup', 'elType' => 'container', 'settings' => [ 'y' => 2 ], 'elements' => [] ],
			[ 'elType' => 'container', 'settings' => [ 'z' => 3 ], 'elements' => [] ],
		];
		$this->add_post( $post_id, wp_json_encode( $tree ) );

		$result = ( new RepairDocument() )->execute( [ 'post_id' => $post_id ] );

		self::assertIsArray( $result );
		self::assertContains( 'duplicate_ids', $result['fixes_applied'] );
		self::assertContains( 'missing_ids', $result['fixes_applied'] );
		$stored = json_decode( stripslashes( (string) $GLOBALS['stonewright_test_posts'][ $post_id ]->meta['_elementor_data'] ), true );
		self::assertCount( 3, array_unique( array_column( $stored, 'id' ) ) );
		self::assertSame( [ 'x' => 1 ], $stored[0]['settings'] );
		self::assertSame( [ 'y' => 2 ], $stored[1]['settings'] );
		self::assertSame( [ 'z' => 3 ], $stored[2]['settings'] );
	}

	public function test_clean_document_is_an_idempotent_no_op_without_snapshot(): void {
		$post_id = 902;
		$tree    = [ [ 'id' => 'clean', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ];
		$this->add_post( $post_id, wp_json_encode( $tree ) );

		$result = ( new RepairDocument() )->execute( [ 'post_id' => $post_id ] );

		self::assertIsArray( $result );
		self::assertFalse( $result['repaired'] );
		self::assertSame( [], $result['fixes_applied'] );
		self::assertSame( '', $result['snapshot_id'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_production_safe_mode_requires_matching_confirmation_token(): void {
		$post_id = 903;
		$tree    = [
			[ 'id' => 'dup', 'elType' => 'container', 'settings' => [], 'elements' => [] ],
			[ 'id' => 'dup', 'elType' => 'container', 'settings' => [], 'elements' => [] ],
		];
		$this->add_post( $post_id, wp_json_encode( $tree ) );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$blocked = ( new RepairDocument() )->execute( [ 'post_id' => $post_id ] );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );

		$token  = ConfirmationToken::issue( 'stonewright/elementor-v3-repair-document', [ 'post_id' => $post_id ] );
		$result = ( new RepairDocument() )->execute( [ 'post_id' => $post_id, 'confirmation_token' => $token ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['repaired'] );
	}

	public function test_public_contract_detects_every_repair_gate(): void {
		$rows = PublicApiContractSnapshot::collect( 'test' )['abilities'];
		$row  = current( array_filter( $rows, static fn( array $candidate ): bool => 'stonewright/elementor-v3-repair-document' === $candidate['ability_name'] ) );

		self::assertIsArray( $row );
		self::assertSame(
			[ 'backup' => true, 'token' => true, 'validator' => true, 'audit' => true ],
			$row['gates']
		);
	}

	private function add_post( int $post_id, string|false $elementor_data, string $content = '' ): void {
		$GLOBALS['stonewright_test_posts'][ $post_id ] = (object) [
			'ID'           => $post_id,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Repair target',
			'post_content' => $content,
			'post_excerpt' => '',
			'meta'         => [
				'_elementor_data'      => (string) $elementor_data,
				'_elementor_edit_mode' => 'builder',
				'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
			],
		];
	}
}
