<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support;

/** Thrown by the exit seam of the rescue runtime, so a test can go on after the runtime "exits". */
final class MuStop extends \RuntimeException {}
