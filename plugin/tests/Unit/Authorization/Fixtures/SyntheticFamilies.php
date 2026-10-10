<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures;

use Stonewright\WpMcp\Authorization\Model\FamilyState;

final class SyntheticFamilies {

	public static function initial( array $changes = [] ): FamilyState {
		return FamilyState::from_array( array_replace( [
			'family_key' => 'family-a', 'client_key' => 'client-a', 'subject_key' => 'subject-a',
			'consented_scopes' => [ 'mcp', 'read' ], 'consented_resources' => [ 'https://example.test/mcp' ],
			'family_deadline' => 1701209600, 'phase' => 'active', 'current_refresh_key' => 'refresh-0', 'revision' => 0,
			'credential_history' => [ 'refresh-0' => [ 'expires_at' => 1701209600, 'consumed' => false, 'successor_key' => null ] ],
		], $changes ) );
	}
}
