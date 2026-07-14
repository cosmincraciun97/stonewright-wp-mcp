<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Expertise;

/** Immutable, release-versioned P0 curriculum shipped with Stonewright. */
final class BundledPacks {

	/** @return list<array<string, mixed>> */
	public static function all(): array {
		return [
			self::pack( 'wordpress-core', 'wordpress', 'content-media-taxonomy-users-menus', 'verified', 'Use when changing native WordPress content, media, taxonomies, users, or menus.', [ 'wordpress', 'post', 'page', 'media', 'taxonomy', 'user', 'menu' ], [ 'wordpress' => '>=6.4' ], [ 'stonewright/content-bulk-upsert-posts', 'stonewright/media-upload-batch' ], [ 'Native post with media and taxonomy', 'Menu repair', 'User capability-safe update', 'Bulk content rollback' ] ),
			self::pack( 'gutenberg-fse', 'gutenberg', 'blocks-fse-patterns-theme-json', 'verified', 'Use when implementing Gutenberg blocks, FSE templates, patterns, or theme.json.', [ 'gutenberg', 'block', 'fse', 'pattern', 'theme json', 'template part' ], [ 'wordpress' => '>=6.4' ], [ 'stonewright/gutenberg-apply-to-post', 'stonewright/blocks-list-registered' ], [ 'Registered third-party block', 'FSE template part', 'theme.json token plan', 'Invalid block repair' ] ),
			self::pack( 'elementor-v3', 'elementor', 'v3-widgets-containers-dynamic-tags', 'verified', 'Use when reading, planning, writing, or repairing an Elementor V3 tree.', [ 'elementor', 'widget', 'container', 'dynamic tag', 'loop grid' ], [ 'wordpress' => '>=6.4', 'elementor_core' => '>=3.20 <4.0', 'elementor_pro' => 'optional' ], [ 'stonewright/elementor-schema', 'stonewright/elementor-v3-batch-mutate' ], [ 'CTA link semantics', 'Third-party widget live schema', 'Responsive container repair', 'Pro widget unavailable' ] ),
			self::pack( 'elementor-v4-atomic', 'elementor', 'v4-atomic-classes-variables-interactions', 'draft', 'Use only for explicit Elementor V4 Atomic discovery until its typed editor adapter is verified.', [ 'elementor v4', 'atomic', 'class', 'variable', 'interaction' ], [ 'wordpress' => '>=6.4', 'elementor_core' => '>=4.0' ], [ 'stonewright/elementor-v4-status', 'stonewright/elementor-v4-list-atomic-node-types' ], [ 'Atomic node discovery', 'Class variable reference', 'Interaction schema rejection', 'No V3 fallback' ] ),
			self::pack( 'design-to-wordpress', 'design', 'figma-image-native-wordpress', 'verified', 'Use when converting Figma, screenshots, images, or design briefs into editable WordPress.', [ 'figma', 'screenshot', 'image', 'design', 'pixel perfect', 'landing page' ], [ 'wordpress' => '>=6.4' ], [ 'stonewright/design-native-plan' ], [ 'Missing CTA destination', 'Measured spacing evidence', 'Native-first proposal', 'Approval-gated CSS delta' ] ),
			self::pack( 'theme-builder', 'elementor', 'theme-builder-conditions', 'verified', 'Use when creating headers, footers, archives, singles, or Theme Builder conditions.', [ 'theme builder', 'header', 'footer', 'archive', 'single template', 'display condition' ], [ 'wordpress' => '>=6.4', 'elementor_core' => '>=3.20 <4.0', 'elementor_pro' => 'optional' ], [ 'stonewright/theme-builder-apply-template' ], [ 'Header include condition', 'Footer replacement', 'Archive template readback', 'Condition rollback' ] ),
			self::pack( 'woocommerce-catalog', 'woocommerce', 'catalog-products-templates', 'verified', 'Use when editing WooCommerce products, variations, taxonomies, templates, or catalog flows.', [ 'woocommerce', 'product', 'variation', 'catalog', 'sku', 'attribute' ], [ 'wordpress' => '>=6.4', 'woocommerce' => '>=8.0' ], [ 'stonewright/content-bulk-upsert-posts' ], [ 'Unique SKU gate', 'Attribute before variation', 'Catalog-only template', 'Soft-delete rollback' ] ),
			self::pack( 'content-model-dynamic-data', 'wordpress', 'acf-cpt-taxonomy-options-dynamic-content', 'verified', 'Use when implementing ACF, CPT, taxonomy, options, or dynamic content models.', [ 'acf', 'cpt', 'custom field', 'taxonomy', 'options page', 'dynamic content' ], [ 'wordpress' => '>=6.4', 'acf' => 'optional' ], [ 'stonewright/content-model-loop-grid-flow', 'stonewright/wp-cli-discover' ], [ 'Field schema before values', 'CPT taxonomy relation', 'Options target', 'Dynamic loop readback' ] ),
			self::pack( 'security-write-recovery', 'security', 'permissions-backup-confirmation-audit-rollback', 'verified', 'Use for every state-changing task, destructive operation, or rollback.', [ 'write', 'delete', 'security', 'backup', 'rollback', 'permission', 'confirmation' ], [ 'wordpress' => '>=6.4' ], [ 'stonewright/security-issue-confirmation-token' ], [ 'Permission denied', 'Production confirmation', 'Backup before write', 'Atomic rollback' ] ),
			self::pack( 'visual-responsive-verification', 'visual', 'screenshots-responsive-interactions-repair', 'verified', 'Use when visual or responsive fidelity must be verified on the public frontend.', [ 'visual', 'responsive', 'screenshot', 'overflow', 'interaction', 'pixel' ], [ 'wordpress' => '>=6.4' ], [ 'stonewright/design-native-plan' ], [ 'Desktop delta', 'Tablet overflow', 'Mobile interaction', 'Editor opens after repair' ] ),
		];
	}

	/** @param list<string> $terms @param array<string, string> $versions @param list<string> $capabilities @param list<string> $scenarios @param list<string> $verified_fingerprints @return array<string, mixed> */
	private static function pack( string $id, string $domain, string $capability, string $status, string $trigger, array $terms, array $versions, array $capabilities, array $scenarios, array $verified_fingerprints = [] ): array {
		$workflow = [
			'discover' => 'Detect live plugin versions, registered capabilities, and feature flags.',
			'inspect'  => 'Read compact structure, schema, and current settings before planning.',
			'plan'     => 'Choose native editable primitives and resolve semantics before settings.',
			'compile'  => 'Compile only typed settings accepted by the live schema.',
			'write'    => 'Require permission, context, backup, audit, idempotency, and confirmation gates.',
			'verify'   => 'Read back state and verify editor, frontend, responsive, and interactions as applicable.',
			'repair'   => 'Diagnose the exact delta and apply the smallest reversible patch.',
			'learn'    => 'Record only verified outcomes as versioned KnowledgeCandidate data.',
		];
		$evals = [];
		foreach ( array_keys( $workflow ) as $level ) {
			$evals[] = [ 'id' => $id . '-' . $level, 'type' => in_array( $level, [ 'repair', 'learn' ], true ) ? $level : 'positive', 'level' => $level, 'critical' => in_array( $level, [ 'compile', 'write', 'verify' ], true ) ];
		}
		foreach ( $scenarios as $index => $scenario ) {
			$evals[] = [ 'id' => $id . '-domain-' . ( $index + 1 ), 'type' => 1 === $index ? 'negative' : ( 3 === $index ? 'repair' : 'positive' ), 'level' => 1 === $index ? 'compile' : ( 3 === $index ? 'repair' : 'verify' ), 'scenario' => $scenario, 'critical' => 1 === $index ];
		}
		$pack = [
			'id'                            => $id,
			'domain'                        => $domain,
			'capability'                    => $capability,
			'version'                       => '1.0.0',
			'status'                        => $status,
			'tier'                          => 'P0',
			'trigger'                       => $trigger,
			'terms'                         => $terms,
			'supported_versions'            => $versions,
			'required_capabilities'         => $capabilities,
			'workflow'                      => $workflow,
			'schema_refs'                   => $capabilities,
			'official_refs'                 => self::official_refs( $domain ),
			'recipes'                       => array_values( array_map( static fn( string $scenario ): array => [ 'name' => $scenario, 'verified' => true ], $scenarios ) ),
			'failure_modes'                 => [ 'runtime capability missing', 'version mismatch', 'unknown setting', 'readback mismatch', 'semantic action unresolved' ],
			'semantic_rules'                => [ 'semantic_action_resolution', 'native_editability', 'read_before_write', 'minimal_repair' ],
			'anti_hallucination_gates'      => [ 'live_schema_required', 'unknown_setting_rejection', 'no_silent_fallback', 'provenance_required' ],
			'write_gates'                   => [ 'permission', 'context_token', 'backup', 'audit', 'idempotency', 'confirmation', 'readback', 'rollback' ],
			'eval_cases'                    => $evals,
			'dependencies'                  => 'wordpress-core' === $id ? [] : [ 'wordpress-core' ],
			'conflicts'                     => [],
			'references'                    => [ 'workflow', 'recipes', 'eval_cases' ],
			'provenance'                    => [ 'type' => 'stonewright_release', 'source' => 'bundled curriculum', 'license' => 'AGPL-3.0-or-later' ],
			'verified_runtime_fingerprints' => $verified_fingerprints,
			'last_verified_at'              => '2026-07-14T00:00:00Z',
		];
		$pack['hash'] = self::hash( $pack );
		return $pack;
	}

	/** @return list<string> */
	private static function official_refs( string $domain ): array {
		return match ( $domain ) {
			'elementor'   => [ 'https://developers.elementor.com/', 'https://elementor.com/help/' ],
			'gutenberg'   => [ 'https://developer.wordpress.org/block-editor/' ],
			'woocommerce' => [ 'https://developer.woocommerce.com/docs/' ],
			default       => [ 'https://developer.wordpress.org/' ],
		};
	}

	/** @param array<string, mixed> $pack */
	public static function hash( array $pack ): string {
		unset( $pack['hash'] );
		return hash( 'sha256', wp_json_encode( $pack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '' );
	}
}
