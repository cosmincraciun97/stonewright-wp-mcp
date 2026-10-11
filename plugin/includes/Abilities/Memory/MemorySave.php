<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Memory;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Memory\MemoryScreen;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class MemorySave extends AbilityKernel {

	public function name(): string {
		return 'stonewright/memory-save';
	}

	public function label(): string {
		return __( 'Save memory entry', 'stonewright' );
	}

	public function description(): string {
		return __( 'Inserts or updates a typed memory entry. Text that tells an agent to override the plugin rules, safety gates or confirmation tokens, or to send credentials elsewhere, is stored with status needs_review (needs_review:true in the answer): it is never offered at task start or as site context until an administrator approves it on the Memory page. A later save under the same key does not clear it.', 'stonewright' );
	}

	public function category(): string {
		return 'memory';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'type', 'scope', 'key', 'name' ],
			'properties'           => [
				'confirmation_token' => [ 'type' => 'string' ],
				'type'       => [
					'type' => 'string',
					'enum' => Memory::valid_types(),
				],
				'scope'      => [ 'type' => 'string', 'maxLength' => 64 ],
				'key'        => [ 'type' => 'string', 'maxLength' => 190 ],
				'name'       => [ 'type' => 'string', 'maxLength' => 190 ],
				'value'      => [],
				'confidence' => [
					'type'    => 'number',
					'default' => 1.0,
					'minimum' => 0,
					'maximum' => 1,
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok' => [ 'type' => 'boolean' ],
				'id' => [ 'type' => 'integer' ],
				'needs_review' => [ 'type' => 'boolean', 'description' => 'Present and true when the entry was stored for review and is not offered to agents.' ],
				'status'       => [ 'type' => 'string', 'description' => 'Present with needs_review: the stored status, needs_review.' ],
				'review_rules' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Present with needs_review: the screen rules the text broke.' ],
				'message'      => [ 'type' => 'string' ],
			],
			'required'   => [ 'ok', 'id' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $a ): array|\WP_Error {
				if ( ! get_option( 'stonewright_memory_enabled', true ) ) {
					return $this->error( 'memory_disabled', __( 'Memory is disabled on this site.', 'stonewright' ) );
				}

				// Text that tells an agent to override the rules is kept, but only as an entry waiting for review.
				$rules = MemoryScreen::blocking_rules( (string) $a['name'], $a['value'] ?? null );

				$id = Memory::put_typed(
					(string) $a['type'],
					(string) $a['scope'],
					(string) $a['key'],
					(string) $a['name'],
					$a['value'] ?? null,
					(float) ( $a['confidence'] ?? 1.0 ),
					[] === $rules ? [] : [ 'status' => MemoryScreen::NEEDS_REVIEW ]
				);

				if ( $id <= 0 ) {
					return $this->error(
						'memory_save_failed',
						__( 'Failed to save memory entry.', 'stonewright' )
					);
				}

				$stored = Memory::get_by_id( $id );
				if ( MemoryScreen::NEEDS_REVIEW === (string) ( $stored['status'] ?? '' ) ) {
					return [
						'ok'           => true,
						'id'           => $id,
						'needs_review' => true,
						'status'       => MemoryScreen::NEEDS_REVIEW,
						'review_rules' => $rules,
						'message'      => __( 'Stored for review. The text tells an agent to override the plugin rules or move credentials, so it is not offered at task start or as site context until an administrator approves it on the Stonewright Memory page.', 'stonewright' ),
					];
				}

				return [
					'ok' => true,
					'id' => $id,
				];
			}
		);
	}
}
