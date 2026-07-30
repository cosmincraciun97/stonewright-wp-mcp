<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\System;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Knowledge\KnowledgeBundle;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Exports instructions, memory, and skills as a portable JSON-ready bundle.
 */
final class KnowledgeExport extends AbilityKernel {

	public function name(): string {
		return 'stonewright/knowledge-export';
	}

	public function label(): string {
		return __( 'Export knowledge bundle', 'stonewright' );
	}

	public function description(): string {
		return __( 'Exports custom instructions, memory entries, and site skills in the Stonewright knowledge bundle format.', 'stonewright' );
	}

	public function category(): string {
		return 'system';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'     => [ 'type' => 'boolean' ],
				'bundle' => [ 'type' => 'object' ],
			],
			'required'   => [ 'ok', 'bundle' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array {
		return $this->ok(
			[
				'bundle' => KnowledgeBundle::export(),
			]
		);
	}
}
