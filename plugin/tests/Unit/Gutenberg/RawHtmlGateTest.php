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

	/**
	 * @dataProvider customCodeProvider
	 * @param list<string> $expected
	 */
	public function test_custom_code_kinds_detect_code_hidden_in_markup( string $markup, array $expected ): void {
		self::assertSame( $expected, RawHtmlGate::custom_code_kinds( $markup ) );
	}

	/** @return array<string, array{0:string,1:list<string>}> */
	public static function customCodeProvider(): array {
		return [
			'style tag'                    => [ '<style>.a{color:red}</style>', [ 'style' ] ],
			'script tag'                   => [ '<p>x</p><script>alert(1)</script>', [ 'script' ] ],
			'uppercase script with a line break' => [ "<SCRIPT\n>alert(1)</SCRIPT>", [ 'script' ] ],
			'script closed by a slash'     => [ '<script/src=//evil.example></script>', [ 'script' ] ],
			'iframe'                       => [ '<iframe src="https://example.test/"></iframe>', [ 'iframe' ] ],
			'inline handler without quotes' => [ '<img src=x onerror=alert(1)>', [ 'event_handler' ] ],
			'inline handler with quotes'   => [ '<a href="#" onclick="go()">x</a>', [ 'event_handler' ] ],
			'uppercase handler'            => [ '<IMG SRC=x ONERROR=alert(1)>', [ 'event_handler' ] ],
			'handler after a slash'        => [ '<img src=x/ onerror=alert(1)>', [ 'event_handler' ] ],
			'handler glued to a quote'     => [ '<a title="x"onclick=y>x</a>', [ 'event_handler' ] ],
			'handler split over lines'     => [ "<img\nsrc=x\nonerror\n=\nalert(1)>", [ 'event_handler' ] ],
			'handler in a tag left open'   => [ 'Hello <img src=x onerror=alert(1)', [ 'event_handler' ] ],
			'handler after a quoted greater-than' => [ '<a title="a>b" onclick=x>x</a>', [ 'event_handler' ] ],
			'handler inside a quoted value that a raw-text element can close' => [ '<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>', [ 'event_handler' ] ],
			'script url inside a quoted value' => [ '<p title="<a href=javascript:alert(1)>">x</p>', [ 'javascript_url' ] ],
			'javascript url'               => [ '<a href="javascript:alert(1)">x</a>', [ 'javascript_url' ] ],
			'javascript url with spaces and case' => [ '<a href="  JaVaScRiPt:alert(1)">x</a>', [ 'javascript_url' ] ],
			'javascript url with an entity' => [ '<a href="java&#115;cript:alert(1)">x</a>', [ 'javascript_url' ] ],
			'javascript url with an entity missing its semicolon' => [ '<a href="&#106avascript:alert(1)">x</a>', [ 'javascript_url' ] ],
			'javascript url with a named whitespace entity' => [ '<a href="jav&Tab;ascript:alert(1)">x</a>', [ 'javascript_url' ] ],
			'javascript url in a form action' => [ '<form action=javascript:alert(1)><input></form>', [ 'javascript_url' ] ],
			'several kinds in a stable order' => [ '<a href="javascript:x" onclick=y></a><script></script><style></style>', [ 'style', 'script', 'event_handler', 'javascript_url' ] ],
		];
	}

	/** @dataProvider ordinaryMarkupProvider */
	public function test_ordinary_markup_and_prose_are_not_custom_code( string $markup ): void {
		self::assertSame( [], RawHtmlGate::custom_code_kinds( $markup ) );
	}

	/** @return array<string, array{0:string}> */
	public static function ordinaryMarkupProvider(): array {
		return [
			'paragraph with a link'        => [ "<!-- wp:paragraph {\"align\":\"center\"} -->\n<p class=\"has-text-align-center\">Hello <a href=\"https://example.test/page\">there</a></p>\n<!-- /wp:paragraph -->" ],
			'heading'                      => [ '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">T</h3><!-- /wp:heading -->' ],
			'prose that looks like a handler' => [ '<p>Set online=1 and once=2 in the config.</p>' ],
			'escaped script text'          => [ '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>' ],
			'prose that starts with the word' => [ '<p>JavaScript: The Good Parts</p>' ],
			'url that merely mentions it'  => [ '<a href="https://example.test/javascript:x">x</a>' ],
			'data attributes'              => [ '<p data-online="1" data-on-click="x">x</p>' ],
			'quoted handler-like text'     => [ '<a href="mailto:a@example.test" title="press onclick= to start">x</a>' ],
			'inline image'                 => [ '<img src="data:image/png;base64,AAAA" alt="x">' ],
			'figure'                       => [ '<figure class="wp-block-image"><img src="https://example.test/a.png" alt=""/></figure>' ],
		];
	}

	public function test_markup_the_scanner_cannot_process_is_treated_as_code(): void {
		$jit       = ini_get( 'pcre.jit' );
		$backtrack = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.jit', '0' );
		ini_set( 'pcre.backtrack_limit', '1' );
		try {
			$kinds = RawHtmlGate::custom_code_kinds( '<p class="x">' . str_repeat( 'a ', 200 ) . '</p>' );
		} finally {
			ini_set( 'pcre.jit', (string) $jit );
			ini_set( 'pcre.backtrack_limit', (string) $backtrack );
		}

		self::assertContains( 'unscannable', $kinds );
	}

	/** @dataProvider codeInPayloadProvider */
	public function test_script_frame_handler_and_script_url_payloads_need_the_same_grant_as_css( string $content ): void {
		$error = RawHtmlGate::assert_spec(
			[
				'name'        => 'core/html',
				'attributes'  => [ 'content' => $content ],
				'innerBlocks' => [],
			],
			true,
			'',
			12
		);

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_custom_code_approval_required', $error->get_error_code() );
	}

	/** @return array<string, array{0:string}> */
	public static function codeInPayloadProvider(): array {
		return [
			'script'         => [ '<script>alert(1)</script>' ],
			'iframe'         => [ '<iframe src="https://example.test/"></iframe>' ],
			'event handler'  => [ '<img src=x onerror=alert(1)>' ],
			'script url'     => [ '<a href="javascript:alert(1)">x</a>' ],
		];
	}

	public function test_code_in_any_attribute_string_of_a_nested_block_is_gated(): void {
		$spec = [
			'name'        => 'core/group',
			'attributes'  => [],
			'innerBlocks' => [
				[
					'name'        => 'core/button',
					'attributes'  => [ 'text' => 'Go <img src=x onerror=alert(1)>', 'url' => 'https://example.test/' ],
					'innerBlocks' => [],
				],
			],
		];

		$error = RawHtmlGate::assert_spec( $spec, true, '', 12 );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_custom_code_approval_required', $error->get_error_code() );
		self::assertSame( '0.innerBlocks.0.attributes.text', (string) ( $error->get_error_data()['offending_path'] ?? '' ) );
		self::assertSame( [ 'event_handler' ], RawHtmlGate::spec_custom_code_kinds( $spec ) );
		self::assertSame( [], RawHtmlGate::spec_custom_code_kinds( [ 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Plain', 'className' => 'a b' ] ] ) );
	}

	public function test_a_queued_change_records_the_custom_code_its_grant_covered(): void {
		$script = '<script>window.example=1</script>';
		$issued = CustomCodeGrant::issue(
			[
				'path'         => RawHtmlGate::grant_path( 42 ),
				'after_sha256' => hash( 'sha256', $script ),
				'language'     => 'html',
			]
		);
		self::assertIsArray( $issued );

		$approved = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'allow_raw_html'        => true,
				'custom_code_grant'     => (string) $issued['token'],
				'block_spec'            => [ 'name' => 'core/html', 'attributes' => [ 'content' => $script ], 'innerBlocks' => [] ],
			]
		);
		self::assertIsArray( $approved, is_wp_error( $approved ) ? $approved->get_error_code() : '' );
		self::assertSame( [ 'script' ], BlockQueue::get( (string) $approved['id'] )['custom_code'] );
		self::assertTrue( BlockQueue::cancel( [ (string) $approved['id'] ], false, 7 )['ok'] );

		$plain = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'block_spec'            => [ 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Hello' ], 'innerBlocks' => [] ],
			]
		);
		self::assertIsArray( $plain );
		self::assertSame( [], BlockQueue::get( (string) $plain['id'] )['custom_code'] );
	}

	private function current_hash(): string {
		return hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][42]->post_content );
	}
}
