import { describe, expect, it } from 'vitest';
import { createConnectionRuntime } from '../../src/connection/runtime.js';

const GENERIC_ACTION = 'Reauthenticate this Stonewright server in the active MCP client, then run stonewright-task-start again.';

function latchedStatus(reasonCode: string, clientName = '') {
	const runtime = createConnectionRuntime({ env: {}, profile: 'essential-static' });
	if (clientName) {
		(runtime as unknown as { server: unknown }).server = { server: { getClientVersion: () => ({ name: clientName }) } };
	}
	runtime.markReauthenticationRequired(reasonCode);
	return { runtime, authentication: runtime.authenticationLatch };
}

describe('reauthentication messages', () => {
	it('explains that the site no longer recognizes the connection and says to add it again', () => {
		const { authentication, runtime } = latchedStatus('invalid_client');

		expect(authentication?.reason_code).toBe('invalid_client');
		expect(authentication?.user_action).toBe(
			'This site no longer recognizes this connection, so it must be added again; signing in again is not enough. '
			+ 'Remove this Stonewright server from the active MCP client and add it again from Stonewright → Setup, then run stonewright-task-start again.',
		);
		expect(runtime.status.next_action).toBe(authentication?.user_action);
	});

	it('names the client in the invalid_client action', () => {
		expect(latchedStatus('invalid_client', 'cursor').authentication?.user_action).toContain(
			'Remove the Stonewright MCP server in Cursor Settings → MCP and add it again from Stonewright → Setup',
		);
		expect(latchedStatus('invalid_client', 'claude-ai').authentication?.user_action).toContain(
			'Remove the Stonewright MCP server in your Claude MCP settings and add it again from Stonewright → Setup',
		);
		expect(latchedStatus('invalid_client', 'grok-cli').authentication?.user_action).toContain(
			'Remove the Stonewright MCP server for Grok Build / CLI and add it again from Stonewright → Setup',
		);
	});

	it('explains refresh_token_expired', () => {
		const { authentication } = latchedStatus('refresh_token_expired');

		expect(authentication?.reason_code).toBe('refresh_token_expired');
		expect(authentication?.user_action).toBe(`The sign-in expired after 30 days without use or reached its 90-day limit. ${GENERIC_ACTION}`);
	});

	it('explains refresh_token_revoked', () => {
		const { authentication } = latchedStatus('refresh_token_revoked');

		expect(authentication?.reason_code).toBe('refresh_token_revoked');
		expect(authentication?.user_action).toBe(`The connection was disconnected, or its credential was reused. ${GENERIC_ACTION}`);
	});

	it('explains refresh_outcome_unknown', () => {
		const { authentication } = latchedStatus('refresh_outcome_unknown');

		expect(authentication?.reason_code).toBe('refresh_outcome_unknown');
		expect(authentication?.user_action).toBe(`The site's answer to the last sign-in refresh was lost, so the saved sign-in cannot be trusted. ${GENERIC_ACTION}`);
	});

	it('explains a bare invalid_grant', () => {
		const { authentication } = latchedStatus('invalid_grant');

		expect(authentication?.reason_code).toBe('invalid_grant');
		expect(authentication?.user_action).toBe(
			`The site did not accept the saved sign-in, for example because the approving user lost access or the site's security keys changed. ${GENERIC_ACTION}`,
		);
	});

	it('keeps the client-specific action after the sentence', () => {
		expect(latchedStatus('refresh_token_expired', 'cursor').authentication?.user_action).toBe(
			'The sign-in expired after 30 days without use or reached its 90-day limit. '
			+ 'Reauthenticate the Stonewright MCP server in Cursor Settings → MCP, then run stonewright-task-start again.',
		);
	});

	it('never echoes a reason code it does not know', () => {
		const { authentication } = latchedStatus('<b>server says hello</b>');

		expect(authentication?.user_action).toBe(GENERIC_ACTION);
		expect(authentication?.reason_code).toBe('<b>server says hello</b>');
	});

	it('keeps every message inside the status contract length', () => {
		for (const reason of ['invalid_client', 'refresh_token_expired', 'refresh_token_revoked', 'refresh_outcome_unknown', 'invalid_grant']) {
			for (const client of ['', 'cursor', 'claude-ai', 'grok-cli']) {
				expect(latchedStatus(reason, client).authentication?.user_action?.length ?? 0).toBeLessThanOrEqual(512);
			}
		}
	});
});
