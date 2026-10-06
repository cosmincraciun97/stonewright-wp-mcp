<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures;

use Stonewright\WpMcp\Authorization\Ports\Clock;

final class ScriptedClock implements Clock {
	public function __construct( private array $times ) {}
	public function now(): int {
		return count( $this->times ) > 1 ? array_shift( $this->times ) : $this->times[0];
	}
	public function set( int $time ): void {
		$this->times = [ $time ];
	}
}
