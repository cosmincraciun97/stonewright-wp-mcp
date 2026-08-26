/**
 * Safe, phase-aware transport failure classification for plugin MCP traffic.
 *
 * Never attach request bodies, authorization headers, URL queries, response
 * token bodies, or raw nested Error.cause text to diagnostics or logs.
 */

export type TransportErrorKind =
	| 'dns_error'
	| 'tls_error'
	| 'timeout'
	| 'connection_refused'
	| 'connection_reset'
	| 'http_error'
	| 'auth_error'
	| 'plugin_route_missing'
	| 'unknown_transport_error';

export type TransportPhase =
	| 'endpoint_head'
	| 'endpoint_get'
	| 'initialize'
	| 'tools_list'
	| 'task_start'
	| 'tool_call';

export interface TransportDiagnostic {
	kind: TransportErrorKind;
	phase: TransportPhase;
	safe_cause_code: string | null;
	http_status: number | null;
	attempt: number;
	duration_ms: number;
	retryable: boolean;
	request_may_have_reached_server: boolean;
}

export interface TransportFailureContext {
	phase: TransportPhase;
	attempt: number;
	startedAt: number;
	now?: () => number;
	httpStatus?: number | null;
}

/** Allowlisted Node/undici cause codes that are safe to surface. */
const SAFE_CAUSE_CODES = new Set([
	'ENOTFOUND',
	'EAI_AGAIN',
	'CERT_HAS_EXPIRED',
	'UNABLE_TO_VERIFY_LEAF_SIGNATURE',
	'ERR_TLS_CERT_ALTNAME_INVALID',
	'DEPTH_ZERO_SELF_SIGNED_CERT',
	'SELF_SIGNED_CERT_IN_CHAIN',
	'ECONNREFUSED',
	'ECONNRESET',
	'EPIPE',
	'EHOSTUNREACH',
	'ENETUNREACH',
	'ETIMEDOUT',
	'UND_ERR_CONNECT_TIMEOUT',
	'UND_ERR_HEADERS_TIMEOUT',
	'UND_ERR_BODY_TIMEOUT',
	'UND_ERR_SOCKET',
	'ABORT_ERR',
	'TimeoutError',
]);

const DNS_CODES = new Set(['ENOTFOUND', 'EAI_AGAIN']);
const TLS_CODES = new Set([
	'CERT_HAS_EXPIRED',
	'UNABLE_TO_VERIFY_LEAF_SIGNATURE',
	'ERR_TLS_CERT_ALTNAME_INVALID',
	'DEPTH_ZERO_SELF_SIGNED_CERT',
	'SELF_SIGNED_CERT_IN_CHAIN',
]);
const REFUSED_CODES = new Set(['ECONNREFUSED', 'EHOSTUNREACH', 'ENETUNREACH']);
const RESET_CODES = new Set(['ECONNRESET', 'EPIPE', 'UND_ERR_SOCKET']);
const TIMEOUT_CODES = new Set([
	'ETIMEDOUT',
	'UND_ERR_CONNECT_TIMEOUT',
	'UND_ERR_HEADERS_TIMEOUT',
	'UND_ERR_BODY_TIMEOUT',
	'TimeoutError',
	'ABORT_ERR',
]);

export class PluginTransportError extends Error {
	constructor(
		message: string,
		readonly diagnostic: TransportDiagnostic,
	) {
		super(message);
		this.name = 'PluginTransportError';
	}
}

function extractSafeCauseCode(error: unknown): string | null {
	const candidates: unknown[] = [error];
	if (error && typeof error === 'object') {
		const withCause = error as { cause?: unknown; code?: unknown; name?: unknown };
		if (withCause.cause !== undefined) candidates.push(withCause.cause);
		if (typeof withCause.code === 'string') candidates.push(withCause.code);
		if (typeof withCause.name === 'string') candidates.push(withCause.name);
		if (withCause.cause && typeof withCause.cause === 'object') {
			const nested = withCause.cause as { code?: unknown; name?: unknown };
			if (typeof nested.code === 'string') candidates.push(nested.code);
			if (typeof nested.name === 'string') candidates.push(nested.name);
		}
	}

	for (const candidate of candidates) {
		if (typeof candidate === 'string' && SAFE_CAUSE_CODES.has(candidate)) {
			return candidate;
		}
		if (candidate && typeof candidate === 'object') {
			const code = (candidate as { code?: unknown }).code;
			if (typeof code === 'string' && SAFE_CAUSE_CODES.has(code)) {
				return code;
			}
			const name = (candidate as { name?: unknown }).name;
			if (typeof name === 'string' && SAFE_CAUSE_CODES.has(name)) {
				return name;
			}
		}
	}

	if (error && typeof error === 'object' && (error as { name?: string }).name === 'AbortError') {
		return 'ABORT_ERR';
	}

	return null;
}

function kindFromCauseCode(code: string | null): TransportErrorKind {
	if (!code) return 'unknown_transport_error';
	if (DNS_CODES.has(code)) return 'dns_error';
	if (TLS_CODES.has(code)) return 'tls_error';
	if (REFUSED_CODES.has(code)) return 'connection_refused';
	if (RESET_CODES.has(code)) return 'connection_reset';
	if (TIMEOUT_CODES.has(code)) return 'timeout';
	return 'unknown_transport_error';
}

function mayHaveReachedServer(kind: TransportErrorKind): boolean {
	return kind === 'connection_reset' || kind === 'timeout' || kind === 'http_error' || kind === 'auth_error' || kind === 'plugin_route_missing';
}

function isRetryable(kind: TransportErrorKind): boolean {
	return kind === 'timeout' || kind === 'connection_reset' || kind === 'dns_error' || kind === 'connection_refused';
}

export function classifyTransportFailure(
	error: unknown,
	context: TransportFailureContext,
): TransportDiagnostic {
	const now = context.now ?? Date.now;
	const safeCode = extractSafeCauseCode(error);
	const kind = kindFromCauseCode(safeCode);
	return {
		kind,
		phase: context.phase,
		safe_cause_code: safeCode,
		http_status: context.httpStatus ?? null,
		attempt: context.attempt,
		duration_ms: Math.max(0, now() - context.startedAt),
		retryable: isRetryable(kind),
		request_may_have_reached_server: mayHaveReachedServer(kind),
	};
}

export function classifyHttpStatus(
	status: number,
	context: Omit<TransportFailureContext, 'httpStatus'>,
): TransportDiagnostic {
	const now = context.now ?? Date.now;
	let kind: TransportErrorKind = 'http_error';
	if (status === 401 || status === 403) kind = 'auth_error';
	else if (status === 404) kind = 'plugin_route_missing';

	return {
		kind,
		phase: context.phase,
		safe_cause_code: null,
		http_status: status,
		attempt: context.attempt,
		duration_ms: Math.max(0, now() - context.startedAt),
		retryable: status >= 500 && status < 600,
		request_may_have_reached_server: true,
	};
}
