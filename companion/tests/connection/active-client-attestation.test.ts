import { describe, expect, it } from 'vitest';
import {
	REQUIRED_ACTIVE_HOST_CALLS,
	requiredActiveHostCallSucceeded,
} from '../../src/connection/active-client-attestation.js';

describe('required active-host call result gate', () => {
	it('accepts every required call only with exact success, schema 2, and non-error content', () => {
		for (const name of REQUIRED_ACTIVE_HOST_CALLS) {
			expect(requiredActiveHostCallSucceeded(name, {
				ok: true,
				schema_version: 2,
				isError: false,
				content: [{ type: 'text', text: 'verified' }],
			})).toBe(true);
		}
	});

	it('counts a required call with ok false when schema 2 and content are not errors', () => {
		for (const name of REQUIRED_ACTIVE_HOST_CALLS) {
			expect(requiredActiveHostCallSucceeded(name, {
				ok: false,
				schema_version: 2,
				isError: false,
				content: [{ type: 'text', text: 'relist required' }],
				error_code: 'client_catalog_relist_required',
			})).toBe(true);
		}
	});

	it.each([
		['missing ok', { schema_version: 2 }],
		['truthy ok', { ok: 'true', schema_version: 2 }],
		['missing schema', { ok: true }],
		['string schema', { ok: true, schema_version: '2' }],
		['MCP isError', { ok: true, schema_version: 2, isError: true }],
		['error content', { ok: true, schema_version: 2, content: [{ type: 'text', text: 'failed', isError: true }] }],
		['malformed content', { ok: true, schema_version: 2, content: {} }],
	])('rejects %s', (_label, result) => {
		expect(requiredActiveHostCallSucceeded('stonewright-task-start', result)).toBe(false);
	});
});
