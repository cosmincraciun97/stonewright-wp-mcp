/**
 * Registry readiness barrier.
 *
 * Remote registration completes atomically into a staging set, then swaps into
 * the active catalog. startup_ready stays false until the barrier commits.
 * Failed reconnect leaves the prior healthy registry active.
 */

export interface CatalogEntry {
	name: string;
	source: 'local-gateway' | 'local' | 'remote' | 'direct';
}

export interface RegistrySnapshot {
	entries: CatalogEntry[];
	names: string[];
	ready: boolean;
	generation: number;
}

export class RegistryBarrier {
	private active: CatalogEntry[] = [];
	private staging: CatalogEntry[] | null = null;
	private ready = false;
	private readyBeforeStaging = false;
	private generation = 0;
	private lastFailure: string | null = null;

	get isReady(): boolean {
		return this.ready;
	}

	getGeneration(): number {
		return this.generation;
	}

	getLastFailure(): string | null {
		return this.lastError();
	}

	private lastError(): string | null {
		return this.lastFailure;
	}

	getActiveNames(): string[] {
		return this.active.map((e) => e.name);
	}

	getActiveEntries(): CatalogEntry[] {
		return [...this.active];
	}

	/** Seed permanent local tools before any remote work (does not mark ready). */
	seedLocal(entries: CatalogEntry[]): void {
		const byName = new Map(this.active.map((e) => [e.name, e]));
		for (const entry of entries) {
			byName.set(entry.name, entry);
		}
		this.active = [...byName.values()].sort((a, b) => a.name.localeCompare(b.name));
		// Local seed alone is not a ready remote/direct registry.
		this.ready = false;
	}

	beginStaging(): void {
		this.readyBeforeStaging = this.ready;
		this.staging = [];
		this.ready = false;
	}

	stage(entry: CatalogEntry): void {
		if (!this.staging) {
			this.beginStaging();
		}
		const staging = this.staging!;
		const idx = staging.findIndex((e) => e.name === entry.name);
		if (idx >= 0) staging[idx] = entry;
		else staging.push(entry);
	}

	stageMany(entries: readonly CatalogEntry[]): void {
		for (const entry of entries) this.stage(entry);
	}

	/**
	 * Atomic commit of staged remote/direct tools merged with permanent local entries.
	 * Bumps generation. Clears last failure.
	 */
	commit(options: { keepLocalSources?: ReadonlySet<string> } = {}): RegistrySnapshot {
		const keepSources = options.keepLocalSources ?? new Set(['local-gateway', 'local']);
		const priorLocal = this.active.filter((e) => keepSources.has(e.source));
		const staged = this.staging ?? [];
		const byName = new Map<string, CatalogEntry>();
		for (const entry of priorLocal) byName.set(entry.name, entry);
		for (const entry of staged) {
			// Local gateway always wins name ownership.
			const existing = byName.get(entry.name);
			if (existing && (existing.source === 'local-gateway' || existing.source === 'local')) {
				continue;
			}
			byName.set(entry.name, entry);
		}
		this.active = [...byName.values()].sort((a, b) => a.name.localeCompare(b.name));
		this.staging = null;
		this.ready = true;
		this.generation += 1;
		this.lastFailure = null;
		return this.snapshot();
	}

	/**
	 * Abort staging; leave prior healthy registry intact and record failure separately.
	 */
	abort(error: string): RegistrySnapshot {
		this.staging = null;
		// Restore prior ready state so a failed reconnect keeps the healthy catalog.
		this.ready = this.readyBeforeStaging;
		this.lastFailure = error;
		return this.snapshot();
	}

	/** Mark ready for Direct path without remote staging. */
	commitDirect(entries: readonly CatalogEntry[]): RegistrySnapshot {
		this.beginStaging();
		this.stageMany(entries);
		return this.commit();
	}

	snapshot(): RegistrySnapshot {
		return {
			entries: [...this.active],
			names: this.active.map((e) => e.name),
			ready: this.ready,
			generation: this.generation,
		};
	}
}
