/**
 * Surface revision + digest helpers.
 *
 * Digest = sha256 of sorted registered tool names, prefixed "sha256:".
 * Revision is monotonic and increments on every committed surface change.
 */

import { createHash } from 'node:crypto';

export function computeSurfaceDigest(toolNames: readonly string[]): string {
	const sorted = [...toolNames].filter(Boolean).sort();
	const hash = createHash('sha256').update(sorted.join('\n')).digest('hex');
	return `sha256:${hash}`;
}

export class SurfaceRevisionTracker {
	private revision = 0;
	private digest = computeSurfaceDigest([]);
	private names: string[] = [];

	getRevision(): number {
		return this.revision;
	}

	getDigest(): string {
		return this.digest;
	}

	getNames(): readonly string[] {
		return this.names;
	}

	/** Replace the active catalog and bump revision when the name set changes. */
	commit(toolNames: readonly string[]): { revision: number; digest: string; changed: boolean } {
		const nextNames = [...new Set(toolNames.filter(Boolean))].sort();
		const nextDigest = computeSurfaceDigest(nextNames);
		const changed = nextDigest !== this.digest || nextNames.length !== this.names.length;
		this.names = nextNames;
		this.digest = nextDigest;
		if (changed) {
			this.revision += 1;
		}
		return { revision: this.revision, digest: this.digest, changed };
	}

	/** Force a revision bump (e.g. reconnect generation) even if names match. */
	bump(toolNames?: readonly string[]): { revision: number; digest: string } {
		if (toolNames) {
			this.names = [...new Set(toolNames.filter(Boolean))].sort();
			this.digest = computeSurfaceDigest(this.names);
		}
		this.revision += 1;
		return { revision: this.revision, digest: this.digest };
	}

	snapshot(): { revision: number; digest: string; registered_tool_names: string[] } {
		return {
			revision: this.revision,
			digest: this.digest,
			registered_tool_names: [...this.names],
		};
	}
}
