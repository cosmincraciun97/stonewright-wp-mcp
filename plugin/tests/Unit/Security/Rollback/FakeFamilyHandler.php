<?php
/**
 * A rollback handler for a family that has no adapter in this branch, to prove that a handler plugs into the registry.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Rollback;

use Stonewright\WpMcp\Security\Rollback\RollbackFamilyHandler;

/**
 * The resource is one array of fields kept in memory. Its image is { fields: ... }.
 */
final class FakeFamilyHandler implements RollbackFamilyHandler {

	/** @var array<string, mixed> */
	public array $state = [];

	/** @var list<array{id:string,image:mixed}> */
	public array $calls = [];

	/** @var (callable():void)|null Runs in the middle of every restore. */
	public $during = null;

	public string $status = 'succeeded';

	public function __construct( private string $family = 'user', private bool $human = false ) {}

	public function families(): array {
		return [ $this->family ];
	}

	public function live_image( array $row ): string|array|\WP_Error|null {
		return [ 'fields' => $this->state ];
	}

	public function restore( array $row, string|array|null $image, array $options ): array {
		$this->calls[] = [ 'id' => (string) $row['change_id'], 'image' => $image ];
		if ( null !== $this->during ) {
			( $this->during )();
		}
		if ( 'succeeded' === $this->status ) {
			$this->state = is_array( $image ) && is_array( $image['fields'] ?? null ) ? $image['fields'] : [];
		}
		return [ 'status' => $this->status, 'detail' => 'succeeded' === $this->status ? '' : 'fake_failure' ];
	}

	public function describe( array $row, string|array|null $image ): string {
		return 'Writes the fields back.';
	}

	public function records_own_row( array $row ): bool {
		return false;
	}

	public function requires_human( array $row ): bool {
		return $this->human;
	}
}
