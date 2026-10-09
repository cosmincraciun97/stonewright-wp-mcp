<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Gutenberg\AttributeValidator;

/**
 * @covers \Stonewright\WpMcp\Gutenberg\AttributeValidator
 */
final class AttributeValidatorTest extends TestCase {

	/** @var array<string, mixed> */
	private array $schema = [
		'title' => [
			'type'     => 'string',
			'required' => true,
		],
		'tone'  => [
			'type' => 'string',
			'enum' => [ 'light', 'dark' ],
		],
		'level' => [
			'type' => 'integer',
		],
	];

	public function test_accepts_attributes_that_match_injected_schema(): void {
		$result = AttributeValidator::validate(
			'vendor/card',
			[
				'title' => 'Stone',
				'tone'  => 'dark',
				'level' => 2,
			],
			$this->schema
		);

		self::assertTrue( $result );
	}

	public function test_rejects_unknown_attributes_with_offending_keys(): void {
		$result = AttributeValidator::validate(
			'vendor/card',
			[
				'title'     => 'Stone',
				'undeclared' => true,
				'alsoBad'    => 1,
			],
			$this->schema
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_unknown_block_attributes', $result->get_error_code() );
		$data = (array) $result->get_error_data();
		self::assertSame( [ 'undeclared', 'alsoBad' ], $data['offending_keys'] );
		self::assertSame( 'vendor/card', $data['block_name'] );
		self::assertArrayHasKey( 'likely_partial', $data );
		self::assertFalse( (bool) $data['likely_partial'] );
	}

	public function test_rejects_enum_and_type_mismatches(): void {
		$enum = AttributeValidator::validate(
			'vendor/card',
			[ 'title' => 'Stone', 'tone' => 'neon' ],
			$this->schema
		);
		self::assertInstanceOf( \WP_Error::class, $enum );
		self::assertSame( 'stonewright_invalid_block_attributes', $enum->get_error_code() );

		$type = AttributeValidator::validate(
			'vendor/card',
			[ 'title' => 'Stone', 'level' => 'two' ],
			$this->schema
		);
		self::assertInstanceOf( \WP_Error::class, $type );
		self::assertSame( 'stonewright_invalid_block_attributes', $type->get_error_code() );
	}

	public function test_rejects_missing_required_attributes(): void {
		$result = AttributeValidator::validate( 'vendor/card', [ 'tone' => 'dark' ], $this->schema );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_block_attributes', $result->get_error_code() );
		self::assertContains( 'title', (array) $result->get_error_data()['offending_keys'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_registered_blocks'] );
	}

	public function test_reads_registered_block_schema_when_none_is_injected(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'qa/card' => (object) [
				'attributes' => [
					'title' => [ 'type' => 'string' ],
				],
			],
		];

		$ok = AttributeValidator::validate( 'qa/card', [ 'title' => 'ok' ] );
		self::assertTrue( $ok );

		$unknown = AttributeValidator::validate( 'qa/card', [ 'nope' => true ] );
		self::assertInstanceOf( \WP_Error::class, $unknown );
		self::assertSame( [ 'nope' ], $unknown->get_error_data()['offending_keys'] );
	}

	public function test_finalizer_context_warns_on_unknown_keys_when_schema_is_likely_partial(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'kadence/row' => (object) [
				'attributes' => [
					'uniqueID' => [ 'type' => 'string' ],
				],
			],
		];

		$result = AttributeValidator::validate(
			'kadence/row',
			[
				'uniqueID'         => 'row-1',
				'kadenceDynamic'   => [ 'enable' => true ],
			],
			null,
			'finalizer'
		);

		self::assertIsArray( $result );
		self::assertContains( 'likely_partial_schema', $this->warning_codes( $result ) );
		self::assertNotInstanceOf( \WP_Error::class, $result );
	}

	public function test_server_context_still_rejects_unknown_keys_on_partial_schemas(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'kadence/row' => (object) [
				'attributes' => [
					'uniqueID' => [ 'type' => 'string' ],
				],
			],
		];

		$result = AttributeValidator::validate(
			'kadence/row',
			[
				'uniqueID'       => 'row-1',
				'kadenceDynamic' => [ 'enable' => true ],
			],
			null,
			'server'
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_unknown_block_attributes', $result->get_error_code() );
		self::assertSame( [ 'kadenceDynamic' ], $result->get_error_data()['offending_keys'] );
		self::assertTrue( (bool) $result->get_error_data()['likely_partial'] );
	}

	public function test_unregistered_block_is_a_hard_error_in_both_contexts(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [];

		$finalizer = AttributeValidator::validate( 'missing/block', [ 'foo' => true ], null, 'finalizer' );
		$server    = AttributeValidator::validate( 'missing/block', [ 'foo' => true ], null, 'server' );

		self::assertInstanceOf( \WP_Error::class, $finalizer );
		self::assertInstanceOf( \WP_Error::class, $server );
		self::assertSame( 'stonewright_block_not_registered', $finalizer->get_error_code() );
		self::assertSame( 'stonewright_block_not_registered', $server->get_error_code() );
	}

	public function test_thin_js_bundle_blocks_are_likely_partial_and_known_namespaces_are(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'thin/widget' => (object) [
				'attributes'    => [
					'text' => [ 'type' => 'string' ],
				],
				'editor_script' => 'thin-widget-editor',
			],
			'thin-handles/widget' => (object) [
				'attributes'            => [
					'text' => [ 'type' => 'string' ],
				],
				'editor_script_handles' => [ 'thin-handles-editor' ],
			],
			'fat/widget'  => (object) [
				'attributes'    => [
					'one'   => [ 'type' => 'string' ],
					'two'   => [ 'type' => 'string' ],
					'three' => [ 'type' => 'string' ],
				],
				'editor_script' => 'fat-widget-editor',
			],
			'kadence/row' => (object) [
				'attributes' => [
					'uniqueID' => [ 'type' => 'string' ],
				],
			],
			'generateblocks/container' => (object) [
				'attributes' => [
					'uniqueId' => [ 'type' => 'string' ],
				],
			],
			'uagb/info-box' => (object) [
				'attributes' => [
					'block_id' => [ 'type' => 'string' ],
				],
			],
		];

		self::assertTrue( AttributeValidator::is_schema_likely_partial( 'thin/widget' ) );
		self::assertTrue( AttributeValidator::is_schema_likely_partial( 'thin-handles/widget' ) );
		self::assertFalse( AttributeValidator::is_schema_likely_partial( 'fat/widget' ) );
		self::assertTrue( AttributeValidator::is_schema_likely_partial( 'kadence/row' ) );
		self::assertTrue( AttributeValidator::is_schema_likely_partial( 'generateblocks/container' ) );
		self::assertTrue( AttributeValidator::is_schema_likely_partial( 'uagb/info-box' ) );
	}

	public function test_finalizer_warns_for_thin_js_bundle_unknown_keys(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'thin/widget' => (object) [
				'attributes'    => [
					'text' => [ 'type' => 'string' ],
				],
				'editor_script' => 'thin-widget-editor',
			],
		];

		$result = AttributeValidator::validate(
			'thin/widget',
			[
				'text'     => 'hi',
				'uniqueID' => 'abc',
			],
			null,
			'finalizer'
		);

		self::assertIsArray( $result );
		self::assertContains( 'likely_partial_schema', $this->warning_codes( $result ) );
	}

	public function test_finalizer_context_still_rejects_unknown_keys_on_complete_schemas(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'vendor/card' => (object) [
				'attributes'    => [
					'title' => [ 'type' => 'string' ],
					'tone'  => [ 'type' => 'string' ],
					'level' => [ 'type' => 'integer' ],
				],
				'editor_script' => 'vendor-card-editor',
			],
		];

		$result = AttributeValidator::validate(
			'vendor/card',
			[
				'title'      => 'Stone',
				'undeclared' => true,
			],
			null,
			'finalizer'
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_unknown_block_attributes', $result->get_error_code() );
	}

	/**
	 * A block type as WordPress 6.9 registers core/group: the attribute list holds what block.json declares plus what the
	 * server-side support handlers add (className, style, colours, layout, align). The attributes that only the block
	 * editor adds from `supports` (anchor, lock, metadata) are not in the list.
	 *
	 * @return array<string, mixed>
	 */
	private function group_type_as_registered_by_wordpress(): array {
		return [
			'tagName'         => [ 'type' => 'string', 'default' => 'div' ],
			'templateLock'    => [ 'type' => [ 'string', 'boolean' ], 'enum' => [ 'all', 'insert', 'contentOnly', false ] ],
			'allowedBlocks'   => [ 'type' => 'array' ],
			'align'           => [ 'type' => 'string', 'enum' => [ 'left', 'center', 'right', 'wide', 'full' ] ],
			'className'       => [ 'type' => 'string' ],
			'style'           => [ 'type' => 'object' ],
			'backgroundColor' => [ 'type' => 'string' ],
			'textColor'       => [ 'type' => 'string' ],
			'gradient'        => [ 'type' => 'string' ],
			'fontSize'        => [ 'type' => 'string' ],
			'layout'          => [ 'type' => 'object' ],
		];
	}

	private function register_group_type_as_registered_by_wordpress(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'core/group' => (object) [
				'attributes'    => $this->group_type_as_registered_by_wordpress(),
				'supports'      => [
					'anchor'     => true,
					'align'      => [ 'wide', 'full' ],
					'color'      => [ 'gradients' => true, 'link' => true ],
					'spacing'    => [ 'padding' => true, 'margin' => [ 'top', 'bottom' ] ],
					'typography' => [ 'fontSize' => true ],
					'layout'     => [ 'allowSizingOnChildren' => true ],
					'html'       => false,
				],
				'editor_script' => 'wp-block-library',
			],
			'core/code'  => (object) [
				'attributes' => [ 'content' => [ 'type' => 'string' ] ],
				'supports'   => [ 'html' => false ],
			],
			'core/spacer' => (object) [
				'attributes' => [ 'height' => [ 'type' => 'string' ] ],
				'supports'   => [ 'anchor' => true, 'customClassName' => false ],
			],
		];
	}

	public function test_accepts_the_attributes_that_the_block_supports_add_to_a_core_group(): void {
		$this->register_group_type_as_registered_by_wordpress();

		$result = AttributeValidator::validate(
			'core/group',
			[
				'anchor'   => 'features',
				'layout'   => [ 'type' => 'constrained' ],
				'className' => 'is-style-card',
				'lock'     => [ 'move' => true ],
				'metadata' => [ 'name' => 'Features' ],
				'style'    => [ 'spacing' => [ 'padding' => '1rem' ] ],
			]
		);

		self::assertTrue( $result );
		self::assertTrue( AttributeValidator::validate_tree( 'core/group', [ 'anchor' => 'features' ], [ [ 'name' => 'core/group', 'attributes' => [ 'anchor' => 'inner' ] ] ] ) );
	}

	public function test_support_attributes_do_not_open_the_schema_to_other_keys(): void {
		$this->register_group_type_as_registered_by_wordpress();

		$result = AttributeValidator::validate( 'core/group', [ 'anchor' => 'features', 'undeclared' => true, 'html' => '<b>x</b>' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_unknown_block_attributes', $result->get_error_code() );
		self::assertSame( [ 'undeclared', 'html' ], $result->get_error_data()['offending_keys'] );
	}

	public function test_a_support_attribute_is_refused_when_the_block_does_not_declare_the_support(): void {
		$this->register_group_type_as_registered_by_wordpress();

		$no_anchor = AttributeValidator::validate( 'core/code', [ 'anchor' => 'snippet' ] );
		self::assertInstanceOf( \WP_Error::class, $no_anchor );
		self::assertSame( [ 'anchor' ], $no_anchor->get_error_data()['offending_keys'] );

		$no_class = AttributeValidator::validate( 'core/spacer', [ 'className' => 'x' ] );
		self::assertInstanceOf( \WP_Error::class, $no_class );
		self::assertSame( [ 'className' ], $no_class->get_error_data()['offending_keys'] );

		$no_colour = AttributeValidator::validate( 'core/code', [ 'backgroundColor' => 'accent', 'style' => [ 'color' => [] ] ] );
		self::assertInstanceOf( \WP_Error::class, $no_colour );
		self::assertSame( [ 'backgroundColor', 'style' ], $no_colour->get_error_data()['offending_keys'] );

		// Every block takes the class name unless it turns the support off, and takes lock and metadata.
		self::assertTrue( AttributeValidator::validate( 'core/code', [ 'className' => 'x', 'lock' => [ 'remove' => true ], 'metadata' => [ 'name' => 'Code' ] ] ) );
		self::assertTrue( AttributeValidator::validate( 'core/spacer', [ 'anchor' => 'gap', 'lock' => [ 'move' => true ] ] ) );
	}

	public function test_support_attributes_keep_their_type_and_the_registered_declaration_wins(): void {
		$this->register_group_type_as_registered_by_wordpress();

		$bad_anchor = AttributeValidator::validate( 'core/group', [ 'anchor' => 12 ] );
		self::assertInstanceOf( \WP_Error::class, $bad_anchor );
		self::assertSame( 'stonewright_invalid_block_attributes', $bad_anchor->get_error_code() );
		self::assertSame( [ 'anchor' ], $bad_anchor->get_error_data()['offending_keys'] );

		$bad_lock = AttributeValidator::validate( 'core/group', [ 'lock' => 'all' ] );
		self::assertInstanceOf( \WP_Error::class, $bad_lock );
		self::assertSame( 'stonewright_invalid_block_attributes', $bad_lock->get_error_code() );

		// The enum the server registered for align still applies.
		$bad_align = AttributeValidator::validate( 'core/group', [ 'align' => 'sideways' ] );
		self::assertInstanceOf( \WP_Error::class, $bad_align );
		self::assertSame( 'stonewright_invalid_block_attributes', $bad_align->get_error_code() );
		self::assertTrue( AttributeValidator::validate( 'core/group', [ 'align' => 'full' ] ) );
	}

	public function test_the_effective_schema_of_a_registered_type_includes_the_support_attributes(): void {
		$this->register_group_type_as_registered_by_wordpress();
		$registered = \WP_Block_Type_Registry::get_instance()->get_registered( 'core/group' );

		$schema = AttributeValidator::schema_for_type( $registered );

		foreach ( [ 'anchor', 'lock', 'metadata', 'className', 'tagName', 'layout' ] as $key ) {
			self::assertArrayHasKey( $key, $schema );
		}
		self::assertSame( [ 'type' => 'string' ], $schema['anchor'] );
		self::assertSame( [ 'type' => 'string', 'default' => 'div' ], $schema['tagName'] );
		self::assertSame( $schema, AttributeValidator::schema_for( 'core/group' ) );
	}

	/**
	 * @param array<string, mixed> $result
	 * @return list<string>
	 */
	private function warning_codes( array $result ): array {
		$warnings = $result['warnings'] ?? [];
		if ( ! is_array( $warnings ) ) {
			return [];
		}
		$codes = [];
		foreach ( $warnings as $warning ) {
			if ( is_string( $warning ) ) {
				$codes[] = $warning;
				continue;
			}
			if ( is_array( $warning ) && isset( $warning['code'] ) && is_string( $warning['code'] ) ) {
				$codes[] = $warning['code'];
			}
		}
		return $codes;
	}
}
