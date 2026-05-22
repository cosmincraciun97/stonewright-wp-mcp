import { describe, it, expect } from 'vitest';
import { diff } from '../src/pixel-diff.js';
import { getArtifactsRoot } from '../src/lib/paths.js';
import { writeFile, unlink } from 'node:fs/promises';
import { join } from 'node:path';
import sharp from 'sharp';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

async function writeSolidPng(path: string, r: number, g: number, b: number, w = 4, h = 4): Promise<void> {
	const buf = Buffer.alloc(w * h * 4);
	for (let i = 0; i < w * h; i++) {
		buf[i * 4] = r;
		buf[i * 4 + 1] = g;
		buf[i * 4 + 2] = b;
		buf[i * 4 + 3] = 255;
	}
	const png = await sharp(buf, { raw: { width: w, height: h, channels: 4 } }).png().toBuffer();
	await writeFile(path, png);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('diff', () => {
	// Writes from pixel-diff are confined to the artifacts root. The dimensions
	// test (which throws before any write) still uses this root for symmetry.
	const tmp = getArtifactsRoot();

	it('returns zero mismatches for identical images', async () => {
		const ref = join(tmp, 'ref-identical.png');
		const act = join(tmp, 'act-identical.png');
		const out = join(tmp, 'out-identical.diff.png');
		await writeSolidPng(ref, 100, 150, 200);
		await writeSolidPng(act, 100, 150, 200);

		const result = await diff(ref, act, { diff_output_path: out });
		expect(result.mismatched_pixels).toBe(0);
		expect(result.total_pixels).toBe(16);
		expect(result.ratio).toBe(0);

		await Promise.all([unlink(ref), unlink(act), unlink(out)]);
	});

	it('detects all mismatches when colours are completely different', async () => {
		const ref = join(tmp, 'ref-diff.png');
		const act = join(tmp, 'act-diff.png');
		const out = join(tmp, 'out-diff.diff.png');
		await writeSolidPng(ref, 0, 0, 0);
		await writeSolidPng(act, 255, 255, 255);

		const result = await diff(ref, act, { threshold: 0.1, diff_output_path: out });
		expect(result.mismatched_pixels).toBe(16);
		expect(result.ratio).toBe(1);

		await Promise.all([unlink(ref), unlink(act), unlink(out)]);
	});

	it('throws when image dimensions differ', async () => {
		const ref = join(tmp, 'ref-dim.png');
		const act = join(tmp, 'act-dim.png');
		await writeSolidPng(ref, 0, 0, 0, 4, 4);
		await writeSolidPng(act, 0, 0, 0, 8, 8);

		await expect(diff(ref, act)).rejects.toThrow(/dimensions differ/);
		await Promise.all([unlink(ref), unlink(act)]);
	});

	it('ignores regions correctly', async () => {
		const ref = join(tmp, 'ref-ignore.png');
		const act = join(tmp, 'act-ignore.png');
		const out = join(tmp, 'out-ignore.diff.png');
		await writeSolidPng(ref, 0, 0, 0);
		await writeSolidPng(act, 255, 255, 255);

		// Ignore all 16 pixels — expect 0 mismatches
		const result = await diff(ref, act, {
			threshold: 0.1,
			ignore_regions: [{ x: 0, y: 0, width: 4, height: 4 }],
			diff_output_path: out,
		});
		expect(result.mismatched_pixels).toBe(0);

		await Promise.all([unlink(ref), unlink(act), unlink(out)]);
	});
});
