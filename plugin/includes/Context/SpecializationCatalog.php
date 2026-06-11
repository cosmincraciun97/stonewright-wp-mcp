<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Context;

/**
 * Compact, clean-room guidance for plugin-specific WordPress workflows.
 *
 * The catalog describes what Stonewright agents should discover and which
 * guarded Stonewright surfaces to prefer. It intentionally references only
 * public official documentation and Stonewright abilities.
 *
 * @stonewright-status stable
 */
final class SpecializationCatalog {

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function all(): array {
		return [
			self::acf(),
			self::acpt(),
			self::meta_box(),
			self::ase(),
			self::pods(),
			self::woocommerce(),
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function match( string $task, string $surface = 'unknown' ): array {
		$query        = self::normalise( $task . ' ' . $surface );
		$has_explicit = false;
		$scored       = [];

		foreach ( self::all() as $specialization ) {
			$explicit_score = self::score_terms( $query, $specialization['explicit_terms'] ?? [] );
			if ( $explicit_score > 0 ) {
				$has_explicit = true;
			}
			$generic_score = self::score_terms( $query, $specialization['task_terms'] ?? [] );
			$score         = ( $explicit_score * 10 ) + $generic_score;

			if ( $score > 0 ) {
				$specialization['_score'] = $score;
				$scored[]                 = $specialization;
			}
		}

		if ( $has_explicit ) {
			$scored = array_values(
				array_filter(
					$scored,
					static fn( array $row ): bool => self::score_terms(
						$query,
						$row['explicit_terms'] ?? []
					) > 0
				)
			);
		}

		usort(
			$scored,
			static fn( array $a, array $b ): int => $b['_score'] <=> $a['_score']
		);

		return array_map(
			static function ( array $row ): array {
				unset( $row['_score'], $row['explicit_terms'], $row['task_terms'] );
				return $row;
			},
			array_slice( $scored, 0, 6 )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function acf(): array {
		return self::base(
			'acf',
			'Advanced Custom Fields',
			[ 'acf', 'advanced custom fields' ],
			[
				'acf',
				'custom field',
				'custom fields',
				'field',
				'field group',
				'field groups',
				'flexible content',
				'post type',
				'repeater',
				'taxonomy',
				'taxonomies',
				'options page',
			],
			[
				'official_docs' => [
					'https://www.advancedcustomfields.com/resources/wp-rest-api-integration/',
					'https://www.advancedcustomfields.com/resources/get_field/',
					'https://www.advancedcustomfields.com/resources/update_field/',
					'https://www.advancedcustomfields.com/resources/post-types-and-taxonomies/',
				],
				'capabilities'  => [
					'discover field groups, locations, post types, taxonomies, and option pages',
					'read and write values on posts, users, terms, comments, and options when exposed',
					'handle repeaters, groups, and flexible content by schema, not by guessed meta keys',
					'audit unused or drifting field definitions before schema edits',
				],
				'write_surface' => 'Prefer ACF REST field objects when enabled. For simple post meta edits, use Stonewright content abilities only after schema discovery. Use WP-CLI discovery before any plugin command.',
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function acpt(): array {
		return self::base(
			'acpt',
			'Advanced Custom Post Types',
			[ 'acpt', 'advanced custom post types' ],
			[ 'acpt', 'custom field', 'custom fields', 'field group', 'fields', 'meta group', 'meta box', 'meta field', 'option page', 'post type', 'taxonomy', 'taxonomies' ],
			[
				'official_docs' => [
					'https://docs.acpt.io/',
					'https://docs.acpt.io/basics/custom-post-types',
					'https://docs.acpt.io/tools/custom-apis',
					'https://docs.acpt.io/meta-fields/field-types',
					'https://docs.acpt.io/integrations/api-rest-field-integration',
				],
				'capabilities'  => [
					'discover custom post types, taxonomies, option pages, meta groups, boxes, and fields',
					'preserve ACPT hierarchy when changing groups, boxes, and fields',
					'use ACPT custom APIs only after the site exposes them and authentication is known',
					'audit orphaned groups, fields, and option-page values before bulk changes',
				],
				'write_surface' => 'Prefer ACPT documented custom API or REST integration when configured. Otherwise use guarded WP-CLI and native WordPress content/taxonomy operations where equivalent.',
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function meta_box(): array {
		return self::base(
			'meta-box',
			'Meta Box',
			[ 'meta box', 'metabox', 'rwmb' ],
			[ 'meta box', 'metabox', 'rwmb', 'custom field', 'custom fields', 'field group', 'fields', 'post type', 'relationship', 'settings page', 'taxonomy', 'taxonomies' ],
			[
				'official_docs' => [
					'https://docs.metabox.io/custom-fields/',
					'https://docs.metabox.io/extensions/meta-box-builder/',
					'https://docs.metabox.io/extensions/mb-rest-api/',
					'https://docs.metabox.io/extensions/mb-relationships/',
					'https://docs.metabox.io/extensions/mb-settings-page/',
				],
				'capabilities'  => [
					'discover field groups, fields, post types, taxonomies, settings pages, and relationships',
					'read and update Meta Box values through MB REST API when the extension is active',
					'treat relationships as first-class schema, not plain text fields',
					'verify settings-page and term/user/comment targets separately from post meta',
				],
				'write_surface' => 'Prefer MB REST API or registered Meta Box APIs when present. Use core meta writes only for simple fields after permission and schema checks.',
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function ase(): array {
		return self::base(
			'ase',
			'Admin and Site Enhancements',
			[ 'ase', 'admin and site enhancements', 'wpase' ],
			[ 'ase', 'admin site enhancements', 'custom content types', 'custom field', 'custom fields', 'field group', 'fields', 'options page', 'post type', 'taxonomy', 'taxonomies' ],
			[
				'official_docs' => [
					'https://www.wpase.com/features/custom-content-types/',
					'https://www.wpase.com/documentation/custom-field-types/',
					'https://www.wpase.com/documentation/',
				],
				'capabilities'  => [
					'discover ASE Pro custom field groups, post types, taxonomies, options pages, and values',
					'handle posts, terms, and options-page targets as separate value scopes',
					'apply merge or replace semantics only after field-shape discovery',
					'audit unused ASE definitions before structural edits',
				],
				'write_surface' => 'Use ASE documented REST exposure when enabled. Otherwise prefer native WordPress post, term, option, and taxonomy operations through guarded Stonewright tools.',
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function pods(): array {
		return self::base(
			'pods',
			'Pods',
			[ 'pods', 'pod' ],
			[ 'pods', 'pod', 'advanced content type', 'act', 'custom field', 'custom fields', 'field group', 'fields', 'item', 'items', 'post type', 'settings page', 'taxonomy', 'taxonomies' ],
			[
				'official_docs' => [
					'https://pods.io/',
					'https://docs.pods.io/advanced-topics/rest-api/',
					'https://docs.pods.io/code/rest-api-endpoints/',
					'https://docs.pods.io/code/wp-cli-commands/',
					'https://docs.pods.io/code/wp-cli-commands/wp-pods-api/',
				],
				'capabilities'  => [
					'discover Pods, groups, fields, Advanced Content Types, settings pages, and built-in extensions',
					'use Pods REST endpoints for configuration management when available',
					'use Pods WP-CLI commands for pod, group, field, and item workflows',
					'keep group-before-field ordering so fields are attached to the correct Pod structure',
				],
				'write_surface' => 'Prefer Pods REST endpoints or wp pods/wp pods-api commands. Always read back pod, group, and field structure after writes.',
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function woocommerce(): array {
		return self::base(
			'woocommerce',
			'WooCommerce Catalog',
			[ 'woocommerce', 'woo', 'wc' ],
			[
				'woocommerce',
				'product',
				'products',
				'variation',
				'variations',
				'sku',
				'attribute',
				'shipping class',
			],
			[
				'official_docs' => [
					'https://developer.woocommerce.com/docs/apis/rest-api/v3/',
					'https://developer.woocommerce.com/docs/wc-cli/wc-cli-commands/',
					'https://developer.woocommerce.com/docs/apis/rest-api/v3/product-shipping-classes/',
					'https://woocommerce.com/document/variable-product/',
				],
				'capabilities'  => [
					'list and search products by SKU, category, stock status, sale state, and product type',
					'create or edit simple and variable products with attributes, prices, stock, and images',
					'generate variation matrices after attributes and terms are confirmed',
					'manage categories, tags, global attributes, terms, and shipping classes',
					'soft-delete catalog objects by default and require explicit force for permanent deletion',
				],
				'write_surface' => 'Prefer WooCommerce REST v3 or wp wc commands. Validate SKU uniqueness and read back parent, attributes, variations, and default attributes after writes.',
			]
		);
	}

	/**
	 * @param list<string> $explicit_terms
	 * @param list<string> $task_terms
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private static function base(
		string $id,
		string $label,
		array $explicit_terms,
		array $task_terms,
		array $overrides
	): array {
		return array_merge(
			[
				'id'                 => $id,
				'label'              => $label,
				'explicit_terms'     => $explicit_terms,
				'task_terms'         => $task_terms,
				'discovery_tools'    => [
					'stonewright/site-plugins-list',
					'stonewright/wp-cli-status',
					'stonewright/wp-cli-discover',
					'stonewright/wp-cli-run',
				],
				'workflow'           => [
					'Confirm the plugin is installed and active before promising support.',
					'Discover available REST routes, WP-CLI command groups, post types, taxonomies, and value targets.',
					'Choose the narrowest official surface for the requested operation.',
					'Batch reads and writes where the official surface supports it.',
					'Record repeatable project conventions with stonewright/learning-record.',
				],
				'safety_rules'       => [
					'Never use wp eval, wp eval-file, wp shell, wp package, --exec, or --require.',
					'Use stonewright_context_token for writes and confirmation tokens in production-safe mode.',
					'Snapshot posts or theme-backed records before mutation.',
					'Do not invent hidden storage keys; discover schema first.',
				],
				'verification_steps' => [
					'Verify by reading back the changed schema or values after every write pass.',
					'For front-end visible changes, verify with an external browser MCP.',
					'For bulk operations, return counts, skipped items, and per-item errors.',
				],
			],
			$overrides
		);
	}

	private static function normalise( string $text ): string {
		return trim( preg_replace( '/[^a-z0-9]+/i', ' ', strtolower( $text ) ) ?? '' );
	}

	/**
	 * @param mixed $terms
	 */
	private static function score_terms( string $query, mixed $terms ): int {
		$score = 0;
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			$needle = self::normalise( (string) $term );
			if ( '' !== $needle && preg_match( '/(^| )' . preg_quote( $needle, '/' ) . '( |$)/', $query ) ) {
				++$score;
			}
		}
		return $score;
	}
}
