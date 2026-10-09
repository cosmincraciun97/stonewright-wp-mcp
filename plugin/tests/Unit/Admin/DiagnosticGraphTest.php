<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stonewright\WpMcp\Admin\Diagnostics\DiagnosticCheck;
use Stonewright\WpMcp\Admin\Diagnostics\DiagnosticGraph;

/**
 * @covers \Stonewright\WpMcp\Admin\Diagnostics\DiagnosticGraph
 * @covers \Stonewright\WpMcp\Admin\Diagnostics\DiagnosticCheck
 */
final class DiagnosticGraphTest extends TestCase {

	public function test_failed_prerequisite_skips_dependents_and_counts_problems(): void {
		$graph = new DiagnosticGraph();
		$graph->add( 'endpoint', [], static fn() => DiagnosticCheck::problem( 'endpoint', 'Endpoint', 'Missing route', 'Restore the route.' ), 'MCP endpoint' );
		$graph->add( 'oauth_metadata', [ 'endpoint' ], static fn() => DiagnosticCheck::ok( 'oauth_metadata', 'OAuth metadata', 'Metadata loaded.' ), 'OAuth metadata' );
		$graph->add( 'registration', [ 'oauth_metadata' ], static fn() => DiagnosticCheck::ok( 'registration', 'Registration', 'Registration passed.' ), 'Registration' );
		$result = $graph->run();
		self::assertSame( 'problem', $result['checks'][0]['status'] );
		self::assertSame( 'skipped', $result['checks'][1]['status'] );
		self::assertSame( 'skipped', $result['checks'][2]['status'] );
		self::assertSame( 1, $result['counts']['problem'] );
		self::assertSame( 2, $result['counts']['skipped'] );
		self::assertSame( 0, $result['counts']['ok'] );
		self::assertSame( 'Skipped: needs MCP endpoint to pass first.', $result['checks'][1]['summary'] );
		self::assertSame( 'Skipped: needs OAuth metadata to pass first.', $result['checks'][2]['summary'] );
		self::assertSame( 'OAuth metadata', $result['checks'][1]['label'], 'A skipped check is shown under its label, not its id.' );
		self::assertSame( 'Registration', $result['checks'][2]['label'] );
		self::assertSame( [ 'endpoint' ], $result['checks'][1]['depends_on'] );
		self::assertSame( [ 'oauth_metadata' ], $result['checks'][2]['depends_on'] );
	}

	public function test_a_skipped_check_names_every_failed_prerequisite_in_words(): void {
		$graph = new DiagnosticGraph();
		$graph->add( 'plugin', [], static fn() => DiagnosticCheck::problem( 'plugin', 'Plugin', 'Off', 'Turn on.' ), 'Stonewright abilities' );
		$graph->add( 'mcp_runtime', [], static fn() => DiagnosticCheck::problem( 'mcp_runtime', 'Runtime', 'Blocked', 'Fix.' ), 'MCP runtime' );
		$graph->add( 'transport', [], static fn() => DiagnosticCheck::ok( 'transport', 'Transport', 'Fine.' ), 'Transport' );
		$graph->add( 'connection', [ 'plugin', 'mcp_runtime', 'transport' ], static fn() => DiagnosticCheck::ok( 'connection', 'Connection', 'Fine.' ), 'Connection' );
		$result = $graph->run();

		$connection = $result['checks'][3];
		self::assertSame( 'skipped', $connection['status'] );
		self::assertSame( 'Skipped: needs Stonewright abilities and MCP runtime to pass first.', $connection['summary'] );
		self::assertSame( [ 'plugin', 'mcp_runtime', 'transport' ], $connection['depends_on'], 'The ids stay available to tools.' );
	}

	public function test_a_node_without_a_label_is_spelled_out_not_shown_as_its_id(): void {
		$graph = new DiagnosticGraph();
		$graph->add( 'mcp_runtime', [], static fn() => DiagnosticCheck::problem( 'mcp_runtime', 'Runtime', 'Blocked', 'Fix.' ) );
		$graph->add( 'tool_surface', [ 'mcp_runtime' ], static fn() => DiagnosticCheck::ok( 'tool_surface', 'Tool surface', 'Fine.' ) );
		$result = $graph->run();

		self::assertSame( 'Tool surface', $result['checks'][1]['label'] );
		self::assertSame( 'Skipped: needs Mcp runtime to pass first.', $result['checks'][1]['summary'] );
	}

	public function test_skipped_callbacks_are_not_invoked(): void {
		$ran = [];
		$graph = new DiagnosticGraph();
		$graph->add(
			'endpoint',
			[],
			static function () use ( &$ran ) {
				$ran[] = 'endpoint';
				return DiagnosticCheck::problem( 'endpoint', 'Endpoint', 'Missing route', 'Restore the route.' );
			}
		);
		$graph->add(
			'oauth_metadata',
			[ 'endpoint' ],
			static function () use ( &$ran ) {
				$ran[] = 'oauth_metadata';
				return DiagnosticCheck::ok( 'oauth_metadata', 'OAuth metadata', 'Metadata loaded.' );
			}
		);
		$graph->run();
		self::assertSame( [ 'endpoint' ], $ran );
	}

	public function test_duplicate_id_is_rejected(): void {
		$graph = new DiagnosticGraph();
		$graph->add( 'endpoint', [], static fn() => DiagnosticCheck::ok( 'endpoint', 'Endpoint', 'OK' ) );

		$this->expectException( InvalidArgumentException::class );
		$graph->add( 'endpoint', [], static fn() => DiagnosticCheck::ok( 'endpoint', 'Endpoint', 'OK' ) );
	}

	public function test_unknown_dependency_is_rejected(): void {
		$graph = new DiagnosticGraph();
		$graph->add( 'registration', [ 'oauth_metadata' ], static fn() => DiagnosticCheck::ok( 'registration', 'Registration', 'OK' ) );

		$this->expectException( InvalidArgumentException::class );
		$graph->run();
	}

	public function test_cycle_is_rejected(): void {
		$graph = new DiagnosticGraph();
		$graph->add( 'a', [ 'b' ], static fn() => DiagnosticCheck::ok( 'a', 'A', 'OK' ) );
		$graph->add( 'b', [ 'a' ], static fn() => DiagnosticCheck::ok( 'b', 'B', 'OK' ) );

		$this->expectException( InvalidArgumentException::class );
		$graph->run();
	}

	public function test_throwing_callback_is_problem_with_error_class_only(): void {
		$graph = new DiagnosticGraph();
		$graph->add(
			'boom',
			[],
			static function () {
				throw new RuntimeException( 'secret diagnostic failure' );
			}
		);
		$result = $graph->run();
		$encoded = (string) wp_json_encode( $result );

		self::assertSame( 'problem', $result['checks'][0]['status'] );
		self::assertSame( 'error', $result['checks'][0]['severity'] );
		self::assertSame( RuntimeException::class, $result['checks'][0]['evidence']['error_class'] );
		self::assertSame( 1, $result['counts']['problem'] );
		self::assertStringNotContainsString( 'secret diagnostic failure', $encoded );
	}

	public function test_nested_evidence_is_rejected_and_classified_as_problem(): void {
		$graph = new DiagnosticGraph();
		$graph->add(
			'nested',
			[],
			static function () {
				return DiagnosticCheck::ok(
					'nested',
					'Nested',
					'Should not leak nested evidence.',
					[ 'child' => [ 'token' => 'secret-nested-token' ] ]
				);
			}
		);
		$result = $graph->run();
		$encoded = (string) wp_json_encode( $result );

		self::assertSame( 'problem', $result['checks'][0]['status'] );
		self::assertSame( InvalidArgumentException::class, $result['checks'][0]['evidence']['error_class'] );
		self::assertStringNotContainsString( 'secret-nested-token', $encoded );
	}

	public function test_unknown_status_and_severity_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		DiagnosticCheck::from_array(
			[
				'id'          => 'endpoint',
				'scope'       => 'oauth-http',
				'status'      => 'nope',
				'severity'    => 'critical',
				'label'       => 'Endpoint',
				'summary'     => 'Bad status.',
				'evidence'    => [],
				'remedy'      => '',
				'action'      => null,
				'copy'        => null,
				'depends_on'  => [],
				'duration_ms' => 0,
			]
		);
	}

	public function test_oversized_strings_are_truncated_at_construction(): void {
		$check = DiagnosticCheck::ok( 'endpoint', str_repeat( 'L', 5000 ), str_repeat( 'S', 8000 ) );
		$array = $check->to_array();

		self::assertSame( 'endpoint', $array['id'] );
		self::assertSame( 'ok', $array['status'] );
		self::assertLessThanOrEqual( 200, strlen( (string) $array['label'] ) );
		self::assertLessThanOrEqual( 500, strlen( (string) $array['summary'] ) );
	}

	public function test_execution_order_is_deterministic_topological_insertion(): void {
		$graph = new DiagnosticGraph();
		$graph->add( 'c', [ 'a' ], static fn() => DiagnosticCheck::ok( 'c', 'C', 'C ok' ) );
		$graph->add( 'a', [], static fn() => DiagnosticCheck::ok( 'a', 'A', 'A ok' ) );
		$graph->add( 'b', [ 'a' ], static fn() => DiagnosticCheck::ok( 'b', 'B', 'B ok' ) );

		$ids = array_column( $graph->run()['checks'], 'id' );
		self::assertSame( [ 'a', 'c', 'b' ], $ids );
	}

	public function test_each_node_runs_once(): void {
		$runs = [];
		$graph = new DiagnosticGraph();
		$graph->add(
			'plugin',
			[],
			static function () use ( &$runs ) {
				$runs[] = 'plugin';
				return DiagnosticCheck::ok( 'plugin', 'Plugin', 'Enabled.' );
			}
		);
		$graph->add(
			'endpoint',
			[ 'plugin' ],
			static function () use ( &$runs ) {
				$runs[] = 'endpoint';
				return DiagnosticCheck::ok( 'endpoint', 'Endpoint', 'Present.' );
			}
		);
		$graph->add(
			'probe',
			[ 'endpoint' ],
			static function () use ( &$runs ) {
				$runs[] = 'probe';
				return DiagnosticCheck::ok( 'probe', 'Probe', 'Passed.' );
			}
		);

		$result = $graph->run();
		self::assertSame( [ 'plugin', 'endpoint', 'probe' ], $runs );
		self::assertSame( [ 'plugin', 'endpoint', 'probe' ], array_column( $result['checks'], 'id' ) );
		self::assertSame( 3, $result['counts']['ok'] );
	}

	public function test_factories_return_the_normalized_shape(): void {
		$check = DiagnosticCheck::problem(
			'endpoint',
			'MCP endpoint',
			'The endpoint returned HTTP 404.',
			'Restore the MCP route, then run diagnostics again.',
			'oauth-http',
			[ 'http_status' => 404, 'duration_ms' => 43 ]
		);
		$array = $check->to_array();

		self::assertSame(
			[
				'id'          => 'endpoint',
				'scope'       => 'oauth-http',
				'status'      => 'problem',
				'severity'    => 'error',
				'label'       => 'MCP endpoint',
				'summary'     => 'The endpoint returned HTTP 404.',
				'evidence'    => [
					'http_status' => 404,
					'duration_ms' => 43,
				],
				'remedy'      => 'Restore the MCP route, then run diagnostics again.',
				'action'      => null,
				'copy'        => null,
				'depends_on'  => [],
				'duration_ms' => 43,
			],
			$array
		);
	}

	public function test_info_checks_are_counted_separately_from_successful_ok(): void {
		$graph = new DiagnosticGraph();
		$graph->add( 'endpoint', [], static fn() => DiagnosticCheck::info( 'endpoint', 'Endpoint', 'Configured URL only.' ) );
		$graph->add( 'runtime', [], static fn() => DiagnosticCheck::ok( 'runtime', 'Runtime', 'Compatible.' ) );
		$graph->add( 'conflict', [], static fn() => DiagnosticCheck::problem( 'conflict', 'Conflict', 'Blocked.', 'Install a compatible adapter.' ) );
		$result = $graph->run();

		self::assertSame( 1, $result['counts']['info'] );
		self::assertSame( 1, $result['counts']['ok'] );
		self::assertSame( 1, $result['counts']['problem'] );
	}
}
