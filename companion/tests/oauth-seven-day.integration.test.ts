/**
 * Fake-clock proof that OAuth access refresh sustains seven-day continuity
 * without a second browser authorization, and that explicit revoke forces reauth.
 */
import { describe, expect, it } from 'vitest';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
	OAuthReauthRequiredError,
	OAuthTokenManager,
	OAuthTokenStore,
	type OAuthTokenSetV2,
} from '../src/oauth-token-manager.js';

const HOUR_MS = 3_600_000;
const DAY_MS = 24 * HOUR_MS;
const SEVEN_DAYS_MS = 7 * DAY_MS;
const ACCESS_TTL_SEC = 3_600;
const FAMILY_TTL_SEC = 14 * 24 * 3_600;

describe('OAuth seven-day continuity (fake clock)', () => {
	it('survives 169 hourly task-start refresh windows with one initial auth and daily restarts', async () => {
		const directory = mkdtempSync(join(tmpdir(), 'stonewright-oauth-7d-'));
		try {
			const tokenStorePath = join(directory, 'tokens.json');
			const store = new OAuthTokenStore(tokenStorePath);
			let now = Date.parse('2026-01-01T00:00:00.000Z');
			let browserAuthorizations = 0;
			let refreshCalls = 0;
			let refreshGeneration = 0;
			let familyRefresh = 'fixture-refresh-initial';
			let revoked = false;

			const issueInitial = (): void => {
				browserAuthorizations += 1;
				refreshGeneration = 1;
				familyRefresh = `fixture-refresh-${refreshGeneration}`;
				const tokens: OAuthTokenSetV2 = {
					version: 2,
					accessToken: `fixture-access-${refreshGeneration}`,
					refreshToken: familyRefresh,
					expiresAt: now + ACCESS_TTL_SEC * 1000,
					refreshExpiresAt: now + FAMILY_TTL_SEC * 1000,
					clientId: 'client-example',
					resource: 'https://example.test/wp-json/mcp/stonewright-oauth',
					generation: refreshGeneration,
					updatedAt: now,
					tokenType: 'Bearer',
				};
				store.save(tokens);
			};

			issueInitial();

			const fetchImpl: typeof fetch = async (_input, init) => {
				await Promise.resolve();
				const body = String(init?.body ?? '');
				const params = new URLSearchParams(body);
				expect(params.get('grant_type')).toBe('refresh_token');
				expect(params.get('resource')).toBe('https://example.test/wp-json/mcp/stonewright-oauth');
				refreshCalls += 1;
				if (revoked || params.get('refresh_token') !== familyRefresh) {
					return new Response(JSON.stringify({ error: 'invalid_grant', reason: 'refresh_token_revoked' }), {
						status: 400,
						headers: { 'content-type': 'application/json', 'x-stonewright-refresh-consumed': '1' },
					});
				}
				refreshGeneration += 1;
				familyRefresh = `fixture-refresh-${refreshGeneration}`;
				return new Response(JSON.stringify({
					access_token: `fixture-access-${refreshGeneration}`,
					refresh_token: familyRefresh,
					expires_in: ACCESS_TTL_SEC,
					refresh_token_expires_in: FAMILY_TTL_SEC,
					token_type: 'Bearer',
				}), {
					status: 200,
					headers: { 'content-type': 'application/json', 'x-stonewright-refresh-consumed': '1' },
				});
			};

			const createManager = () => new OAuthTokenManager(store, {
				now: () => now,
				sleep: () => Promise.resolve(),
				random: () => 0,
				lockTimeoutMs: 1_000,
			});

			let manager = createManager();
			let reauthBeforeEnd = 0;

			// 169 hourly windows covers day 0 hour 0 through day 7 hour 0 inclusive.
			for (let hour = 0; hour <= 168; hour += 1) {
				now = Date.parse('2026-01-01T00:00:00.000Z') + hour * HOUR_MS;
				// Restart companion at least once per day (new manager, same durable store).
				if (hour > 0 && hour % 24 === 0) {
					manager = createManager();
				}
				try {
					const access = await manager.getAccessToken(
						fetchImpl,
						'https://example.test/wp-json/stonewright/v1/oauth/token',
						'client-example',
						'https://example.test/wp-json/mcp/stonewright-oauth',
					);
					expect(access.startsWith('fixture-access-')).toBe(true);
					expect(store.load()).not.toBeNull();
				} catch (error) {
					if (error instanceof OAuthReauthRequiredError) {
						reauthBeforeEnd += 1;
					} else {
						throw error;
					}
				}
			}

			expect(browserAuthorizations).toBe(1);
			expect(reauthBeforeEnd).toBe(0);
			expect(now - Date.parse('2026-01-01T00:00:00.000Z')).toBe(SEVEN_DAYS_MS);
			expect(refreshCalls).toBeGreaterThan(0);

			revoked = true;
			manager = createManager();
			now += HOUR_MS;
			await expect(
				manager.getAccessToken(
					fetchImpl,
					'https://example.test/wp-json/stonewright/v1/oauth/token',
					'client-example',
					'https://example.test/wp-json/mcp/stonewright-oauth',
				),
			).rejects.toBeInstanceOf(OAuthReauthRequiredError);
			expect(store.load()).toBeNull();
		} finally {
			rmSync(directory, { recursive: true, force: true });
		}
	});
});
