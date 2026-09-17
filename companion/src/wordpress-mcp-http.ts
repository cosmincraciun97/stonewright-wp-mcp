/**
 * Bounded, redacted WordPress MCP HTTP evidence.
 *
 * Never attach Authorization, cookies, or a full response body to this error.
 */

import type { PluginRouteState } from './connection/status-contract.js';

export type WordPressMcpBodyKind = 'wp_rest_error' | 'json_rpc' | 'html' | 'empty' | 'unknown';

export class WordPressMcpHttpError extends Error {
	constructor(
		message: string,
		readonly status: number,
		readonly restCode: string | null,
		readonly bodyKind: WordPressMcpBodyKind,
		readonly checkedAt: string,
		readonly truncated = false,
	) {
		super(message);
		this.name = 'WordPressMcpHttpError';
	}
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
