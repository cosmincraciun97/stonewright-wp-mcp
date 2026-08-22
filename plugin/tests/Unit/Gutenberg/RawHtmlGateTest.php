<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\InsertBlock;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Gutenberg\RawHtmlGate;
use Stonewright\WpMcp\Security\CustomCodeGrant;

/**
 * @covers \Stonewright\WpMcp\Gutenberg\RawHtmlGate
 * @covers \Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\InsertBlock
 */
final class RawHtmlGateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_user_caps']       = [
			'edit_posts'     => true,
			'edit_post'      => true,
			'manage_options' => true,
		];
		$GLOBALS['stonewright_test_posts']           = [
			42 => (object) [
				'ID'           => 42,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Gate target',
				'post_content' => '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->',
				'post_excerpt' => '',
				'meta'         => [],
			],
		];
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'core/html'      => (object) [
				'attributes' => [ 'content' => [ 'type' => 'string' ] ],
			],
			'core/group'     => (object) [
				'attributes' => [ 'layout' => [ 'type' => 'object' ] ],
			],
			'core/columns'   => (object) [
				'attributes' => [],
			],
			'core/column'    => (object) [
				'attributes' => [],
			],
			'core/paragraph' => (object) [
				'attributes'      => [ 'content' => [ 'type' => 'string' ] ],
				'render_callback' => static fn(): string => '',
				'is_dynamic'      => true,
			],
			'core/freeform'  => (object) [
				'attributes' => [ 'content' => [ 'type' => 'string' ] ],
			],
		];
		$GLOBALS['stonewright_test_transients'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']           = [];
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_current_user_id']   = 0;
		$GLOBALS['stonewright_test_user_caps']         = [];
		$GLOBALS['stonewright_test_user_logged_in']    = false;
		$GLOBALS['stonewright_test_transients']        = [];
		unset( $GLOBALS['stonewright_test_registered_blocks'] );
	}

	public function test_named_core_html_with_style_is_rejected_without_flag_and_grant(): void {
		$result = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'block_spec'            => [
					'name'        => 'core/html',
					'attributes'  => [ 'content' => '<style>.hero{color:red}</style>' ],
					'innerBlocks' => [],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
		$data = (array) $result->get_error_data();
		self::assertTrue( (bool) ( $data['allow_raw_html_required'] ?? false ) );
		self::assertTrue( (bool) ( $data['custom_code_grant_required'] ?? false ) );
		self::assertNotSame( '', (string) ( $data['offending_path'] ?? '' ) );
		self::assertStringContainsString( 'preset', strtolower( (string) ( $data['native_alternative'] ?? '' ) ) );
		self::assertSame( RawHtmlGate::grant_path( 42 ), (string) ( $data['path'] ?? '' ) );
	}

	public function test_named_core_html_with_style_is_not_silently_stripped(): void {
		$result = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'allow_raw_html'        => true,
				'block_spec'            => [
					'name'        => 'core/html',
					'attributes'  => [ 'content' => '<div>ok</div><style>p{margin:0}</style>' ],
					'innerBlocks' => [],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
		self::assertNull( BlockQueue::pending_for_target( 42 ) );
	}

	public function test_innerhtml_style_payload_requires_grant_even_on_named_paragraph(): void {
		$error = RawHtmlGate::assert_spec(
			[
				'name'        => 'core/paragraph',
				'attributes'  => [],
				'innerHTML'   => '<p>Hi</p><style>p{color:red}</style>',
				'innerBlocks' => [],
			],
			true,
			'',
			12
		);

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_custom_code_approval_required', $error->get_error_code() );
	}

	public function test_all_raw_html_tree_inside_group_is_refused_without_flag(): void {
		$result = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'block_spec'            => [
					'name'        => 'core/group',
					'attributes'  => [ 'layout' => [ 'type' => 'constrained' ] ],
					'innerBlocks' => [
						[
							'name'        => 'core/columns',
							'attributes'  => [],
							'innerBlocks' => [
								[
									'name'        => 'core/column',
									'attributes'  => [],
									'innerBlocks' => [
										[
											'name'        => 'core/html',
											'attributes'  => [ 'content' => '<div>left</div>' ],
											'innerBlocks' => [],
										],
									],
								],
								[
									'name'        => 'core/column',
									'attributes'  => [],
									'innerBlocks' => [
										[
											'name'        => 'core/freeform',
											'attributes'  => [ 'content' => '<p>right</p>' ],
											'innerBlocks' => [],
										],
									],
								],
							],
						],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_raw_html_refused', $result->get_error_code() );
		$leaves = (array) ( $result->get_error_data()['raw_leaves'] ?? [] );
		self::assertCount( 2, $leaves );
		self::assertSame( 'core/html', $leaves[0]['name'] ?? '' );
		self::assertSame( 'core/freeform', $leaves[1]['name'] ?? '' );
	}

	public function test_mixed_group_with_paragraph_and_html_is_allowed_without_flag(): void {
		$result = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'block_spec'            => [
					'name'        => 'core/group',
					'attributes'  => [ 'layout' => [ 'type' => 'constrained' ] ],
					'innerBlocks' => [
						[
							'name'        => 'core/paragraph',
							'attributes'  => [ 'content' => 'Hello' ],
							'innerBlocks' => [],
						],
						[
							'name'        => 'core/html',
							'attributes'  => [ 'content' => '<div>aside</div>' ],
							'innerBlocks' => [],
						],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'queued', $result['status'] );
	}

	public function test_named_core_html_with_style_succeeds_with_flag_and_consumed_grant(): void {
		$html = '<style>.ok{display:block}</style>';
		$path = RawHtmlGate::grant_path( 42 );
		$issued = CustomCodeGrant::issue(
			[
				'path'         => $path,
				'after_sha256' => hash( 'sha256', $html ),
				'language'     => 'html',
			]
		);
		self::assertIsArray( $issued );

		$result = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'allow_raw_html'        => true,
				'custom_code_grant'     => (string) $issued['token'],
				'block_spec'            => [
					'name'        => 'core/html',
					'attributes'  => [ 'content' => $html ],
					'innerBlocks' => [],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'queued', $result['status'] );
		$stored = BlockQueue::get( (string) $result['id'] );
		self::assertIsArray( $stored );
		self::assertSame( $html, $stored['block_spec']['attributes']['content'] ?? '' );

		$reuse = CustomCodeGrant::verify_and_consume(
			(string) $issued['token'],
			$path,
			hash( 'sha256', $html ),
			'html'
		);
		self::assertInstanceOf( \WP_Error::class, $reuse );
		self::assertSame( 'stonewright_custom_code_grant_reused', $reuse->get_error_code() );
	}

	public function test_insert_block_direct_path_rejects_style_html_without_grant(): void {
		$GLOBALS['stonewright_test_registered_blocks']['core/html'] = (object) [
			'attributes'      => [ 'content' => [ 'type' => 'string' ] ],
			'render_callback' => static fn(): string => '',
			'is_dynamic'      => true,
		];

		$result = ( new InsertBlock() )->execute(
			[
				'post_id' => 42,
				'block'   => [
					'name'       => 'core/html',
					'attributes' => [ 'content' => '<style>body{color:red}</style>' ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
		self::assertSame(
			'<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->',
			(string) $GLOBALS['stonewright_test_posts'][42]->post_content
		);
	}

	private function current_hash(): string {
		return hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][42]->post_content );
	}
}
