<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures;

use Stonewright\WpMcp\Authorization\Ports\SubjectAuthority;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;

final class SyntheticSubjectAuthority implements SubjectAuthority {
	public function __construct( private bool $allowed = true ) {}
	public function require_allowed( string $subject_key, string $operation, array $context ): void {
		if ( ! $this->allowed ) {
			throw new OAuthFault( 'access_denied', 403 );
		}
	}
}
