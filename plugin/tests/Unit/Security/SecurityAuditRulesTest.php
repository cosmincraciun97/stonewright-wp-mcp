<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'sw_find_unsafe_assert_calls' ) ) {
	require_once dirname( __DIR__, 3 ) . '/bin/security-audit-rules.php';
}

final class SecurityAuditRulesTest extends TestCase {

	private string $dir;

	/** @var array<int, string> */
	private array $files = [];

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/sw-assert-audit-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir, 0700 );
	}

	protected function tearDown(): void {
		foreach ( $this->files as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
	}

	public function test_reports_language_assert_calls_and_ignores_methods_and_declarations(): void {
		$source = <<<'PHP'
<?php
assert($payload);
assert('code');
assert("double");
\assert($payload);
CssGrantGate::assert($value);
$gate->assert($value);
$gate?->assert($value);
function assert($x) {}
class Gate {
	public function assert($x) {}
	public function run() {
		assert($still_flagged);
	}
}
assert(true);
assert(1);
assert(some_func());
PHP;
		$path = $this->write_fixture( 'fixture.php', $source );
		$hits = \sw_find_unsafe_assert_calls( [ $path ] );
		$by_line = [];
		foreach ( $hits as $hit ) {
			$by_line[ $hit['line'] ] = $hit;
		}

		self::assertSame(
			[ 2, 3, 4, 5, 13 ],
			array_column( $hits, 'line' )
		);

		self::assertSame( $path, $by_line[2]['file'] );
		self::assertSame( 'assert($payload);', $by_line[2]['text'] );
		self::assertSame( "assert('code');", $by_line[3]['text'] );
		self::assertSame( 'assert("double");', $by_line[4]['text'] );
		self::assertSame( '\assert($payload);', $by_line[5]['text'] );
		self::assertSame( 'assert($still_flagged);', $by_line[13]['text'] );

		self::assertArrayNotHasKey( 6, $by_line );
		self::assertArrayNotHasKey( 7, $by_line );
		self::assertArrayNotHasKey( 8, $by_line );
		self::assertArrayNotHasKey( 9, $by_line );
		self::assertArrayNotHasKey( 11, $by_line );
		self::assertArrayNotHasKey( 16, $by_line );
		self::assertArrayNotHasKey( 17, $by_line );
		self::assertArrayNotHasKey( 18, $by_line );
	}

	public function test_reports_interpolated_and_heredoc_strings_and_ignores_new_assert(): void {
		$source = <<<'PHP'
<?php
assert("$payload");
assert(<<<'EOD'
code
EOD);
new assert($payload);
PHP;
		$path = $this->write_fixture( 'strings.php', $source );
		$hits = \sw_find_unsafe_assert_calls( [ $path ] );
		$by_line = [];
		foreach ( $hits as $hit ) {
			$by_line[ $hit['line'] ] = $hit;
		}

		self::assertSame( [ 2, 3 ], array_column( $hits, 'line' ) );
		self::assertSame( 'assert("$payload");', $by_line[2]['text'] );
		self::assertSame( "assert(<<<'EOD'", $by_line[3]['text'] );
		self::assertArrayNotHasKey( 6, $by_line );
	}

	public function test_skip_basenames_skips_matching_files(): void {
		$skipped = $this->write_fixture( 'StaticGuard.php', "<?php\nassert(\$payload);\n" );
		$kept    = $this->write_fixture( 'Keep.php', "<?php\nassert(\$payload);\n" );

		$hits = \sw_find_unsafe_assert_calls(
			[ $skipped, $kept ],
			[ 'StaticGuard.php' ]
		);

		self::assertCount( 1, $hits );
		self::assertSame( $kept, $hits[0]['file'] );
		self::assertSame( 2, $hits[0]['line'] );
		self::assertSame( 'assert($payload);', $hits[0]['text'] );
	}

	private function write_fixture( string $basename, string $source ): string {
		$path = $this->dir . '/' . $basename;
		file_put_contents( $path, $source );
		$this->files[] = $path;
		return $path;
	}
}
