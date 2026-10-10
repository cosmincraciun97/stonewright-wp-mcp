<?php
/**
 * Turns a portable Elementor section into elements ready to insert into a document.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

use Stonewright\WpMcp\Elementor\ElementorCustomCssGate;
use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;
use Stonewright\WpMcp\Elementor\Schema\ContainerSchemaRepository;
use Stonewright\WpMcp\Elementor\Schema\PatchValidator;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\Elementor\WidgetAvailability;
use Stonewright\WpMcp\Security\RemediationHints;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * The step between a validated {@see PortableSection} and the write engines of Elementor V3 and V4. It
 *
 * - gives every element a fresh id that is unique in the target document and in the section;
 * - for V4, gives every local style a fresh id that names its new element and rewrites each class list to match,
 *   so a style is never shared between the copy and its source or between two copies;
 * - keeps global colors, fonts, classes and variables that exist on this site, and fails with the exact
 *   reference when one does not;
 * - keeps dynamic tags and reports them;
 * - gives an id attribute (`_element_id` in V3, `_cssid` in V4) that the document already uses, or that the copy
 *   uses twice, the next free `name-2`, `name-3`, and points the links of the copy at the renamed one;
 * - fails with the exact widget when the section holds a placeholder that Elementor registers for a plugin that is
 *   not active, or a widget that is not registered at all;
 * - fails with the exact missing feature when a V4 element type is not available on this site;
 * - never changes a widget type and never removes a setting it does not know; a V3 section whose settings the live
 *   schema refuses is refused naming every one of them, and it is copied without them only when the caller lists
 *   exactly those settings in `drop_settings`.
 *
 * It touches no database record and writes nothing; the caller inserts the result with its own write closure.
 */
final class ElementorSectionInserter {

	/** @var array<string, list<string>> Reference types that must resolve before a copy is inserted, by builder. */
	private const REQUIRED = [
		Builder::ELEMENTOR_V3 => [ 'global_color', 'global_font' ],
		Builder::ELEMENTOR_V4 => [ 'global_class', 'variable' ],
	];

	/** Most missing references listed in one error. */
	private const MAX_MISSING = 20;

	/** Code of the refusal for a V3 section whose settings the live schema does not accept. */
	public const REFUSED_CODE = 'stonewright_section_settings_not_reusable';

	/** Code of the refusal for a drop_settings list that differs from the settings rejected now. */
	public const MISMATCH_CODE = 'stonewright_section_drop_settings_mismatch';

	/** Code of the refusal for a drop_settings value that is not a list of element and setting pairs. */
	public const INVALID_DROP_CODE = 'stonewright_section_drop_settings_invalid';

	/** Code of the refusal for drop_settings on an insert that is not an Elementor V3 section. */
	public const UNSUPPORTED_DROP_CODE = 'stonewright_section_drop_settings_unsupported';

	/** Most rejected settings one error lists, and most a drop_settings list can approve. */
	private const MAX_VIOLATIONS = 25;

	/** Most rejected key paths the message of a refusal names. */
	private const MESSAGE_SETTINGS = 5;

	/** Most passes that take rejected settings out of one element to find the rest. */
	private const MAX_ROUNDS = 60;

	/** Most entries a drop_settings list may hold. */
	private const MAX_DROP_REQUESTS = 200;

	/**
	 * @param array<string, mixed>                $payload     A payload from {@see PortableSection::validate()}.
	 * @param array<int, array<string, mixed>>    $tree        The document the section goes into, for id uniqueness.
	 * @param list<int>                           $parent_path Where it goes; an empty path is the document root.
	 * @param array{id_generator?:callable():string,drop_settings?:mixed} $options `id_generator` replaces the random id source in tests;
	 *                                                                             `drop_settings` is the caller's approval to copy a V3 section without exactly the settings the live schema rejects.
	 * @return array{element:array<string,mixed>,id_map:array<string,string>,warnings:list<array<string,mixed>>,removed:list<array<string,string>>}|\WP_Error
	 */
	public static function instantiate( array $payload, string $builder, array $tree, array $parent_path, array $options = [] ): array|\WP_Error {
		$element = $payload['element'] ?? null;
		if ( ! is_array( $element ) ) {
			return new \WP_Error( 'stonewright_section_invalid', 'The section has no element.', [ 'status' => 400 ] );
		}
		$drop = self::drop_requests( $options['drop_settings'] ?? null );
		if ( $drop instanceof \WP_Error ) {
			return $drop;
		}
		$root_type = (string) ( $element['elType'] ?? '' );
		if ( Builder::ELEMENTOR_V3 === $builder && ! in_array( $root_type, [ 'container', 'section' ], true ) ) {
			return new \WP_Error( 'stonewright_section_parent_invalid', 'A V3 section must start with a container or a section element.', [ 'status' => 400, 'element_type' => $root_type ] );
		}
		if ( Builder::ELEMENTOR_V3 === $builder && 'section' === $root_type && [] !== $parent_path ) {
			return new \WP_Error( 'stonewright_section_parent_invalid', 'A legacy section can only sit at the top level of a document.', [ 'status' => 400 ] );
		}

		$inspection = SectionInspector::inspect( $builder, $element );
		$unavailable = self::unavailable_types( $builder, $element );
		if ( null !== $unavailable ) {
			return new \WP_Error(
				'stonewright_atomic_type_unavailable',
				'The section uses an Atomic type this site does not have: ' . $unavailable . '.',
				[ 'status' => 409, 'missing_types' => [ $unavailable ], 'missing_feature' => 'atomic_type:' . $unavailable ]
			);
		}
		if ( Builder::ELEMENTOR_V3 === $builder ) {
			$widget_error = self::unavailable_widget( $element );
			if ( null !== $widget_error ) {
				return $widget_error;
			}
		}
		$missing = self::missing_references( $builder, $inspection['references'] );
		if ( [] !== $missing ) {
			return new \WP_Error(
				'stonewright_section_reference_missing',
				sprintf( 'The section refers to %1$s "%2$s", which does not exist on this site.', str_replace( '_', ' ', $missing[0]['type'] ), $missing[0]['id'] ),
				[ 'status' => 409, 'missing' => $missing, 'reference' => $missing[0], 'repair' => 'Create the missing reference, or choose another section. Nothing was written.' ]
			);
		}

		$used      = array_fill_keys( array_keys( ElementorData::flatten( $tree ) ), true );
		$generator = $options['id_generator'] ?? static fn(): string => ElementorData::generate_id();
		$id_map    = [];
		$copy      = self::copy( $element, $builder, $used, $generator, $id_map );
		$copy['isInner'] = [] !== $parent_path;
		$renamed         = [];
		$copy            = self::rename_dom_ids( $copy, self::dom_ids( $tree ), $renamed );
		$removed         = [];
		if ( Builder::ELEMENTOR_V3 === $builder ) {
			$settled = self::settle_settings( $copy, array_flip( $id_map ), $drop );
			if ( $settled instanceof \WP_Error ) {
				return $settled;
			}
			$copy    = $settled['element'];
			$removed = $settled['removed'];
			if ( [] !== $removed ) {
				$inspection = SectionInspector::inspect( $builder, $copy );
			}
		}

		$warnings = [];
		if ( [] !== $renamed ) {
			$warnings[] = [ 'code' => 'anchors_renamed', 'count' => count( $renamed ), 'items' => array_slice( array_map( static fn( array $pair ): string => $pair[0] . ' -> ' . $pair[1], $renamed ), 0, 5 ) ];
		}
		if ( [] !== $removed ) {
			$warnings[] = [ 'code' => 'settings_removed', 'count' => count( $removed ), 'items' => array_slice( array_map( static fn( array $row ): string => $row['element'] . ' ' . $row['setting'], $removed ), 0, 5 ) ];
		}
		$tags     = array_values( array_map( static fn( array $reference ): string => (string) $reference['id'], array_filter( $inspection['references'], static fn( array $reference ): bool => 'dynamic_tag' === $reference['type'] ) ) );
		if ( [] !== $tags ) {
			$warnings[] = [ 'code' => 'dynamic_tags_kept', 'count' => count( $tags ), 'items' => array_slice( $tags, 0, 5 ) ];
		}

		return [ 'element' => $copy, 'id_map' => $id_map, 'warnings' => $warnings, 'removed' => $removed ];
	}

	/**
	 * The refusal for an insert operation that carries `drop_settings` where the option does not exist: only an
	 * Elementor V3 section can be copied without the settings the live schema rejects.
	 *
	 * @param array<string, mixed> $operation
	 */
	public static function unsupported_drop_settings( array $operation, string $builder_label ): ?\WP_Error {
		if ( null === ( $operation['drop_settings'] ?? null ) ) {
			return null;
		}

		return new \WP_Error(
			self::UNSUPPORTED_DROP_CODE,
			sprintf( 'drop_settings applies only to Elementor V3 sections; this is %s. Remove drop_settings from the operation. Nothing was written.', $builder_label ),
			[ 'status' => 400, 'repair' => RemediationHints::for_code( self::UNSUPPORTED_DROP_CODE ) ]
		);
	}

	/**
	 * The `drop_settings` option of an insert operation as a clean list, or the error for a list that is not one.
	 *
	 * @param mixed $raw What the caller sent: a list of `{element, setting}` objects, the placeholder of the
	 *                   element and the setting key (or `__globals__.key` for one binding), as in the
	 *                   `drop_settings_proposal` of a refusal. Other keys of an entry are ignored.
	 * @return list<array{element:string,setting:string}>|\WP_Error An empty list when nothing was sent.
	 */
	public static function drop_requests( mixed $raw ): array|\WP_Error {
		if ( null === $raw || [] === $raw ) {
			return [];
		}
		$invalid = static fn(): \WP_Error => new \WP_Error(
			self::INVALID_DROP_CODE,
			'drop_settings must be a list of objects with the placeholder of the element and the setting key, copied from the drop_settings_proposal of the refusal. Nothing was written.',
			[ 'status' => 400, 'repair' => RemediationHints::for_code( self::INVALID_DROP_CODE ) ]
		);
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( $raw ) > self::MAX_DROP_REQUESTS ) {
			return $invalid();
		}
		$list = [];
		foreach ( $raw as $entry ) {
			$element = is_array( $entry ) && is_string( $entry['element'] ?? null ) ? trim( $entry['element'] ) : '';
			$setting = is_array( $entry ) && is_string( $entry['setting'] ?? null ) ? trim( $entry['setting'] ) : '';
			if ( '' === $element || '' === $setting || strlen( $element ) > 64 || strlen( $setting ) > 190 ) {
				return $invalid();
			}
			$list[ $element . "\n" . $setting ] = [ 'element' => $element, 'setting' => $setting ];
		}

		return array_values( $list );
	}

	/**
	 * Decides what happens to the settings the live schema refuses on the new elements.
	 *
	 * A write validates the settings of every new element against the live schema and refuses a setting it
	 * does not know; it never drops one. A copy therefore keeps every setting of its source, or is refused
	 * here, in the dry run, naming every rejected setting, instead of failing when the page is saved.
	 *
	 * The one exception is the caller's explicit approval: `drop_settings` lists the rejected settings, and the
	 * copy is made without exactly those. The list must match what is rejected now, no more and no less; a
	 * list that differs refuses the copy and removes nothing.
	 *
	 * @param array<string, mixed>                           $copy         The new element tree, under its fresh ids.
	 * @param array<string, string>                          $placeholders New id to placeholder.
	 * @param list<array{element:string,setting:string}>     $drop         Settings the caller approved removing.
	 * @return array{element:array<string,mixed>,removed:list<array<string,string>>}|\WP_Error
	 */
	private static function settle_settings( array $copy, array $placeholders, array $drop ): array|\WP_Error {
		$rows = self::rejections( $copy, $placeholders );
		if ( [] === $rows ) {
			return [] === $drop ? [ 'element' => $copy, 'removed' => [] ] : self::drop_mismatch( [], [], $drop, [], true );
		}

		$required  = [];
		$removable = true;
		foreach ( $rows as $row ) {
			$removable = $removable && $row['removable'];
			foreach ( $row['units'] as $unit => $detail ) {
				$required[ $row['element'] . "\n" . $unit ] = [ 'element' => $row['element'], 'element_type' => $row['element_type'], 'setting' => (string) $unit ] + $detail;
			}
		}
		$available = $removable && count( $required ) <= self::MAX_VIOLATIONS;
		if ( [] === $drop || ! $available ) {
			return self::not_reusable( $rows, $required, $available, $removable ? 'too_many' : 'not_removable' );
		}

		$given   = [];
		foreach ( $drop as $entry ) {
			$given[ $entry['element'] . "\n" . $entry['setting'] ] = $entry;
		}
		$missing    = array_values( array_diff_key( $required, $given ) );
		$unexpected = array_values( array_diff_key( $given, $required ) );
		if ( [] !== $missing || [] !== $unexpected ) {
			return self::drop_mismatch( $rows, $required, $drop, [ 'missing' => $missing, 'unexpected' => $unexpected ], true );
		}

		$units = [];
		foreach ( $rows as $row ) {
			$units[ $row['id'] ] = array_keys( $row['units'] );
		}
		$stripped = self::without_settings( $copy, $units );
		// What the write would validate is what was checked: a copy that still has a rejected setting is refused.
		if ( [] !== self::rejections( $stripped, $placeholders ) ) {
			return self::not_reusable( $rows, $required, false, 'not_removable' );
		}

		return [
			'element' => $stripped,
			'removed' => array_map(
				static fn( array $row ): array => array_intersect_key( $row, array_flip( [ 'element', 'element_type', 'setting', 'code', 'control_type' ] ) ),
				array_values( $required )
			),
		];
	}

	/**
	 * Every element of the tree whose settings the write would refuse, with the settings to remove for the
	 * element to pass. A setting is checked the way the write checks it: the live schema, and the gate for CSS
	 * classes and custom CSS. One validation reports a single kind of problem, so a rejected setting is taken
	 * out of a working copy and the rest checked again until nothing is left to reject.
	 *
	 * @param array<string, mixed>  $element
	 * @param array<string, string> $placeholders New id to placeholder.
	 * @return list<array{id:string,element:string,element_type:string,code:string,removable:bool,violations:list<array<string,string>>,units:array<string,array<string,string>>}>
	 */
	private static function rejections( array $element, array $placeholders ): array {
		$type     = (string) ( $element['elType'] ?? '' );
		$widget   = 'widget' === $type ? (string) ( $element['widgetType'] ?? '' ) : '';
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		$checked  = ! ( 'widget' === $type && ( str_starts_with( $widget, 'e-' ) || 'html' === $widget ) ) && in_array( $type, [ 'widget', 'container', 'section', 'column' ], true ) && [] !== $settings;
		$rows     = [];
		if ( $checked ) {
			$found = self::settings_rejection( $type, $widget, $settings );
			if ( null !== $found ) {
				$name  = $placeholders[ (string) $element['id'] ] ?? (string) $element['id'];
				$kind  = '' !== $widget ? $widget : $type;
				$rows[] = [
					'id'           => (string) $element['id'],
					'element'      => $name,
					'element_type' => $kind,
					'code'         => $found['code'],
					'removable'    => $found['removable'],
					'violations'   => array_map( static fn( array $violation ): array => array_merge( [ 'element' => $name, 'element_type' => $kind ], $violation ), $found['violations'] ),
					'units'        => array_map( static fn( array $violation ): array => array_diff_key( $violation, [ 'path' => 1 ] ), $found['units'] ),
				];
			}
		}
		foreach ( is_array( $element['elements'] ?? null ) ? $element['elements'] : [] as $child ) {
			if ( is_array( $child ) ) {
				$rows = array_merge( $rows, self::rejections( $child, $placeholders ) );
			}
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array{code:string,removable:bool,violations:list<array<string,string>>,units:array<string,array<string,string>>}|null
	 */
	private static function settings_rejection( string $type, string $widget, array $settings ): ?array {
		$work       = $settings;
		$violations = [];
		$units      = [];
		$code       = '';
		$clean      = false;
		for ( $round = 0; $round < self::MAX_ROUNDS; ++$round ) {
			$gate    = ElementorCustomCssGate::refused_settings( $work );
			$problem = self::settings_problem( $type, $widget, array_diff_key( $work, $gate ) );
			$found   = [];
			foreach ( $gate as $key => $reason ) {
				$found[] = [ 'path' => 'settings.' . $key, 'code' => $reason ];
			}
			if ( null !== $problem ) {
				$found = array_merge( $found, self::problem_violations( $problem ) );
			}
			if ( [] === $found ) {
				$clean = true;
				break;
			}
			$code = '' !== $code ? $code : ( null !== $problem ? (string) $problem->get_error_code() : ( 'css_classes_not_approved' === $found[0]['code'] ? ElementorCustomCssGate::CLASS_ERROR_CODE : ElementorCustomCssGate::ERROR_CODE ) );
			$round_units = [];
			foreach ( $found as $violation ) {
				$described = self::described( $violation, $type, $widget );
				$violations[] = $described;
				$unit         = self::removal_unit( $violation['path'] );
				if ( null !== $unit && self::has_unit( $work, $unit ) && ! isset( $units[ $unit ] ) && ! isset( $round_units[ $unit ] ) ) {
					$round_units[ $unit ] = $described;
				}
			}
			if ( [] === $round_units ) {
				break;
			}
			foreach ( $round_units as $unit => $described ) {
				$work          = self::without_unit( $work, (string) $unit );
				$units[ $unit ] = $described;
			}
		}

		if ( [] === $violations ) {
			return null;
		}

		return [ 'code' => $code, 'removable' => $clean, 'violations' => $violations, 'units' => $units ];
	}

	/**
	 * What validating the settings the way the write does reports, or null when it accepts them as they are.
	 *
	 * @param array<string, mixed> $settings
	 */
	private static function settings_problem( string $type, string $widget, array $settings ): ?\WP_Error {
		if ( [] === $settings ) {
			return null;
		}
		$validated = 'widget' === $type
			? PatchValidator::widget( $widget, [], $settings, 'merge' )
			: PatchValidator::container( [], $settings, $type, 'merge' );
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}

		return $validated['settings'] !== $settings
			? new \WP_Error( 'stonewright_elementor_settings_invalid', 'The settings would change when written.', [ 'violations' => [ [ 'path' => 'settings', 'code' => 'delta_result_mismatch' ] ] ] )
			: null;
	}

	/**
	 * @return list<array{path:string,code:string}>
	 */
	private static function problem_violations( \WP_Error $problem ): array {
		$data = is_array( $problem->get_error_data() ) ? $problem->get_error_data() : [];
		$list = [];
		foreach ( is_array( $data['violations'] ?? null ) ? $data['violations'] : [] as $violation ) {
			if ( is_array( $violation ) && is_string( $violation['path'] ?? null ) && '' !== $violation['path'] ) {
				$list[] = [ 'path' => $violation['path'], 'code' => (string) ( $violation['code'] ?? '' ) ];
			}
		}

		return [] !== $list ? $list : [ [ 'path' => 'settings', 'code' => sanitize_key( (string) $problem->get_error_code() ) ] ];
	}

	/**
	 * A violation as the error lists it: the path and code, and the type of the live control when the key is one.
	 *
	 * @param array{path:string,code:string} $violation
	 * @return array<string, string>
	 */
	private static function described( array $violation, string $type, string $widget ): array {
		$described = [ 'path' => $violation['path'], 'code' => $violation['code'] ];
		$control   = self::control_type( $type, $widget, $violation['path'] );
		if ( '' !== $control ) {
			$described['control_type'] = $control;
		}

		return $described;
	}

	private static function control_type( string $type, string $widget, string $path ): string {
		$segments = explode( '.', preg_replace( '/^settings\.?/', '', $path ) ?? '' );
		$key      = (string) ( $segments[0] ?? '' );
		if ( in_array( $key, [ '__globals__', '__dynamic__' ], true ) ) {
			$key = (string) ( $segments[1] ?? '' );
		}
		if ( '' === $key ) {
			return '';
		}
		$schema   = 'widget' === $type ? WidgetSchemaRepository::get( $widget ) : ContainerSchemaRepository::get( $type );
		$controls = is_array( $schema ) && is_array( $schema['controls'] ?? null ) ? $schema['controls'] : [];
		foreach ( [ $key, ...array_map( static fn( string $suffix ): string => str_ends_with( $key, $suffix ) ? substr( $key, 0, -strlen( $suffix ) ) : '', [ '_widescreen', '_laptop', '_tablet_extra', '_tablet', '_mobile_extra', '_mobile' ] ) ] as $candidate ) {
			if ( '' !== $candidate && is_array( $controls[ $candidate ] ?? null ) && is_string( $controls[ $candidate ]['type'] ?? null ) ) {
				return $controls[ $candidate ]['type'];
			}
		}

		return '';
	}

	/**
	 * What removing a violation takes out: the top-level setting, or one binding of `__globals__` or `__dynamic__`.
	 * Null for a violation of the settings as a whole.
	 */
	private static function removal_unit( string $path ): ?string {
		$relative = preg_replace( '/^settings\.?/', '', $path ) ?? '';
		if ( '' === $relative ) {
			return null;
		}
		$segments = explode( '.', $relative );
		if ( in_array( $segments[0], [ '__globals__', '__dynamic__' ], true ) && isset( $segments[1] ) && '' !== $segments[1] ) {
			return $segments[0] . '.' . $segments[1];
		}

		return $segments[0];
	}

	/** @param array<string, mixed> $settings */
	private static function has_unit( array $settings, string $unit ): bool {
		$segments = explode( '.', $unit, 2 );

		return isset( $segments[1] )
			? is_array( $settings[ $segments[0] ] ?? null ) && array_key_exists( $segments[1], $settings[ $segments[0] ] )
			: array_key_exists( $segments[0], $settings );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private static function without_unit( array $settings, string $unit ): array {
		$segments = explode( '.', $unit, 2 );
		if ( ! isset( $segments[1] ) ) {
			unset( $settings[ $segments[0] ] );
			return $settings;
		}
		if ( is_array( $settings[ $segments[0] ] ?? null ) ) {
			unset( $settings[ $segments[0] ][ $segments[1] ] );
			if ( [] === $settings[ $segments[0] ] ) {
				unset( $settings[ $segments[0] ] );
			}
		}

		return $settings;
	}

	/**
	 * The tree with the listed settings taken out of the listed elements.
	 *
	 * @param array<string, mixed>          $element
	 * @param array<string, list<string>>   $units   Element id to the units to remove.
	 * @return array<string, mixed>
	 */
	private static function without_settings( array $element, array $units ): array {
		$id = (string) ( $element['id'] ?? '' );
		if ( isset( $units[ $id ] ) && is_array( $element['settings'] ?? null ) ) {
			foreach ( $units[ $id ] as $unit ) {
				$element['settings'] = self::without_unit( $element['settings'], $unit );
			}
		}
		if ( is_array( $element['elements'] ?? null ) ) {
			$element['elements'] = array_map( static fn( mixed $child ): mixed => is_array( $child ) ? self::without_settings( $child, $units ) : $child, $element['elements'] );
		}

		return $element;
	}

	/**
	 * The refusal for a section whose settings the live schema does not accept.
	 *
	 * @param list<array<string, mixed>>           $rows
	 * @param array<string, array<string, string>> $required  Every setting that would have to go, by element and setting.
	 * @param string                               $reason    Why drop_settings cannot be offered, when it cannot.
	 */
	private static function not_reusable( array $rows, array $required, bool $available, string $reason ): \WP_Error {
		$data = self::refusal_data( $rows, $required, $available );
		if ( ! $available ) {
			$data['drop_settings_reason'] = $reason;
		}
		$next = $available
			? ' Choose another section, activate the plugin that provides these settings, or ask the user and repeat the insert with drop_settings set to the drop_settings_proposal of this error to copy the section without exactly these settings.'
			: ' Choose another section or activate the plugin that provides these settings; drop_settings cannot cover ' . ( 'too_many' === $reason ? 'this many settings.' : 'these settings.' );

		return new \WP_Error(
			self::REFUSED_CODE,
			sprintf(
				'The section holds settings that the live Elementor schema does not accept as they are (%1$s). Stonewright never strips settings on its own, so it did not copy the section. Nothing was written.%2$s',
				self::named( $rows ),
				$next
			),
			$data
		);
	}

	/**
	 * The refusal for a drop_settings list that is not the list of settings rejected now.
	 *
	 * @param list<array<string, mixed>>                                       $rows
	 * @param array<string, array<string, string>>                             $required
	 * @param list<array{element:string,setting:string}>                       $drop
	 * @param array{missing?:list<array<string,string>>,unexpected?:list<array<string,string>>} $diff
	 */
	private static function drop_mismatch( array $rows, array $required, array $drop, array $diff, bool $available ): \WP_Error {
		$missing    = $diff['missing'] ?? [];
		$unexpected = $diff['unexpected'] ?? $drop;
		$pairs      = static fn( array $list ): string => implode( ', ', array_map( static fn( array $row ): string => $row['element'] . ' ' . $row['setting'], array_slice( $list, 0, self::MESSAGE_SETTINGS ) ) ) . ( count( $list ) > self::MESSAGE_SETTINGS ? sprintf( ', and %d more', count( $list ) - self::MESSAGE_SETTINGS ) : '' );
		$parts      = [];
		if ( [] !== $missing ) {
			$parts[] = 'rejected but not listed: ' . $pairs( $missing );
		}
		if ( [] !== $unexpected ) {
			$parts[] = 'listed but not rejected: ' . $pairs( $unexpected );
		}
		$data = self::refusal_data( $rows, $required, $available );
		$data['drop_settings_missing']    = array_map( static fn( array $row ): array => [ 'element' => $row['element'], 'setting' => $row['setting'] ], array_slice( $missing, 0, self::MAX_VIOLATIONS ) );
		$data['drop_settings_unexpected'] = array_map( static fn( array $row ): array => [ 'element' => $row['element'], 'setting' => $row['setting'] ], array_slice( $unexpected, 0, self::MAX_VIOLATIONS ) );

		return new \WP_Error(
			self::MISMATCH_CODE,
			sprintf( 'drop_settings does not match the settings the live Elementor schema rejects now (%1$s). Nothing was removed and nothing was written. Send drop_settings exactly as the drop_settings_proposal of this error, after the user agrees, or choose another section.', implode( '; ', $parts ) ),
			$data
		);
	}

	/**
	 * @param list<array<string, mixed>>           $rows
	 * @param array<string, array<string, string>> $required
	 * @return array<string, mixed>
	 */
	private static function refusal_data( array $rows, array $required, bool $available ): array {
		$violations = [];
		foreach ( $rows as $row ) {
			foreach ( $row['violations'] as $violation ) {
				$violations[] = $violation;
			}
		}
		$first = $rows[0] ?? [ 'element' => '', 'element_type' => '', 'code' => '' ];
		$data  = [
			'status'                  => 409,
			'element'                 => (string) $first['element'],
			'element_type'            => (string) $first['element_type'],
			'code'                    => (string) $first['code'],
			'violations'              => array_slice( $violations, 0, self::MAX_VIOLATIONS ),
			'violations_total'        => count( $violations ),
			'drop_settings_available' => $available,
			'repair'                  => RemediationHints::for_code( self::REFUSED_CODE ),
		];
		if ( $available ) {
			$data['drop_settings_proposal'] = array_values( array_map( static fn( array $row ): array => [ 'element' => $row['element'], 'setting' => $row['setting'] ], $required ) );
		}

		return $data;
	}

	/**
	 * The rejected key paths for a message: up to five, grouped by element, names only.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private static function named( array $rows ): string {
		$total  = 0;
		$groups = [];
		foreach ( $rows as $row ) {
			$names = [];
			foreach ( $row['violations'] as $violation ) {
				++$total;
				if ( $total <= self::MESSAGE_SETTINGS ) {
					$names[] = preg_replace( '/^settings\.?/', '', (string) $violation['path'] ) ?: 'settings';
				}
			}
			if ( [] !== $names ) {
				$groups[] = sprintf( 'element %1$s (%2$s): %3$s', $row['element'], $row['element_type'], implode( ', ', array_unique( $names ) ) );
			}
		}
		$more = $total - self::MESSAGE_SETTINGS;

		return implode( '; ', $groups ) . ( $more > 0 ? sprintf( ', and %d more', $more ) : '' );
	}

	/**
	 * Most elements one batch of insert operations may add: the element cap of an Elementor write.
	 */
	public static function max_batch_elements(): int {
		return (int) ProviderRouter::element_limits()['max_elements'];
	}

	/**
	 * Refuses an insert that would bring the elements added by one batch over the element cap. A section is checked
	 * on its own when it is validated; a batch of several may not add up to more than one write may hold.
	 *
	 * @param int                  $already Elements the earlier insert operations of the batch add.
	 * @param array<string, mixed> $payload A payload from {@see PortableSection::validate()}.
	 */
	public static function within_batch_budget( int $already, array $payload ): ?\WP_Error {
		$adding = is_array( $payload['element'] ?? null ) ? self::count_elements( $payload['element'] ) : 0;
		$limit  = self::max_batch_elements();
		if ( $already + $adding <= $limit ) {
			return null;
		}

		return new \WP_Error(
			'stonewright_section_batch_too_large',
			sprintf( 'This batch would add %1$d elements (%2$d already planned and %3$d in this section); one write may add at most %4$d. Split the work into several batches. Nothing was written.', $already + $adding, $already, $adding, $limit ),
			[ 'status' => 413, 'limit' => $limit, 'inserted' => $already, 'adding' => $adding, 'limit_kind' => 'batch_elements', 'retryable' => true, 'write_blocked' => true ]
		);
	}

	/** @param array<string, mixed> $element */
	private static function count_elements( array $element ): int {
		$count = 1;
		foreach ( is_array( $element['elements'] ?? null ) ? $element['elements'] : [] as $child ) {
			if ( is_array( $child ) ) {
				$count += self::count_elements( $child );
			}
		}

		return $count;
	}

	/**
	 * The error for the first widget of the section that cannot render here: a placeholder that Elementor registers
	 * for a plugin that is not active, or a widget that is not registered. A V3 write validates only the settings a
	 * widget is given, so a widget without settings would otherwise pass until the page is saved.
	 *
	 * @param array<string, mixed> $element
	 */
	private static function unavailable_widget( array $element ): ?\WP_Error {
		$widget = 'widget' === ( $element['elType'] ?? '' ) ? (string) ( $element['widgetType'] ?? '' ) : '';
		if ( '' !== $widget && ! str_starts_with( $widget, 'e-' ) ) {
			$registration = WidgetAvailability::registration( $widget );
			$name         = (string) ( $element['id'] ?? '' );
			if ( 'placeholder' === $registration ) {
				$requires = WidgetAvailability::placeholder_requirement( $widget );
				return new \WP_Error(
					'stonewright_section_placeholder_widget',
					sprintf( 'The section uses the "%1$s" widget (element %2$s), which is provided by a plugin that is not active on this site (%3$s). Elementor shows a placeholder in its place, so the copy would render empty. Nothing was written.', $widget, $name, 'elementor-pro' === $requires ? 'Elementor Pro' : $requires ),
					[ 'status' => 409, 'widget_type' => $widget, 'element' => $name, 'requires' => $requires, 'repair' => 'Activate the plugin that provides the widget, or choose another section.' ]
				);
			}
			if ( 'unregistered' === $registration ) {
				return new \WP_Error(
					'stonewright_section_widget_unregistered',
					sprintf( 'The section uses the "%1$s" widget (element %2$s), which is not registered on this site. Nothing was written.', $widget, $name ),
					[ 'status' => 409, 'widget_type' => $widget, 'element' => $name, 'repair' => 'Activate the plugin that provides the widget, or choose another section.' ]
				);
			}
		}
		foreach ( is_array( $element['elements'] ?? null ) ? $element['elements'] : [] as $child ) {
			if ( is_array( $child ) ) {
				$found = self::unavailable_widget( $child );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	// ---------------------------------------------------------------- id attributes

	/**
	 * The id attribute an element sets: `_element_id` in V3, `_cssid` (a string prop) in V4.
	 *
	 * @param array<string, mixed> $element
	 * @return array{key:string,value:string}|null
	 */
	private static function dom_id( array $element ): ?array {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		$v3       = $settings['_element_id'] ?? null;
		if ( is_string( $v3 ) && '' !== trim( $v3 ) ) {
			return [ 'key' => '_element_id', 'value' => trim( $v3 ) ];
		}
		$v4 = is_array( $settings['_cssid'] ?? null ) ? ( $settings['_cssid']['value'] ?? null ) : null;
		if ( is_string( $v4 ) && '' !== trim( $v4 ) ) {
			return [ 'key' => '_cssid', 'value' => trim( $v4 ) ];
		}

		return null;
	}

	/**
	 * Every id attribute the document uses.
	 *
	 * @param array<int, mixed> $tree
	 * @return array<string, true>
	 */
	private static function dom_ids( array $tree ): array {
		$used = [];
		foreach ( $tree as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$dom = self::dom_id( $element );
			if ( null !== $dom ) {
				$used[ $dom['value'] ] = true;
			}
			$used += self::dom_ids( is_array( $element['elements'] ?? null ) ? $element['elements'] : [] );
		}

		return $used;
	}

	/**
	 * Gives every id attribute of the copy that the document already uses, or that an earlier element of the copy
	 * uses, the next free `name-2`, `name-3`, and points the `#name` links of the copy at the renamed id.
	 *
	 * @param array<string, mixed>           $copy
	 * @param array<string, true>            $used    Id attributes taken in the document.
	 * @param list<array{0:string,1:string}> $renamed Old and new id of every rename, filled as it goes.
	 * @return array<string, mixed>
	 */
	private static function rename_dom_ids( array $copy, array $used, array &$renamed ): array {
		$seen  = [];
		$links = [];
		$copy  = self::rename_dom_ids_in( $copy, $used, $seen, $links, $renamed );
		if ( [] !== $links ) {
			$copy = self::rewrite_links( $copy, $links );
		}

		return $copy;
	}

	/**
	 * @param array<string, mixed>           $element
	 * @param array<string, true>            $used
	 * @param array<string, true>            $seen    Id attributes met in the copy so far.
	 * @param array<string, string>          $links   Old to new id, for the first element of the copy that carries an id when that one was renamed.
	 * @param list<array{0:string,1:string}> $renamed
	 * @return array<string, mixed>
	 */
	private static function rename_dom_ids_in( array $element, array &$used, array &$seen, array &$links, array &$renamed ): array {
		$dom = self::dom_id( $element );
		if ( null !== $dom ) {
			$old = $dom['value'];
			$new = $old;
			if ( isset( $used[ $old ] ) ) {
				$new = self::free_dom_id( $old, $used );
				if ( '_element_id' === $dom['key'] ) {
					$element['settings']['_element_id'] = $new;
				} else {
					$element['settings']['_cssid']['value'] = $new;
				}
				if ( ! isset( $seen[ $old ] ) ) {
					$links[ $old ] = $new;
				}
				$renamed[] = [ $old, $new ];
			}
			$used[ $new ] = true;
			$seen[ $old ] = true;
		}
		if ( is_array( $element['elements'] ?? null ) ) {
			$children = [];
			foreach ( $element['elements'] as $child ) {
				$children[] = is_array( $child ) ? self::rename_dom_ids_in( $child, $used, $seen, $links, $renamed ) : $child;
			}
			$element['elements'] = $children;
		}

		return $element;
	}

	/** @param array<string, true> $used */
	private static function free_dom_id( string $id, array $used ): string {
		for ( $n = 2; $n < 10000; ++$n ) {
			if ( ! isset( $used[ $id . '-' . $n ] ) ) {
				return $id . '-' . $n;
			}
		}

		return $id . '-' . bin2hex( random_bytes( 3 ) );
	}

	/**
	 * Points the `#name` links in the settings of the copy at the renamed ids. A link is a string that is exactly
	 * `#name`, or an `href="#name"` inside HTML; styles and the id attributes themselves are left as they are.
	 *
	 * @param array<string, mixed>  $element
	 * @param array<string, string> $links   Old to new id.
	 * @return array<string, mixed>
	 */
	private static function rewrite_links( array $element, array $links ): array {
		if ( is_array( $element['settings'] ?? null ) ) {
			$element['settings'] = self::rewrite_link_values( $element['settings'], $links, 0 );
		}
		if ( is_array( $element['elements'] ?? null ) ) {
			$element['elements'] = array_map( static fn( mixed $child ): mixed => is_array( $child ) ? self::rewrite_links( $child, $links ) : $child, $element['elements'] );
		}

		return $element;
	}

	/**
	 * @param array<mixed>          $value
	 * @param array<string, string> $links
	 * @return array<mixed>
	 */
	private static function rewrite_link_values( array $value, array $links, int $depth ): array {
		if ( $depth > 12 ) {
			return $value;
		}
		foreach ( $value as $key => $member ) {
			if ( in_array( $key, [ '_element_id', '_cssid' ], true ) ) {
				continue;
			}
			if ( is_array( $member ) ) {
				$value[ $key ] = self::rewrite_link_values( $member, $links, $depth + 1 );
			} elseif ( is_string( $member ) ) {
				foreach ( $links as $old => $new ) {
					if ( '#' . $old === $member ) {
						$member = '#' . $new;
						continue;
					}
					$member = str_replace( [ 'href="#' . $old . '"', "href='#" . $old . "'" ], [ 'href="#' . $new . '"', "href='#" . $new . "'" ], $member );
				}
				$value[ $key ] = $member;
			}
		}

		return $value;
	}

	/**
	 * The references of the section that definitely do not exist here. A reference this site cannot check
	 * (unknown, not false) is reported by extract and never blocks.
	 *
	 * @param list<array<string, mixed>> $references
	 * @return list<array{type:string,id:string,at:list<string>}>
	 */
	private static function missing_references( string $builder, array $references ): array {
		$missing = [];
		foreach ( $references as $reference ) {
			if ( ! in_array( (string) $reference['type'], self::REQUIRED[ $builder ] ?? [], true ) ) {
				continue;
			}
			if ( false === ReferenceCatalog::exists( (string) $reference['type'], (string) $reference['id'] ) ) {
				$missing[] = [ 'type' => (string) $reference['type'], 'id' => (string) $reference['id'], 'at' => array_values( array_map( 'strval', (array) $reference['at'] ) ) ];
			}
		}

		return array_slice( $missing, 0, self::MAX_MISSING );
	}

	/**
	 * The first V4 element type of the section that this site does not offer, or null.
	 *
	 * @param array<string, mixed> $element
	 */
	private static function unavailable_types( string $builder, array $element ): ?string {
		if ( Builder::ELEMENTOR_V4 !== $builder ) {
			return null;
		}
		$type = Builder::element_type( $element );
		if ( str_starts_with( $type, 'e-' ) && null === AtomicSchemaRepository::for_atomic_type( $type ) ) {
			return $type;
		}
		foreach ( is_array( $element['elements'] ?? null ) ? $element['elements'] : [] as $child ) {
			if ( is_array( $child ) ) {
				$found = self::unavailable_types( $builder, $child );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Copies an element and its children under fresh ids.
	 *
	 * @param array<string, mixed>   $element
	 * @param array<string, true>    $used      Ids taken in the target document and by this copy so far.
	 * @param callable():string      $generator
	 * @param array<string, string>  $id_map    Placeholder to new id, filled as the copy goes.
	 * @return array<string, mixed>
	 */
	private static function copy( array $element, string $builder, array &$used, callable $generator, array &$id_map ): array {
		$placeholder = (string) $element['id'];
		$new_id      = self::fresh_id( $used, $generator );
		$id_map[ $placeholder ] = $new_id;
		$copy        = $element;
		$copy['id']  = $new_id;

		if ( Builder::ELEMENTOR_V4 === $builder && is_array( $element['styles'] ?? null ) && [] !== $element['styles'] ) {
			$map    = [];
			$styles = [];
			foreach ( $element['styles'] as $old => $style ) {
				$suffix = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
				$new    = 'e-' . $new_id . '-' . $suffix;
				while ( isset( $styles[ $new ] ) ) {
					$suffix = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
					$new    = 'e-' . $new_id . '-' . $suffix;
				}
				$map[ (string) $old ] = $new;
				if ( is_array( $style ) && isset( $style['id'] ) && (string) $style['id'] === (string) $old ) {
					$style['id'] = $new;
				}
				$styles[ $new ] = $style;
			}
			$copy['styles']   = $styles;
			$copy['settings'] = PortableSection::remap_classes( is_array( $element['settings'] ?? null ) ? $element['settings'] : [], $map );
		}

		$children = [];
		foreach ( is_array( $element['elements'] ?? null ) ? $element['elements'] : [] as $child ) {
			if ( is_array( $child ) ) {
				$children[] = self::copy( $child, $builder, $used, $generator, $id_map );
			}
		}
		$copy['elements'] = $children;

		return $copy;
	}

	/**
	 * @param array<string, true> $used
	 * @param callable():string   $generator
	 */
	private static function fresh_id( array &$used, callable $generator ): string {
		for ( $attempt = 0; $attempt < 1000; ++$attempt ) {
			$id = (string) $generator();
			if ( '' !== $id && ! isset( $used[ $id ] ) ) {
				$used[ $id ] = true;
				return $id;
			}
		}

		// A generator that keeps repeating itself is bypassed: a random id is almost certainly free.
		do {
			$id = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
		} while ( isset( $used[ $id ] ) );
		$used[ $id ] = true;

		return $id;
	}
}
