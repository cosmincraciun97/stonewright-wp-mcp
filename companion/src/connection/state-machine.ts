/**
 * Connection state machine for companion ↔ WordPress lifecycle.
 *
 * States:
 *   local-ready          Permanent gateways registered; no remote yet
 *   probing              Auto mode checking plugin endpoint
 *   direct-ready         Direct REST tools registered and usable
 *   plugin-authenticated Auth/handshake succeeded
 *   plugin-registering   Remote tools staging; startup_ready=false
 *   plugin-ready         Registry barrier committed; full catalog active
 *   degraded             Connection issues; prior healthy registry retained when possible
 */

export type ConnectionStage =
	| 'local-ready'
	| 'probing'
	| 'direct-ready'
	| 'plugin-authenticated'
	| 'plugin-registering'
	| 'plugin-ready'
	| 'degraded';

const ALLOWED_TRANSITIONS: Record<ConnectionStage, readonly ConnectionStage[]> = {
	'local-ready': ['probing', 'direct-ready', 'plugin-authenticated', 'plugin-registering', 'degraded'],
	probing: ['local-ready', 'direct-ready', 'plugin-authenticated', 'plugin-registering', 'degraded'],
	'direct-ready': ['probing', 'plugin-authenticated', 'plugin-registering', 'degraded', 'local-ready'],
	'plugin-authenticated': ['plugin-registering', 'degraded', 'direct-ready', 'local-ready'],
	'plugin-registering': ['plugin-ready', 'degraded', 'direct-ready', 'local-ready'],
	'plugin-ready': ['probing', 'plugin-registering', 'degraded', 'direct-ready', 'local-ready'],
	degraded: ['probing', 'local-ready', 'direct-ready', 'plugin-authenticated', 'plugin-registering', 'plugin-ready'],
};

export class ConnectionStateMachine {
	private stage: ConnectionStage = 'local-ready';
	private generation = 0;
	private lastError: string | null = null;

	getStage(): ConnectionStage {
		return this.stage;
	}

	getGeneration(): number {
		return this.generation;
	}

	getLastError(): string | null {
		return this.lastError;
	}

	canTransition(to: ConnectionStage): boolean {
		if (to === this.stage) return true;
		return ALLOWED_TRANSITIONS[this.stage].includes(to);
	}

	transition(to: ConnectionStage, options: { error?: string | null; bumpGeneration?: boolean } = {}): ConnectionStage {
		if (!this.canTransition(to)) {
			throw new Error(`Invalid connection transition ${this.stage} → ${to}`);
		}
		if (to !== this.stage && options.bumpGeneration) {
			this.generation += 1;
		}
		this.stage = to;
		if (options.error !== undefined) {
			this.lastError = options.error;
		} else if (to === 'plugin-ready' || to === 'direct-ready') {
			this.lastError = null;
		}
		return this.stage;
	}

	/** Registry barrier incomplete while registering remote tools. */
	isRegistryReady(): boolean {
		return this.stage === 'plugin-ready' || this.stage === 'direct-ready';
	}

	/** Derived backward-compatible connected flag — not source of truth. */
	isConnectedDerived(): boolean {
		return this.stage === 'plugin-ready'
			|| this.stage === 'direct-ready'
			|| this.stage === 'plugin-registering'
			|| this.stage === 'plugin-authenticated';
	}

	snapshot(): {
		connection_stage: ConnectionStage;
		connection_generation: number;
		registry_ready: boolean;
		connected: boolean;
		last_error: string | null;
	} {
		return {
			connection_stage: this.stage,
			connection_generation: this.generation,
			registry_ready: this.isRegistryReady(),
			connected: this.isConnectedDerived(),
			last_error: this.lastError,
		};
	}
}
