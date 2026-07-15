import type { ResolvedSite } from './sites-config.js';

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export interface RequestOpts {
	query?: Record<string, string | number | boolean | undefined | null>;
	body?: unknown;
	headers?: Record<string, string>;
	/** When true, body is sent as-is (FormData / Buffer) without JSON encoding. */
	rawBody?: boolean;
}

export interface WpRestClientOptions {
	fetchImpl?: typeof fetch | undefined;
	timeoutMs?: number | undefined;
}

export class WpRestError extends Error {
	readonly code: string;
	readonly status: number;
	readonly hint: string;
	readonly details?: unknown;

	constructor(args: { code: string; status: number; message: string; hint: string; details?: unknown }) {
		super(args.message);
		this.name = 'WpRestError';
		this.code = args.code;
		this.status = args.status;
		this.hint = args.hint;
		this.details = args.details;
	}

	static async from(response: Response): Promise<WpRestError> {
		let payload: unknown = null;
		const text = await response.text();
		if (text) {
			try {
				payload = JSON.parse(text) as unknown;
			} catch {
				payload = text;
			}
		}

		const obj = payload && typeof payload === 'object' ? (payload as Record<string, unknown>) : {};
		const code = typeof obj.code === 'string' ? obj.code : `http_${response.status}`;
		const message =
			typeof obj.message === 'string' && obj.message.trim()
				? obj.message
				: `WordPress REST request failed with HTTP ${response.status}`;

		let hint = 'Check the request path, authentication, and WordPress REST availability.';
		if (response.status === 401) {
			hint = 'Verify Application Password credentials (username + app password).';
		} else if (response.status === 403) {
			hint = 'Authenticated user lacks the required capability for this operation.';
		} else if (response.status === 404) {
			hint = 'Route not found. If this is a core /wp/v2 route, check permalinks and that REST is enabled.';
		}

		return new WpRestError({
			code,
			status: response.status,
			message,
			hint,
			details: payload,
		});
	}

	toJSON(): Record<string, unknown> {
		return {
			code: this.code,
			status: this.status,
			message: this.message,
			hint: this.hint,
		};
	}
}

function isRetryableNetworkError(err: unknown): boolean {
	if (!err || typeof err !== 'object') {
		return false;
	}
	const code = 'code' in err ? String((err as { code?: unknown }).code ?? '') : '';
	if (code === 'ECONNRESET' || code === 'ETIMEDOUT' || code === 'ECONNREFUSED') {
		return true;
	}
	const message = err instanceof Error ? err.message : String(err);
	return /ECONNRESET|socket hang up|network/i.test(message);
}

async function fetchWithTimeout(
	fetchImpl: typeof fetch,
	url: string,
	init: RequestInit,
	timeoutMs: number,
): Promise<Response> {
	const controller = new AbortController();
	const timer = setTimeout(() => controller.abort(), timeoutMs);
	try {
		return await fetchImpl(url, { ...init, signal: controller.signal });
	} finally {
		clearTimeout(timer);
	}
}

export class WpRestClient {
	private readonly fetchImpl: typeof fetch;
	private readonly timeoutMs: number;

	constructor(
		private readonly site: ResolvedSite,
		options: WpRestClientOptions = {},
	) {
		this.fetchImpl = options.fetchImpl ?? fetch;
		this.timeoutMs = options.timeoutMs ?? 30_000;
	}

	get siteAlias(): string {
		return this.site.alias;
	}

	get restBase(): string {
		return this.site.restBase;
	}

	async get<T>(path: string, opts: RequestOpts = {}): Promise<T> {
		return this.request<T>('GET', path, opts);
	}

	async post<T>(path: string, opts: RequestOpts = {}): Promise<T> {
		return this.request<T>('POST', path, opts);
	}

	async put<T>(path: string, opts: RequestOpts = {}): Promise<T> {
		return this.request<T>('PUT', path, opts);
	}

	async del<T>(path: string, opts: RequestOpts = {}): Promise<T> {
		return this.request<T>('DELETE', path, opts);
	}

	async request<T>(method: HttpMethod, path: string, opts: RequestOpts = {}): Promise<T> {
		const normalizedPath = path.startsWith('/') ? path : `/${path}`;
		const url = new URL(`${this.site.restBase}${normalizedPath}`);
		for (const [key, value] of Object.entries(opts.query ?? {})) {
			if (value === undefined || value === null) {
				continue;
			}
			url.searchParams.set(key, String(value));
		}

		const headers: Record<string, string> = {
			authorization: `Basic ${Buffer.from(`${this.site.username}:${this.site.appPassword}`).toString('base64')}`,
			accept: 'application/json',
			...(opts.headers ?? {}),
		};

		let body: string | FormData | Blob | ArrayBuffer | Uint8Array | undefined;
		if (opts.body !== undefined) {
			if (opts.rawBody) {
				body = opts.body as string | FormData | Blob | ArrayBuffer | Uint8Array;
			} else {
				headers['content-type'] = headers['content-type'] ?? 'application/json';
				body = JSON.stringify(opts.body);
			}
		}

		const init: RequestInit = body === undefined
			? { method, headers }
			: { method, headers, body: body as never };
		let lastError: unknown;

		for (let attempt = 0; attempt < 2; attempt += 1) {
			try {
				const response = await fetchWithTimeout(this.fetchImpl, url.toString(), init, this.timeoutMs);
				if (!response.ok) {
					throw await WpRestError.from(response);
				}
				if (response.status === 204) {
					return undefined as T;
				}
				const text = await response.text();
				if (!text) {
					return undefined as T;
				}
				return JSON.parse(text) as T;
			} catch (err) {
				lastError = err;
				if (err instanceof WpRestError) {
					throw err;
				}
				if (attempt === 0 && isRetryableNetworkError(err)) {
					continue;
				}
				throw err;
			}
		}

		throw lastError instanceof Error ? lastError : new Error(String(lastError));
	}
}
