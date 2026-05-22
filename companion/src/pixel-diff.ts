/**
 * Pixel-level image diff using sharp.
 *
 * Loads both images as raw RGBA buffers, compares them pixel-by-pixel
 * with a configurable threshold, and writes a diff PNG where mismatched
 * pixels are highlighted in red.
 *
 * No third-party diff library — the comparator is written here so there are
 * no licensing surprises.
 */

import sharp from 'sharp';
import { writeFile } from 'node:fs/promises';
import { join, dirname, basename } from 'node:path';
import { log } from './lib/log.js';
import { assertInsideArtifacts, getArtifactsRoot } from './lib/paths.js';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface IgnoreRegion {
	x: number;
	y: number;
	width: number;
	height: number;
}

export interface PixelDiffOptions {
	/** 0–1 per-channel tolerance. Default 0.1. */
	threshold?: number;
	/** Rectangles that are excluded from the comparison. */
	ignore_regions?: IgnoreRegion[];
	/** Where to write the diff PNG. Defaults to <actualPath>.diff.png */
	diff_output_path?: string;
}

export interface PixelDiffResult {
	mismatched_pixels: number;
	total_pixels: number;
	ratio: number;
	diff_png_path: string;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function isIgnored(px: number, py: number, regions: IgnoreRegion[]): boolean {
	for (const r of regions) {
		if (px >= r.x && px < r.x + r.width && py >= r.y && py < r.y + r.height) return true;
	}
	return false;
}

interface RawImage {
	data: Buffer;
	width: number;
	height: number;
}

async function toRaw(path: string): Promise<RawImage> {
	const { data, info } = await sharp(path)
		.ensureAlpha()
		.raw()
		.toBuffer({ resolveWithObject: true });
	return { data, width: info.width, height: info.height };
}

// ---------------------------------------------------------------------------
// Main diff function
// ---------------------------------------------------------------------------

export async function diff(
	referencePath: string,
	actualPath: string,
	opts: PixelDiffOptions = {},
): Promise<PixelDiffResult> {
	const threshold = opts.threshold ?? 0.1;
	const ignoreRegions = opts.ignore_regions ?? [];
	const logger = log.child({ referencePath, actualPath });
	logger.info('Starting pixel diff');

	const [ref, actual] = await Promise.all([toRaw(referencePath), toRaw(actualPath)]);

	if (ref.width !== actual.width || ref.height !== actual.height) {
		throw new Error(
			`Image dimensions differ: reference ${ref.width}×${ref.height} vs actual ${actual.width}×${actual.height}`,
		);
	}

	const { width, height } = ref;
	const totalPixels = width * height;
	// RGBA diff output: same dimensions, 4 channels
	const diffData = Buffer.alloc(totalPixels * 4);
	let mismatchedPixels = 0;

	// Threshold is expressed as a 0–1 fraction of the max channel value (255).
	const maxDelta = threshold * 255;

	for (let y = 0; y < height; y++) {
		for (let x = 0; x < width; x++) {
			const idx = (y * width + x) * 4;
			if (isIgnored(x, y, ignoreRegions)) {
				// Paint ignored regions grey in the diff image
				diffData[idx] = 128;
				diffData[idx + 1] = 128;
				diffData[idx + 2] = 128;
				diffData[idx + 3] = 255;
				continue;
			}

			const rR = ref.data[idx] ?? 0;
			const rG = ref.data[idx + 1] ?? 0;
			const rB = ref.data[idx + 2] ?? 0;
			const rA = ref.data[idx + 3] ?? 255;

			const aR = actual.data[idx] ?? 0;
			const aG = actual.data[idx + 1] ?? 0;
			const aB = actual.data[idx + 2] ?? 0;
			const aA = actual.data[idx + 3] ?? 255;

			const dR = Math.abs(rR - aR);
			const dG = Math.abs(rG - aG);
			const dB = Math.abs(rB - aB);
			const dA = Math.abs(rA - aA);

			if (dR > maxDelta || dG > maxDelta || dB > maxDelta || dA > maxDelta) {
				mismatchedPixels++;
				// Highlight mismatches in red
				diffData[idx] = 255;
				diffData[idx + 1] = 0;
				diffData[idx + 2] = 0;
				diffData[idx + 3] = 255;
			} else {
				// Copy actual pixel at reduced opacity so reference context is visible
				diffData[idx] = aR;
				diffData[idx + 1] = aG;
				diffData[idx + 2] = aB;
				diffData[idx + 3] = Math.round(aA * 0.3);
			}
		}
	}

	// Resolve the diff output path. When the caller does not provide one we
	// drop the diff next to the actual screenshot. Either way the final path
	// must live inside the configured artifacts root — no writes anywhere else.
	const requestedDiffPath = opts.diff_output_path
		?? join(dirname(actualPath), basename(actualPath, '.png') + '.diff.png');
	const artifactsRoot = getArtifactsRoot();
	const diffOutputPath = assertInsideArtifacts(requestedDiffPath, artifactsRoot);

	await writeFile(
		diffOutputPath,
		await sharp(diffData, { raw: { width, height, channels: 4 } }).png().toBuffer(),
	);

	const ratio = totalPixels > 0 ? mismatchedPixels / totalPixels : 0;
	logger.info('Pixel diff complete', { mismatchedPixels, totalPixels, ratio });

	return {
		mismatched_pixels: mismatchedPixels,
		total_pixels: totalPixels,
		ratio,
		diff_png_path: diffOutputPath,
	};
}
