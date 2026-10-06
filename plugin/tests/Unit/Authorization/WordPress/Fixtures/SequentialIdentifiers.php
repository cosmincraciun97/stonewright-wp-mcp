<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures;

use Stonewright\WpMcp\Authorization\Ports\IdentifierSource;

/** Predictable identifiers in the production shape (80 lowercase hex characters). */
final class SequentialIdentifiers implements IdentifierSource {

	private int $number = 0;

	public function __construct( private string $prefix = 'a' ) {}

	public function next(): string {
		++$this->number;
		return $this->prefix . str_pad( dechex( $this->number ), 79, '0', STR_PAD_LEFT );
	}
}
