<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\System;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Knowledge\KnowledgeBundle;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Imports instructions, memory, and skills from a Stonewright bundle.
 */
final class KnowledgeImport extends AbilityKernel {

	public function name(): string {
		return 'stonewright/knowledge-import';
	}

	public function label(): string {
		return __( 'Import knowledge bundle', 'stonewright' );
	}

	public function description(): string {
		return __( 'Imports custom instructions, memory entries, and site skills from a Stonewright knowledge bundle.', 'stonewright' );
	}

	public function category(): string {
		return 'system';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'bundle' ],
			'properties'           => [
				'confirmation_token' => [ 'type' => 'string' ],
				'bundle' => [
					'type'        => 'object',
					'description' => 'A Stonewright knowledge bundle exported by stonewright/knowledge-export.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                    => [ 'type' => 'boolean' ],
				'instructions_imported' => [ 'type' => 'integer' ],
				'memory_imported'       => [ 'type' => 'integer' ],
				'skills_imported'       => [
					'type'        => 'integer',
					'description' => 'Skills added as disabled drafts. A bundle never enables a skill or replaces one.',
				],
				'skills_skipped'        => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'maxItems'    => KnowledgeBundle::SKIPPED_LIMIT,
					'description' => 'Slugs of bundle skills that were not added: the slug already belongs to a skill in any state, the trash and built-in skills included, or the entry was refused. At most ' . KnowledgeBundle::SKIPPED_LIMIT . ' are listed.',
				],
			],
			'required'   => [ 'ok', 'instructions_imported', 'memory_imported', 'skills_imported', 'skills_skipped' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $a ): array|\WP_Error {
				$bundle = $a['bundle'] ?? null;
				if ( ! is_array( $bundle ) ) {
					return $this->error( 'knowledge_bundle_invalid', __( 'bundle must be an object.', 'stonewright' ) );
				}

				try {
					$result = KnowledgeBundle::import( $bundle );
				} catch ( \InvalidArgumentException $e ) {
					return $this->error( 'knowledge_bundle_invalid', $e->getMessage() );
				}

				return $this->ok( $result );
			}
		);
	}
}
