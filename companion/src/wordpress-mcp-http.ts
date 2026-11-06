/**
 * Bounded, redacted WordPress MCP HTTP evidence.
 *
 * Never attach Authorization, cookies, or a full response body to this error.
 */

import type { PluginRouteState } from './connection/status-contract.js';
import type { TransportDiagnostic } from './connection/transport-diagnostic.js';

export type WordPressMcpBodyKind = 'wp_rest_error' | 'json_rpc' | 'html' | 'empty' | 'unknown';

export class WordPressMcpHttpError extends Error {
	constructor(
		message: string,
		readonly status: number,
		readonly restCode: string | null,
		readonly bodyKind: WordPressMcpBodyKind,
		readonly checkedAt: string,
		readonly truncated = false,
		/** Set when the failure is a body over the read cap: the safe, phase-aware diagnostic for it. */
		readonly diagnostic: TransportDiagnostic | null = null,
	) {
		super(message);
		this.name = 'WordPressMcpHttpError';
	}
}

/** The handshake step a {@link WordPressMcpHandshakeError} failed at. */
export type WordPressMcpHandshakeStep = 'protocol_version' | 'initialized_notification';

/**
 * The plugin route answered `initialize`, but the MCP handshake could not be completed: the
 * server chose a protocol version this companion does not speak, or it refused the
 * `notifications/initialized` that follows. The route and the credentials work, so this is
 * not a missing plugin; it usually means the MCP adapter that serves the route (possibly
 * another plugin's copy) applies protocol rules this companion does not meet.
 */
export class WordPressMcpHandshakeError extends WordPressMcpHttpError {
	constructor(
		message: string,
		status: number,
		restCode: string | null,
		bodyKind: WordPressMcpBodyKind,
		checkedAt: string,
		readonly step: WordPressMcpHandshakeStep,
		readonly negotiatedVersion: string | null,
	) {
		super(message, status, restCode, bodyKind, checkedAt);
		this.name = 'WordPressMcpHandshakeError';
	}
}

export interface HandshakeFailure {
	code: 'plugin_handshake_rejected' | 'protocol_version_unsupported';
	nextAction: string;
}

const HANDSHAKE_FAILURES: Record<WordPressMcpHandshakeStep, HandshakeFailure> = {
	initialized_notification: {
		code: 'plugin_handshake_rejected',
		nextAction: 'The Stonewright MCP route answered initialize but refused the rest of the MCP handshake, so the plugin is installed and the credentials work. The MCP adapter serving the route may come from another active plugin with stricter protocol rules: open Stonewright > Troubleshoot > MCP runtime to see which plugin supplies it, update the plugin and the companion to the same latest release, then call stonewright-reconnect with force_probe=true.',
	},
	protocol_version: {
		code: 'protocol_version_unsupported',
		nextAction: 'The MCP adapter serving the Stonewright route chose a protocol version this companion does not speak. Update the companion to the latest release (the same version as the plugin), then call stonewright-reconnect with force_probe=true. Stonewright > Troubleshoot > MCP runtime shows which plugin supplies the adapter.',
	},
};

/** Error codes a failed MCP handshake reports, for status consumers such as the doctor. */
export const HANDSHAKE_FAILURE_CODES: ReadonlySet<string> = new Set(
	Object.values(HANDSHAKE_FAILURES).map((failure) => failure.code),
);

/** The status error code and next action for a handshake the plugin route could not finish. */
export function handshakeFailure(error: unknown): HandshakeFailure | null {
	return error instanceof WordPressMcpHandshakeError ? HANDSHAKE_FAILURES[error.step] : null;
}

export interface BoundedBodyResult {
	text: string;
	truncated: boolean;
}

export interface McpHttpClassification {
	status: number;
	present: boolean | null;
	route_state: PluginRouteState;
	body_kind: WordPressMcpBodyKind;
	rest_code: string | null;
}

const DEFAULT_BODY_LIMIT = 4096;

function concatUint8(chunks: readonly Uint8Array[]): Uint8Array {
	const total = chunks.reduce((sum, chunk) => sum + chunk.byteLength, 0);
	const out = new Uint8Array(total);
	let offset = 0;
	for (const chunk of chunks) {
		out.set(chunk, offset);
		offset += chunk.byteLength;
	}
	return out;
}

/**
 * Read at most `limit` bytes from the response stream. Overflow cancels the
 * reader and marks the probe truncated so a sliced REST index cannot look like
 * a confirmed missing plugin route.
 */
export async function readBoundedBody(
	response: Response,
	limit = DEFAULT_BODY_LIMIT,
): Promise<BoundedBodyResult> {
	const decoder = new TextDecoder();
	const reader = response.body?.getReader();
	if (!reader) {
		return { text: '', truncated: false };
	}

	const chunks: Uint8Array[] = [];
	let received = 0;
	let done = false;
	try {
		while (!done) {
			const raw: unknown = await reader.read();
			if (!raw || typeof raw !== 'object') {
				break;
			}
			const record = raw as { done?: unknown; value?: unknown };
			done = record.done === true;
			const value = record.value instanceof Uint8Array ? record.value : null;
			if (done || value === null || value.byteLength === 0) {
				continue;
			}
			if (received + value.byteLength > limit) {
				const remaining = Math.max(0, limit - received);
				if (remaining > 0) {
					chunks.push(value.subarray(0, remaining));
				}
				try {
					await reader.cancel();
				} catch {
					// Cancel is best-effort once the byte cap is hit.
				}
				return { text: decoder.decode(concatUint8(chunks)), truncated: true };
			}
			chunks.push(value);
			received += value.byteLength;
		}
	} catch {
		return { text: decoder.decode(concatUint8(chunks)), truncated: received > limit };
	}

	return { text: decoder.decode(concatUint8(chunks)), truncated: false };
}

export function isHtmlPayload(contentType: string, body: string): boolean {
	if (/text\/html|application\/xhtml\+xml/i.test(contentType)) {
		return true;
	}
	const trimmed = body.trimStart().slice(0, 64).toLowerCase();
	return trimmed.startsWith('<!doctype html') || trimmed.startsWith('<html');
}

export function parseJsonObject(body: string): Record<string, unknown> | null {
	const trimmed = body.trim();
	if (!trimmed.startsWith('{') && !trimmed.startsWith('[')) {
		return null;
	}
	try {
		const parsed: unknown = JSON.parse(trimmed);
		return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
			? parsed as Record<string, unknown>
			: null;
	} catch {
		return null;
	}
}

export function classifyMcpHttpPayload(
	status: number,
	contentType: string,
	body: string,
	truncated = false,
): McpHttpClassification {
	if (truncated) {
		return {
			status,
			present: null,
			route_state: 'inconclusive',
			body_kind: 'unknown',
			rest_code: null,
		};
	}

	const json = parseJsonObject(body);
	const restCode = typeof json?.code === 'string' ? json.code : null;
	const html = isHtmlPayload(contentType, body);
	const jsonRpc = json !== null && (
		json.jsonrpc === '2.0'
		|| 'result' in json
		|| ('error' in json && 'id' in json)
	);
	const bodyKind: WordPressMcpBodyKind = json && restCode
		? 'wp_rest_error'
		: jsonRpc
			? 'json_rpc'
			: html
				? 'html'
				: (body === '' ? 'empty' : (json ? 'wp_rest_error' : 'unknown'));

	if (status === 401 || status === 403 || status === 405) {
		return {
			status,
			present: true,
			route_state: 'present',
			body_kind: json ? 'wp_rest_error' : (html ? 'html' : (body === '' ? 'empty' : 'unknown')),
			rest_code: restCode,
		};
	}

	if (status === 200) {
		if (json && restCode === 'rest_no_route') {
			return {
				status,
				present: false,
				route_state: 'missing',
				body_kind: 'wp_rest_error',
				rest_code: 'rest_no_route',
			};
		}
		if (jsonRpc) {
			return {
				status,
				present: true,
				route_state: 'present',
				body_kind: 'json_rpc',
				rest_code: restCode,
			};
		}
		if (html) {
			return {
				status,
				present: null,
				route_state: 'inconclusive',
				body_kind: 'html',
				rest_code: null,
			};
		}
		return {
			status,
			present: true,
			route_state: 'present',
			body_kind: bodyKind,
			rest_code: restCode,
		};
	}

	if (status === 404) {
		if (json && restCode === 'rest_no_route') {
			return {
				status: 404,
				present: false,
				route_state: 'missing',
				body_kind: 'wp_rest_error',
				rest_code: 'rest_no_route',
			};
		}
		if (html) {
			return {
				status: 404,
				present: null,
				route_state: 'inconclusive',
				body_kind: 'html',
				rest_code: null,
			};
		}
		return {
			status: 404,
			present: false,
			route_state: 'missing',
			body_kind: json ? 'wp_rest_error' : (body === '' ? 'empty' : 'unknown'),
			rest_code: restCode,
		};
	}

	if (status >= 500) {
		return {
			status,
			present: null,
			route_state: 'inconclusive',
			body_kind: json ? 'wp_rest_error' : (html ? 'html' : (body === '' ? 'empty' : 'unknown')),
			rest_code: restCode,
		};
	}

	return {
		status,
		present: true,
		route_state: 'present',
		body_kind: json ? 'wp_rest_error' : (html ? 'html' : (body === '' ? 'empty' : 'unknown')),
		rest_code: restCode,
	};
}

export function isConfirmedPluginRouteAbsence(error: unknown): error is WordPressMcpHttpError {
	return error instanceof WordPressMcpHttpError
		&& error.truncated === false
		&& error.bodyKind === 'wp_rest_error'
		&& error.restCode === 'rest_no_route';
}

export function isWordPressMcpAuthFailure(error: unknown): error is WordPressMcpHttpError {
	return error instanceof WordPressMcpHttpError
		&& (error.status === 401 || error.status === 403);
}
