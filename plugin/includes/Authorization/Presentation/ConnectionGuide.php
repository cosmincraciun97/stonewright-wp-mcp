<?php
/**
 * OAuth connection guide data.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Presentation;

use Stonewright\WpMcp\Authorization\Protocol\TransportPolicy;

/** Returns data only; a future admin adapter escapes and renders it for the chosen surface. */
final class ConnectionGuide {

	public function __construct( private ?TransportPolicy $transport = null ) {
		$this->transport ??= new TransportPolicy();
	}

	public function describe( string $client, string $server_name, string $mcp_url, bool $authorization_available ): array {
		if ( ! in_array( $client, [ 'codex', 'generic-mcp' ], true ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $server_name ) ) {
			throw new \InvalidArgumentException( 'Invalid connection guide selection.' );
		}
		$this->transport->require_secure( $mcp_url );
		$guide = [ 'status' => 'unavailable', 'configuration' => [], 'command_arguments' => [], 'steps' => [ 'Authorization is currently unavailable. Check the site connection settings.' ], 'first_tool' => 'stonewright-task-start', 'documentation_url' => 'codex' === $client ? 'https://developers.openai.com/codex/mcp' : 'https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization' ];
		if ( ! $authorization_available ) {
			return $guide;
		}
		$guide['steps'] = [ 'Add the site as a Streamable HTTP MCP server.', 'Start OAuth sign-in in the client and approve the requested site access.', 'After sign-in, call stonewright-task-start and verify the required tools are available.' ];
		if ( 'codex' === $client ) {
			$guide['status'] = 'sign-in-required';
			$guide['configuration'] = [ 'mcp_servers' => [ $server_name => [ 'url' => $mcp_url ] ] ];
			$guide['command_arguments'] = [ [ 'codex', 'mcp', 'add', $server_name, '--url', $mcp_url ], [ 'codex', 'mcp', 'login', $server_name ] ];
		} else {
			$guide['status'] = 'client-support-required';
			$guide['configuration'] = [ 'server_name' => $server_name, 'transport' => 'streamable-http', 'url' => $mcp_url, 'authentication' => 'oauth' ];
		}
		return $guide;
	}
}
