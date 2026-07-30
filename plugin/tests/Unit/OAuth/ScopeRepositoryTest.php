<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from tests/oauth/ScopeRepositoryTest.php
// Source SHA-256: 28ca06d55e0d92f597e5e2a7ebc009bda166dc212ed9c82d78b9c69347587176

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Repositories\ScopeEntity;
use Stonewright\WpMcp\OAuth\Repositories\ScopeRepository;

final class ScopeRepositoryTest extends TestCase {

	public function test_accepts_supported_scopes_only(): void {
		$repository = new ScopeRepository();

		self::assertNotNull( $repository->getScopeEntityByIdentifier( 'mcp' ) );
		self::assertNotNull( $repository->getScopeEntityByIdentifier( 'read' ) );
		self::assertNotNull( $repository->getScopeEntityByIdentifier( 'write' ) );
		self::assertNotNull( $repository->getScopeEntityByIdentifier( 'offline_access' ) );
		self::assertNull( $repository->getScopeEntityByIdentifier( 'admin' ) );
		self::assertNull( $repository->getScopeEntityByIdentifier( '' ) );
	}

	public function test_finalize_always_grants_mcp_and_preserves_offline_access(): void {
		$repository = new ScopeRepository();
		$client     = $this->createMock( ClientEntityInterface::class );

		self::assertContains(
			'mcp',
			$this->ids(
				$repository->finalizeScopes(
					[ $this->scope( 'read' ), $this->scope( 'write' ) ],
					'authorization_code',
					$client
				)
			)
		);
		self::assertSame(
			[ 'mcp', 'offline_access' ],
			$this->ids(
				$repository->finalizeScopes(
					[ $this->scope( 'mcp' ), $this->scope( 'offline_access' ) ],
					'authorization_code',
					$client
				)
			)
		);
		self::assertSame(
			[ 'mcp' ],
			$this->ids( $repository->finalizeScopes( [], 'authorization_code', $client ) )
		);
	}

	private function scope( string $identifier ): ScopeEntity {
		$entity = new ScopeEntity();
		$entity->setIdentifier( $identifier );
		return $entity;
	}

	/**
	 * @param array<array-key, ScopeEntityInterface> $scopes Scopes.
	 * @return list<string>
	 */
	private function ids( array $scopes ): array {
		return array_values(
			array_map(
				static fn( ScopeEntityInterface $scope ): string => $scope->getIdentifier(),
				$scopes
			)
		);
	}
}
