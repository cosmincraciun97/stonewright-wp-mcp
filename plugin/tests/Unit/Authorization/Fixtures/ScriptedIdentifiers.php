<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures;

use Stonewright\WpMcp\Authorization\Ports\IdentifierSource;

final class ScriptedIdentifiers implements IdentifierSource {
	private int $number = 0;
	public function next(): string {
		return 'synthetic-' . ++$this->number;
	}
}
