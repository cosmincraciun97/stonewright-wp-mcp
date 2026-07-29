<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Sandbox;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\CodePayloadCanonicalizer;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class SandboxWrite extends AbilityKernel {

	use SandboxGuards;

	public function name(): string {
		return 'stonewright/sandbox-write';
	}

	public function label(): string {
		return __( 'Write sandbox file', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates or overwrites a sandbox draft file with the given PHP contents. Destructive — requires confirmation_token in production-safe mode.', 'stonewright' );
	}

	public function category(): string {
		return 'sandbox';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'name'               => [
					'type'    => 'string',
					'pattern' => '^[a-z0-9_-]+\\.php$',
				],
				'contents'           => [
					'type' => 'string',
				],
				'confirmation_token' => [
					'type' => 'string',
				],
				'decode_escaped_layout' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Opt in to conservative decoding of escaped layout outside PHP strings and comments.',
				],
			],
			'required'             => [ 'name', 'contents' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'   => [ 'type' => 'boolean' ],
				'name' => [ 'type' => 'string' ],
			],
			'required'   => [ 'ok', 'name' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_sandbox();
	}

	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'contents' ] );
	}

	public function execute( array $args ): array|\WP_Error {
		$args = CodePayloadCanonicalizer::canonicalize( $this->name(), $args );
		if ( $args instanceof \WP_Error ) {
			return $args;
		}

		return $this->audit(
			$args,
			function ( array $a ): array|\WP_Error {
				$file_mods_error = $this->file_mods_disabled_error();
				if ( null !== $file_mods_error ) {
					return $file_mods_error;
				}

				$verify = $a;
				unset( $verify['confirmation_token'] );
				$token_error = $this->production_safe_token_error(
					$a,
					$verify
				);
				if ( null !== $token_error ) {
					return $token_error;
				}

				$result = SandboxFiles::write( $a['name'], $a['contents'] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return $this->ok( [ 'name' => $a['name'] ] );
			}
		);
	}
}
