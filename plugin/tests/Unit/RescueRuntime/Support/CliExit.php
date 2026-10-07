<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support;

/** Thrown where WP-CLI would end the command. */
final class CliExit extends \RuntimeException {}
