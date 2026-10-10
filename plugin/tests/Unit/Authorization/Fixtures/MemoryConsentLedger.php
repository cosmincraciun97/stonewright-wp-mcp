<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Ports\ConsentLedger;

final class MemoryConsentLedger implements ConsentLedger {
	public ?\Closure $before_commit = null;
	public bool $fail_before_commit = false;
	public array $codes = [];

	public function __construct( public array $pending ) {}

	public function change( string $pending_key, callable $decide ): array {
		if ( $pending_key !== $this->pending['pending_key'] ) {
			throw new OAuthFault( 'invalid_request' );
		}
		$outcome = $decide( $this->pending );
		$hook = $this->before_commit;
		$this->before_commit = null;
		if ( null !== $hook ) {
			$hook();
			$outcome = $decide( $this->pending );
		}
		if ( $this->fail_before_commit ) {
			throw new \RuntimeException( 'Synthetic commit failure.' );
		}
		if ( null !== $outcome['code'] ) {
			$key = $outcome['code']['code_key'];
			if ( isset( $this->codes[ $key ] ) ) {
				throw new \RuntimeException( 'Synthetic code collision.' );
			}
			$this->codes[ $key ] = $outcome['code'];
		}
		$this->pending = $outcome['pending'];
		return $outcome;
	}
}
