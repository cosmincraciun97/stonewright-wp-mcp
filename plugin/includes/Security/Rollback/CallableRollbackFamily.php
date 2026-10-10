<?php
/**
 * A rollback handler made of a restore function, for adapters that restore a row through one entry point.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Rollback;

/**
 * Wraps `restore( string $change_id, array $options ): array` where the result holds status, detail, limits and
 * rollback_change_id, and the adapter writes its own rollback row (chained through parent_id). The engine keeps every
 * gate. Without a live-image function the engine cannot show a diff or tell drift for these families, and says so.
 *
 * Registering is one call:
 *
 *     RollbackFamilies::register( new CallableRollbackFamily( [ 'user', 'media' ], $restore ) );
 */
final class CallableRollbackFamily implements RollbackFamilyHandler {

	/** @var callable(string, array<string, mixed>):array<string, mixed> */
	private $restore;

	/** @var (callable(array<string, mixed>):(string|array<mixed>|\WP_Error|null))|null */
	private $live_image;

	/**
	 * @param list<string>                                                                $families
	 * @param callable(string, array<string, mixed>):array<string, mixed>                 $restore    The adapter's restore function.
	 * @param (callable(array<string, mixed>):(string|array<mixed>|\WP_Error|null))|null  $live_image Reads the live state of the row's resource, or null when it cannot.
	 * @param bool                                                                        $human      Whether the families need a person at wp-admin.
	 */
	public function __construct( private array $families, callable $restore, ?callable $live_image = null, private bool $human = false, private string $description = '' ) {
		$this->restore    = $restore;
		$this->live_image = $live_image;
	}

	public function families(): array {
		return $this->families;
	}

	public function live_image( array $row ): string|array|\WP_Error|null {
		if ( null === $this->live_image ) {
			return new \WP_Error( self::LIVE_UNSUPPORTED, 'The live state of this family cannot be read.' );
		}
		return ( $this->live_image )( $row );
	}

	public function restore( array $row, string|array|null $image, array $options ): array {
		$pass = [
			'kind'      => 'redo' === ( $options['kind'] ?? '' ) ? 'redo' : 'rollback',
			'permanent' => ! empty( $options['permanent'] ),
			'actor'     => (int) ( $options['actor'] ?? 0 ),
		];
		if ( isset( $options['expected_current_sha256'] ) && '' !== (string) $options['expected_current_sha256'] ) {
			$pass['expected_current_sha256'] = (string) $options['expected_current_sha256'];
		}
		$result = ( $this->restore )( (string) $row['change_id'], $pass );
		return [
			'status'             => (string) ( $result['status'] ?? 'failed' ),
			'detail'             => (string) ( $result['detail'] ?? '' ),
			'limits'             => array_values( array_filter( array_map( 'strval', (array) ( $result['limits'] ?? [] ) ), static fn ( string $limit ): bool => '' !== $limit ) ),
			'rollback_change_id' => isset( $result['rollback_change_id'] ) && is_string( $result['rollback_change_id'] ) && '' !== $result['rollback_change_id'] ? $result['rollback_change_id'] : null,
		];
	}

	public function describe( array $row, string|array|null $image ): string {
		return '' !== $this->description ? $this->description : __( 'Restores the item through its own adapter.', 'stonewright' );
	}

	public function records_own_row( array $row ): bool {
		return true;
	}

	public function requires_human( array $row ): bool {
		return $this->human;
	}
}
