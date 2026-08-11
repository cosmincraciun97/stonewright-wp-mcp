/**
 * Singleflight reconnect: concurrent reconnect requests coalesce into one transition.
 * Failed reconnect leaves prior healthy registry active and reports failure separately.
 */

export interface ReconnectInput {
	reason: string;
	site_alias?: string;
	force_probe?: boolean;
}

export interface ReconnectResult {
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

export type ReconnectExecutor = (input: ReconnectInput) => Promise<ReconnectResult>;

export class ReconnectController {
	private inflight: Promise<ReconnectResult> | null = null;
	private waiters = 0;

	constructor(private readonly execute: ReconnectExecutor) {}

	async reconnect(input: ReconnectInput): Promise<ReconnectResult> {
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
