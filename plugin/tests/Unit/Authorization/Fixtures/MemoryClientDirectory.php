<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures;

use Stonewright\WpMcp\Authorization\Ports\ClientDirectory;

final class MemoryClientDirectory implements ClientDirectory {
	public ?array $accepted = null;
	public bool $fail = false;

	public function find( string $client_key ): ?array {
		return 'assigned-client-a' === $client_key && null !== $this->accepted ? [ 'client_id' => $client_key ] + $this->accepted : null;
	}

	public function create( array $accepted_profile ): array {
		if ( $this->fail ) {
			throw new \RuntimeException( 'Synthetic directory failure.' );
		}
		$this->accepted = $accepted_profile;
		return [ 'client_id' => 'assigned-client-a', 'unrelated_private_field' => 'synthetic-private-marker' ] + $accepted_profile;
	}
}
