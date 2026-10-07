<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support;

use Stonewright\WpMcp\Cli\RescueCommand;

/** The rescue command with its WP-CLI output replaced by a recorder. */
final class RecordingRescueCommand extends RescueCommand {

	/** @var list<string> */
	public array $lines = [];

	/** @var list<string> */
	public array $successes = [];

	/** @var list<string> */
	public array $warnings = [];

	/** @var list<array{rows: list<array<string, mixed>>, fields: list<string>, format: string}> */
	public array $tables = [];

	protected function line( string $text ): void {
		$this->lines[] = $text;
	}

	protected function success( string $text ): void {
		$this->successes[] = $text;
	}

	protected function warning( string $text ): void {
		$this->warnings[] = $text;
	}

	protected function fail( string $text, int $code = 1 ): never {
		throw new CliExit( $text, $code );
	}

	protected function items( array $rows, array $fields, string $format ): void {
		$this->tables[] = [ 'rows' => $rows, 'fields' => $fields, 'format' => $format ];
	}

	/** Everything the command printed, as one string. */
	public function printed(): string {
		return implode( "\n", array_merge( $this->lines, $this->successes, $this->warnings ) ) . json_encode( $this->tables );
	}
}
