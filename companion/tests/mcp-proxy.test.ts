/**
 * Tests for mcp-proxy configuration loading and path-safety.
 *
 * Item 4: Verify that getProxyConfig() honours env vars set AFTER module import
 *         (lazy memoisation).
 * S5:     Verify that handleProxy rejects path-traversal in req.url.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import type { IncomingMessage, ServerResponse } from 'node:http';
import { proxyConfig, getProxyConfig, _resetProxyConfigCache } from '../src/mcp-proxy.js';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function fakeReq(url: string, method = 'POST'): IncomingMessage {
	return {
		url,
		method,
		headers: { 'content-type': 'application/json', 'accept': 'application/json' },
		socket: { remoteAddress: '127.0.0.1' },
		on(_: string, __: () => void) { return this; },
	} as unknown as IncomingMessage;
}

function fakeRes(): ServerResponse & { statusCode: number; _body: string } {
	const res = {
		headersSent: false,
		statusCode: 200,
		_body: '',
		_headers: {} as Record<string, string>,
		writeHead(status: number, headers?: Record<string, string>) {
			this.statusCode = status;
			if (headers) Object.assign(this._headers, headers);
		},
		end(body?: string) { this._body = body ?? ''; },
		setHeader(k: string, v: string) { this._headers[k] = v; },
		write(_: Buffer) { /* noop */ },
		once(_: string, __: () => void) { return this; },
	};
	return res as unknown as ServerResponse & { statusCode: number; _body: string };
}

// ---------------------------------------------------------------------------
// Module-level proxyConfig (loaded at import time)
// ---------------------------------------------------------------------------

describe('proxyConfig', () => {
	it('is null when MCP_PROXY_TARGET is not set', () => {
		// In the test environment this env var should not be set.
		if (process.env['MCP_PROXY_TARGET']) {
			// If someone ran tests with the var set, just verify it has a target.
			expect(proxyConfig).not.toBeNull();
			expect(proxyConfig?.target).toBe(process.env['MCP_PROXY_TARGET']?.replace(/\/$/, ''));
		} else {
			expect(proxyConfig).toBeNull();
		}
	});
});

// ---------------------------------------------------------------------------
// Item 4: getProxyConfig() lazy memoisation — honours env vars set after import
// ---------------------------------------------------------------------------

describe('getProxyConfig lazy getter', () => {
	const savedTarget = process.env['MCP_PROXY_TARGET'];

	beforeEach(() => {
		// Reset the memoised cache before each test so env changes are picked up.
		_resetProxyConfigCache();
		delete process.env['MCP_PROXY_TARGET'];
		delete process.env['MCP_PROXY_TOKEN'];
	});

	afterEach(() => {
		_resetProxyConfigCache();
		if (savedTarget !== undefined) {
			process.env['MCP_PROXY_TARGET'] = savedTarget;
		} else {
			delete process.env['MCP_PROXY_TARGET'];
		}
	});

	it('returns null when MCP_PROXY_TARGET is absent', () => {
		// env var is absent (set in beforeEach)
		expect(getProxyConfig()).toBeNull();
	});

	it('honours MCP_PROXY_TARGET set after module import', () => {
		// Simulate env var injection that happens after the module was imported.
		// Without lazy loading this would still be null.
		process.env['MCP_PROXY_TARGET'] = 'https://mcp.example.com';
		process.env['MCP_PROXY_TOKEN'] = 'secret';
		const cfg = getProxyConfig();
		expect(cfg).not.toBeNull();
		expect(cfg?.target).toBe('https://mcp.example.com');
		expect(cfg?.token).toBe('secret');
	});

	it('strips trailing slash from target', () => {
		process.env['MCP_PROXY_TARGET'] = 'https://mcp.example.com/';
		const cfg = getProxyConfig();
		expect(cfg?.target).toBe('https://mcp.example.com');
	});

	it('memoises: second call returns same object without re-reading env', () => {
		process.env['MCP_PROXY_TARGET'] = 'https://first.example.com';
		const first = getProxyConfig();
		// Change env after first call — memoisation should ignore this.
		process.env['MCP_PROXY_TARGET'] = 'https://second.example.com';
		const second = getProxyConfig();
		expect(second).toBe(first); // same reference
		expect(second?.target).toBe('https://first.example.com');
	});
});

// ---------------------------------------------------------------------------
// S5: handleProxy path-traversal guard
// ---------------------------------------------------------------------------

describe('handleProxy path traversal guard', () => {
	beforeEach(() => {
		_resetProxyConfigCache();
		// Set a proxy target so the handler doesn't 503 immediately.
		process.env['MCP_PROXY_TARGET'] = 'https://mcp.example.com';
		process.env['MCP_PROXY_TOKEN'] = 'tok';
	});

	afterEach(() => {
		_resetProxyConfigCache();
		delete process.env['MCP_PROXY_TARGET'];
		delete process.env['MCP_PROXY_TOKEN'];
	});

	it('rejects URLs containing .. segments (400)', async () => {
		// Import handleProxy dynamically so we get the real implementation.
		const { handleProxy } = await import('../src/mcp-proxy.js');
		const req = fakeReq('/proxy/../../../etc/passwd');
		const res = fakeRes();
		await handleProxy(req, res, Buffer.from('{}'));
		expect(res.statusCode).toBe(400);
		expect(res._body).toContain('Path traversal');
	});

	it('accepts a clean path without .. segments', async () => {
		// This test does NOT make a real upstream request — handleProxy will try
		// to fetch and fail with a network error (502). That's acceptable because
		// the point here is to verify the 400 guard does NOT trigger for clean paths.
		const { handleProxy } = await import('../src/mcp-proxy.js');
		const req = fakeReq('/proxy/mcp');
		const res = fakeRes();
		// We expect either a 502 (network error, no real upstream) or a non-400 status.
		await handleProxy(req, res, Buffer.from('{}'));
		expect(res.statusCode).not.toBe(400);
	});

	it('rejects URLs with encoded .. segments after normalisation', async () => {
		const { handleProxy } = await import('../src/mcp-proxy.js');
		// %2F..%2F — the raw path still contains literal '..' between slashes, so
		// the regex guard catches it and returns 400 before any URL normalisation.
		const req = fakeReq('/proxy/%2F..%2Fetc%2Fpasswd');
		const res = fakeRes();
		await handleProxy(req, res, Buffer.from('{}'));
		expect(res.statusCode).toBe(400);
	});

	it('rejects URLs with mixed-case percent-encoded .. segments (bypass attempt)', async () => {
		const { handleProxy } = await import('../src/mcp-proxy.js');
		// Mixed-case %2E%2e bypassed the old two-literal-string checks.
		// The case-insensitive toLowerCase().includes('%2e%2e') guard catches it.
		const req = fakeReq('/proxy/%2E%2e/etc/passwd');
		const res = fakeRes();
		await handleProxy(req, res, Buffer.from('{}'));
		expect(res.statusCode).toBe(400);
	});
});
