<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures;

/** A statement named a table the fake database does not have. */
final class FakeTableMissing extends \RuntimeException {
}
