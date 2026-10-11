<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Support;

use PHPUnit\Framework\MockObject\Generator;
use Stonewright\WpMcp\Abilities\Ability;
use Stonewright\WpMcp\Context\ContextToken;
use Stonewright\WpMcp\Core\AbilityRegistry;
use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;

/**
 * Runs one Stonewright ability the way an MCP client does: the registered execute callback behind the
 * bundled adapter's tools/call handler. The adapter turns an ability error into the text of an error
 * result, so this returns what the client would receive.
 */
final class McpToolCall {

	/**
	 * @param array<string, mixed> $arguments
	 * @return array{is_error: bool, text: string}
	 */
	public static function call( Ability $ability, array $arguments ): array {
		$tool = McpTool::fromArray(
			[
				'name'       => 'test-tool',
				'handler'    => AbilityRegistry::registration_args( $ability )['execute_callback'],
				'permission' => [ $ability, 'permission_callback' ],
			]
		);
		if ( $tool instanceof \WP_Error ) {
			throw new \RuntimeException( $tool->get_error_message() );
		}

		$generator = new Generator();
		$handler   = $generator->getMock( McpErrorHandlerInterface::class );
		$server    = $generator->getMock( McpServer::class, [ 'get_mcp_tool', 'get_error_handler' ], [], '', false );
		$server->method( 'get_mcp_tool' )->willReturn( $tool );
		$server->method( 'get_error_handler' )->willReturn( $handler );

		$issued = ContextToken::issue( 'Test task', $ability->name() );
		$arguments['stonewright_context_token'] = (string) ( $issued['token'] ?? '' );

		$result = ( new ToolsHandler( $server ) )->call_tool( [ 'name' => 'test-tool', 'arguments' => $arguments ] );
		$array  = is_array( $result ) ? $result : $result->toArray();
		$text   = '';
		foreach ( (array) ( $array['content'] ?? [] ) as $block ) {
			if ( is_array( $block ) && isset( $block['text'] ) ) {
				$text .= (string) $block['text'];
			}
		}

		return [
			'is_error' => true === ( $array['isError'] ?? false ),
			'text'     => $text,
		];
	}
}
