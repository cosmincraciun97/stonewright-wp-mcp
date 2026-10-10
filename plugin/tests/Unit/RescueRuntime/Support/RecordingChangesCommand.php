<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support;

use Stonewright\WpMcp\Cli\ChangesCommand;

/** The changes command with its WP-CLI output replaced by a recorder. */
final class RecordingChangesCommand extends ChangesCommand {

	/** @var list<string> */
	public array $lines = [];

	/** @var list<string> */
	public array $successes = [];

	/** @var list<string> */
	public array $warnings = [];

	/** @var list<string> The questions the command asked a person to confirm. */
	public array $questions = [];

	/** @var list<array{rows: list<array<string, mixed>>, fields: list<string>, format: string}> */
	public array $tables = [];

	/** Whether the person answers no. */
	public bool $decline = false;

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

	/** WP-CLI's confirm() asks unless --yes was given, and ends the command on no. */
	protected function confirm( string $question, array $assoc_args ): void {
		if ( ! empty( $assoc_args['yes'] ) ) {
			return;
		}
		$this->questions[] = $question;
		if ( $this->decline ) {
			throw new CliExit( 'Aborted', 1 );
		}
	}

	/** Everything the command printed, as one string. */
	public function printed(): string {
		return implode( "\n", array_merge( $this->lines, $this->successes, $this->warnings ) ) . json_encode( $this->tables );
	}
}
