<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\System;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Canonical one-call task gateway.
 *
 * It preserves the proven workflow-preflight implementation while making the
 * compact, task-aware path explicit instead of measuring context-bootstrap
 * under a name that did not exist.
 *
 * @stonewright-status stable
 */
final class TaskStart extends AbilityKernel {

	public function name(): string {
		return 'stonewright/task-start';
	}

	public function label(): string {
		return __( 'Start Stonewright task', 'stonewright' );
	}

	public function description(): string {
		return __( 'Canonical one-call task start: issues the context token and returns compact skills, memory, expertise, capability gates, and the exact next tool path.', 'stonewright' );
	}

	public function category(): string {
		return 'system';
	}

	public function input_schema(): array {
		return ( new WorkflowPreflight() )->input_schema();
	}

	public function output_schema(): array {
		return ( new WorkflowPreflight() )->output_schema();
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		if ( ! array_key_exists( 'responseMode', $args ) ) {
			$args['responseMode'] = 'compact';
		}

		return ( new WorkflowPreflight() )->execute( $args );
	}
}
