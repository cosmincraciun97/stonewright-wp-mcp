/**
 * Single-flight reconnect keyed by site identity, with bounded cooldown.
 * Concurrent callers coalesce onto one attempt. Catalog is preserved until a
 * successful registry barrier commit elsewhere.
 */

import type { TransportDiagnostic } from './transport-diagnostic.js';

export interface ReconnectInput {
	reason: string;
	site_alias?: string;
	force_probe?: boolean;
}

/** Legacy reconnect tool result (MCP stonewright-reconnect). */
export interface ReconnectToolResult {
	ok: boolean;
	coalesced: boolean;
	reason: string;
	site_alias: string | null;
	force_probe: boolean;
	connection_generation: number;
	surface_revision: number;
	prior_registry_preserved: boolean;
	error: string | null;
	status?: Record<string, unknown>;
}

/** Coordinator result used by degraded task-start recovery. */
export interface ReconnectResult {
	ok: boolean;
	attempted: boolean;
	coalesced: boolean;
	cooldown_active: boolean;
	diagnostic: TransportDiagnostic | null;
}

export type ReconnectExecutor = (input: ReconnectInput) => Promise<ReconnectToolResult>;
export type CoordinatorProbe = (reason: string) => Promise<{ ok: boolean; diagnostic?: TransportDiagnostic | null }>;

const DEFAULT_COOLDOWN_MS = 1_500;

export class ReconnectController {
	private inflight: Promise<ReconnectToolResult> | null = null;
	private waiters = 0;

	constructor(private readonly execute: ReconnectExecutor) {}

	async reconnect(input: ReconnectInput): Promise<ReconnectToolResult> {
		const reason = (input.reason ?? '').trim() || 'unspecified';
		const normalized: ReconnectInput = {
			reason,
			...(input.site_alias !== undefined ? { site_alias: input.site_alias } : {}),
			...(input.force_probe !== undefined ? { force_probe: input.force_probe } : {}),
		};

		if (this.inflight) {
			this.waiters += 1;
			const result = await this.inflight;
			return { ...result, coalesced: true };
		}

		this.waiters = 0;
		this.inflight = this.execute(normalized)
			.then((result) => ({ ...result, coalesced: false }))
			.finally(() => {
				this.inflight = null;
				this.waiters = 0;
			});

		return this.inflight;
	}

	isInFlight(): boolean {
		return this.inflight !== null;
	}

	pendingWaiters(): number {
		return this.waiters;
	}
}

export interface ReconnectCoordinatorOptions {
	siteKey?: string;
	cooldownMs?: number;
	now?: () => number;
}

/**
 * Keyed single-flight reconnect with cooldown for task-start recovery.
 * Increments connection generation only when an actual probe runs (caller responsibility).
 */
export function createReconnectCoordinator(
	probe: CoordinatorProbe,
	options: ReconnectCoordinatorOptions = {},
): { run: (reason: string) => Promise<ReconnectResult> } {
	const cooldownMs = options.cooldownMs ?? DEFAULT_COOLDOWN_MS;
	const now = options.now ?? Date.now;
	let inflight: Promise<ReconnectResult> | null = null;
	let lastAttemptAt = 0;

	return {
		async run(reason: string): Promise<ReconnectResult> {
			void reason;
			const current = now();
			if (inflight) {
				const result = await inflight;
				return { ...result, coalesced: true, attempted: false };
			}

			if (lastAttemptAt > 0 && current - lastAttemptAt < cooldownMs) {
				return {
					ok: false,
					attempted: false,
					coalesced: false,
					cooldown_active: true,
					diagnostic: null,
				};
			}

			lastAttemptAt = current;
			inflight = (async (): Promise<ReconnectResult> => {
				try {
					const outcome = await probe(reason);
					return {
						ok: outcome.ok,
						attempted: true,
						coalesced: false,
						cooldown_active: false,
						diagnostic: outcome.diagnostic ?? null,
					};
				} catch (error) {
					const diagnostic = error && typeof error === 'object' && 'diagnostic' in error
						? (error as { diagnostic: TransportDiagnostic }).diagnostic
						: null;
					return {
						ok: false,
						attempted: true,
						coalesced: false,
						cooldown_active: false,
						diagnostic,
					};
				} finally {
					inflight = null;
				}
			})();

			return inflight;
		},
	};
}

/**
 * Explicitly select plugin-supported task-start properties.
 * Never spread unknown companion keys then delete — omit site/site_alias entirely.
 */
export function projectTaskArgs(args: Record<string, unknown>): {
	task: string;
	surface?: string;
	intent?: string;
} {
	const task = typeof args['task'] === 'string' ? args['task'] : '';
	const projected: { task: string; surface?: string; intent?: string } = { task };
	if (typeof args['surface'] === 'string') projected.surface = args['surface'];
	if (typeof args['intent'] === 'string') projected.intent = args['intent'];
	return projected;
}
