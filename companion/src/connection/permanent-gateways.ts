/**
 * Permanent local gateway tools.
 *
 * Registered by the companion BEFORE any remote handshake and never removed by
 * profiles, advisory filters, or reconnect failures. Local gateway owns the
 * name when the remote plugin exposes the same canonical tool.
 */

export const PERMANENT_GATEWAY_TOOL_NAMES = [
	'stonewright-task-start',
	'stonewright-connect-doctor',
	'stonewright-wordpress-mcp-status',
	'stonewright-setup-profile',
	'stonewright-mode-capabilities',
	'stonewright-tool-profile',
	'stonewright-client-surface-check',
	'stonewright-reconnect',
	'stonewright-ping',
] as const;

export type PermanentGatewayToolName = (typeof PERMANENT_GATEWAY_TOOL_NAMES)[number];

export const PERMANENT_GATEWAY_TOOL_NAME_SET = new Set<string>(PERMANENT_GATEWAY_TOOL_NAMES);

export function isPermanentGatewayTool(name: string): boolean {
	return PERMANENT_GATEWAY_TOOL_NAME_SET.has(name);
}

/** Gateway tools that are always client-callable once the companion process is up. */
export function permanentGatewayMembership(name: string): boolean {
	return isPermanentGatewayTool(name);
}
