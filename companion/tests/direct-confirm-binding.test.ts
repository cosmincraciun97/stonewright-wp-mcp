import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { markTaskStartSeen, resetTaskStartSeenForTests } from '../src/direct/writes.js';
import { assertWriteAllowed, DirectSafetyBlockedError, legacyConfirmAccepted } from '../src/direct/writes.js';
import { runWithDirectCallContext } from '../src/direct/plugin-presence.js';
import {
	CONFIRMATION_TTL_MS,
	canonicalConfirmationArgs,
	consumeConfirmation,
	issueConfirmation,
	resetConfirmationsForTests,
} from '../src/direct/confirm-token.js';

const REMOTE = { alias: 'remote', url: 'https://example.com' };
const OTHER = { alias: 'other', url: 'https://other.example.com' };
const LOCAL = { alias: 'local', url: 'http://example.test' };
const TOOL = 'stonewright-plugin-delete';

type Attempt = {
	tool?: string;
	site?: { alias: string; url: string };
	args?: Record<string, unknown>;
	confirm?: boolean;
	confirmationId?: string;
	env?: NodeJS.ProcessEnv;
	now?: number;
	mode?: 'on' | 'confirm';
	alwaysConfirm?: boolean;
};

/** One write attempt as the registry makes it: the call carries its arguments and an optional confirmation_id. */
function attempt(a: Attempt = {}): void {
	const tool = a.tool ?? TOOL;
	runWithDirectCallContext(
		{
			tool,
			confirm: a.confirm === true,
			args: a.args ?? { plugin: 'akismet/akismet' },
			...(a.confirmationId !== undefined ? { confirmationId: a.confirmationId } : {}),
		},
		() =>
			assertWriteAllowed({
				mode: a.mode ?? 'confirm',
				destructive: true,
				tool,
				site: a.site ?? REMOTE,
				env: a.env ?? {},
				...(a.confirm !== undefined ? { confirm: a.confirm } : {}),
				...(a.now !== undefined ? { now: a.now } : {}),
				...(a.alwaysConfirm !== undefined ? { alwaysConfirm: a.alwaysConfirm } : {}),
			}),
	);
}

function blockedBy(fn: () => void): DirectSafetyBlockedError {
	try {
		fn();
	} catch (error) {
		if (error instanceof DirectSafetyBlockedError) return error;
		throw error;
	}
	throw new Error('expected the call to be refused');
}

function issuedId(a: Attempt = {}): string {
	const error = blockedBy(() => attempt(a));
	expect(error.code).toBe('confirmation_required');
	const id = error.details['confirmation_id'];
	expect(typeof id).toBe('string');
	return id as string;
}

describe('args-bound confirmation', () => {
	beforeEach(() => {
		for (const site of [REMOTE, OTHER, LOCAL]) markTaskStartSeen(site);
		resetConfirmationsForTests();
	});
	afterEach(() => {
		resetTaskStartSeenForTests();
		resetConfirmationsForTests();
	});

	it('a destructive call without a confirmation returns an id, a summary and the lifetime', () => {
		const error = blockedBy(() => attempt());
		expect(error.code).toBe('confirmation_required');
		expect(error.details['confirmation_id']).toMatch(/^[0-9a-f]{32}$/);
		expect(error.details['expires_in_seconds']).toBe(CONFIRMATION_TTL_MS / 1000);
		expect(String(error.details['summary'])).toContain(TOOL);
		expect(String(error.details['summary'])).toContain('remote');
		expect(String(error.details['summary'])).toContain('akismet/akismet');
		expect(error.message).toContain('confirmation_id');
		// The id travels in its own field, not in the text that goes to the audit trail.
		expect(error.message).not.toContain(String(error.details['confirmation_id']));
	});

	it('the summary shortens long values and never repeats a secret', () => {
		// Built at run time: a plain value that only has to be recognisable in the output.
		const marker = ['marker', 'value', 'one'].join('-');
		const error = blockedBy(() =>
			attempt({ args: { username: 'ada', password: marker, content: 'x'.repeat(500), data: [1, 2, 3], app_token: marker } }),
		);
		const summary = String(error.details['summary']);
		expect(summary).toContain('username="ada"');
		expect(summary).toContain('data=[3 items]');
		expect(summary).toContain('password=[hidden]');
		expect(summary).toContain('app_token=[hidden]');
		expect(summary).not.toContain(marker);
		expect(summary).not.toContain('x'.repeat(100));
	});

	it('the same call repeated with the id is allowed once', () => {
		const id = issuedId();
		expect(() => attempt({ confirmationId: id })).not.toThrow();
		// Single use.
		const again = blockedBy(() => attempt({ confirmationId: id }));
		expect(again.code).toBe('confirmation_invalid');
		expect(again.details['reason']).toBe('unknown');
	});

	it('is not valid for other arguments', () => {
		const id = issuedId();
		const changed = blockedBy(() => attempt({ confirmationId: id, args: { plugin: 'hello-dolly/hello' } }));
		expect(changed.code).toBe('confirmation_invalid');
		expect(changed.details['reason']).toBe('different_call');
		// A refused attempt does not burn the id for the call it was issued for.
		expect(() => attempt({ confirmationId: id })).not.toThrow();
	});

	it('is not valid for another site', () => {
		const id = issuedId();
		const moved = blockedBy(() => attempt({ confirmationId: id, site: OTHER }));
		expect(moved.code).toBe('confirmation_invalid');
		expect(moved.details['reason']).toBe('different_call');
		expect(() => attempt({ confirmationId: id })).not.toThrow();
	});

	it('is not valid for another tool', () => {
		const id = issuedId();
		const swapped = blockedBy(() => attempt({ confirmationId: id, tool: 'stonewright-plugin-deactivate' }));
		expect(swapped.code).toBe('confirmation_invalid');
		expect(swapped.details['reason']).toBe('different_call');
		expect(() => attempt({ confirmationId: id })).not.toThrow();
	});

	it('expires after ten minutes', () => {
		const t0 = 1_700_000_000_000;
		const id = issuedId({ now: t0 });
		const late = blockedBy(() => attempt({ confirmationId: id, now: t0 + CONFIRMATION_TTL_MS + 1 }));
		expect(late.code).toBe('confirmation_invalid');
		expect(late.details['reason']).toBe('expired');
		// Right at the limit it still works.
		const id2 = issuedId({ now: t0 });
		expect(() => attempt({ confirmationId: id2, now: t0 + CONFIRMATION_TTL_MS })).not.toThrow();
	});

	it('an invented id is refused and a fresh one is offered', () => {
		const invented = blockedBy(() => attempt({ confirmationId: 'f'.repeat(32) }));
		expect(invented.code).toBe('confirmation_invalid');
		expect(invented.details['reason']).toBe('unknown');
		expect(invented.details['confirmation_id']).toMatch(/^[0-9a-f]{32}$/);
	});

	it('does not depend on key order, nor on confirm, confirmation_id or site inside the arguments', () => {
		const id = issuedId({ args: { b: { y: 2, x: 1 }, a: [1, 2] } });
		expect(() =>
			attempt({
				confirmationId: id,
				args: { a: [1, 2], site: 'remote', confirm: true, confirmation_id: id, b: { x: 1, y: 2 } },
			}),
		).not.toThrow();
		expect(canonicalConfirmationArgs({ site: 's', confirm: true, confirmation_id: 'x', a: 1 })).toBe(canonicalConfirmationArgs({ a: 1 }));
	});

	it('a nested change in the arguments changes the binding', () => {
		const id = issuedId({ args: { data: { a: { b: 1 } } } });
		const changed = blockedBy(() => attempt({ confirmationId: id, args: { data: { a: { b: 2 } } } }));
		expect(changed.code).toBe('confirmation_invalid');
	});

	it('issuing and consuming work on the module directly', () => {
		const binding = { tool: TOOL, site: 'remote', args: { id: 1 } };
		const issued = issueConfirmation(binding, 1000);
		expect(consumeConfirmation(issued.id, { ...binding, args: { id: 2 } }, 1001)).toEqual({ ok: false, reason: 'different_call' });
		expect(consumeConfirmation(issued.id, binding, 1001)).toEqual({ ok: true });
		expect(consumeConfirmation(issued.id, binding, 1002)).toEqual({ ok: false, reason: 'unknown' });
	});
});

describe('plain confirm:true', () => {
	beforeEach(() => {
		for (const site of [REMOTE, LOCAL]) markTaskStartSeen(site);
		resetConfirmationsForTests();
	});
	afterEach(() => {
		resetTaskStartSeenForTests();
		resetConfirmationsForTests();
	});

	it('stays accepted on a local .test site', () => {
		expect(() => attempt({ site: LOCAL, confirm: true })).not.toThrow();
	});

	it('is not accepted on a remote site: it returns an id and says why', () => {
		const error = blockedBy(() => attempt({ confirm: true }));
		expect(error.code).toBe('confirmation_required');
		expect(error.message).toMatch(/confirm:true/);
		expect(error.message).toMatch(/no longer accepted/i);
		expect(error.details['confirmation_id']).toMatch(/^[0-9a-f]{32}$/);
	});

	it('is accepted on a remote site only with STONEWRIGHT_DIRECT_CONFIRM_LEGACY=on', () => {
		expect(() => attempt({ confirm: true, env: { STONEWRIGHT_DIRECT_CONFIRM_LEGACY: 'on' } })).not.toThrow();
	});

	it('STONEWRIGHT_DIRECT_CONFIRM_LEGACY=off refuses it on a local site too', () => {
		const error = blockedBy(() => attempt({ site: LOCAL, confirm: true, env: { STONEWRIGHT_DIRECT_CONFIRM_LEGACY: 'off' } }));
		expect(error.code).toBe('confirmation_required');
		expect(() => attempt({ site: LOCAL, confirmationId: error.details['confirmation_id'] as string, env: { STONEWRIGHT_DIRECT_CONFIRM_LEGACY: 'off' } })).not.toThrow();
	});

	it('policy table', () => {
		expect(legacyConfirmAccepted({}, 'http://example.test')).toBe(true);
		expect(legacyConfirmAccepted({}, 'http://localhost:8080')).toBe(true);
		expect(legacyConfirmAccepted({}, 'https://example.com')).toBe(false);
		expect(legacyConfirmAccepted({}, undefined)).toBe(false);
		expect(legacyConfirmAccepted({ STONEWRIGHT_DIRECT_CONFIRM_LEGACY: 'on' }, 'https://example.com')).toBe(true);
		expect(legacyConfirmAccepted({ STONEWRIGHT_DIRECT_CONFIRM_LEGACY: 'off' }, 'http://example.test')).toBe(false);
	});

	it('a valid id is enough: confirm is not also needed', () => {
		const id = (blockedBy(() => attempt()).details['confirmation_id']) as string;
		expect(() => attempt({ confirmationId: id })).not.toThrow();
	});
});

describe('which calls need a confirmation', () => {
	beforeEach(() => {
		for (const site of [REMOTE, LOCAL]) markTaskStartSeen(site);
		resetConfirmationsForTests();
	});
	afterEach(() => {
		resetTaskStartSeenForTests();
		resetConfirmationsForTests();
	});

	it('a destructive call in on mode needs none', () => {
		expect(() => attempt({ mode: 'on' })).not.toThrow();
	});

	it('alwaysConfirm asks for one even in on mode', () => {
		const error = blockedBy(() => attempt({ mode: 'on', alwaysConfirm: true }));
		expect(error.code).toBe('confirmation_required');
		expect(() => attempt({ mode: 'on', alwaysConfirm: true, site: LOCAL, confirm: true })).not.toThrow();
	});

	it('a call made outside the registry (no call context) still gets an id', () => {
		let error: unknown;
		try {
			assertWriteAllowed({ mode: 'confirm', destructive: true, tool: TOOL, site: REMOTE, env: {} });
		} catch (e) {
			error = e;
		}
		expect(error).toBeInstanceOf(DirectSafetyBlockedError);
		expect((error as DirectSafetyBlockedError).code).toBe('confirmation_required');
	});
});
