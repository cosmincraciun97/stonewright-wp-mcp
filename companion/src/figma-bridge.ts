/**
 * Figma REST bridge.
 *
 * Responsibilities:
 *   - Parse Figma share URLs into (fileKey, nodeId) tuples.
 *   - Fetch node data from the Figma API.
 *   - Export images for a set of node IDs.
 *   - Apply a heuristic to map Figma nodes onto WordPress-friendly
 *     section/block descriptors (same mental model as the PHP FigmaImporter
 *     but richer: preserves fills, typography, auto-layout hints).
 *   - Build a full Stonewright DesignSpec from the mapped blocks.
 *
 * This module NEVER writes to WordPress.
 */

import { log } from './lib/log.js';

// ---------------------------------------------------------------------------
// Public types
// ---------------------------------------------------------------------------

export interface FigmaRef {
	fileKey: string;
	nodeId: string | null;
}

export interface FigmaColor {
	r: number;
	g: number;
	b: number;
	a: number;
}

export interface FigmaTypography {
	fontFamily: string;
	fontSize: number;
	fontWeight: number;
	lineHeightPx?: number;
	letterSpacing?: number;
}

export type BlockKind =
	| 'section'
	| 'heading'
	| 'paragraph'
	| 'image'
	| 'button'
	| 'group'
	| 'unknown';

export interface MappedBlock {
	id: string;
	name: string;
	kind: BlockKind;
	children: MappedBlock[];
	text?: string;
	typography?: FigmaTypography;
	fills?: FigmaColor[];
	imageExportUrl?: string;
	autoLayout?: 'HORIZONTAL' | 'VERTICAL' | 'NONE';
	width?: number;
	height?: number;
}

export interface FigmaFetchResult {
	fileKey: string;
	nodeId: string;
	rawNode: FigmaNode;
	blocks: MappedBlock[];
}

export interface FigmaExportResult {
	fileKey: string;
	images: Record<string, string>; // nodeId → CDN URL
}

// ---------------------------------------------------------------------------
// DesignSpec shapes (mirrors design-spec.schema.json)
// ---------------------------------------------------------------------------

export interface DesignColorToken {
	r: number;
	g: number;
	b: number;
	a?: number;
}

export interface DesignTypographyToken {
	fontFamily: string;
	fontSize: number;
	fontWeight: number;
	lineHeightPx?: number;
	letterSpacing?: number;
}

export interface DesignTokens {
	colors?: Record<string, string>;
	typography?: Record<string, DesignTypographyToken>;
	spacing?: Record<string, string>;
}

export interface AssetReference {
	id: string;
	url: string;
	altText?: string;
	width?: number;
	height?: number;
	mimeType?: string;
}

export interface DesignSpecBlock {
	type: string;
	id?: string;
	text?: string;
	level?: number;
	src?: string;
	alt?: string;
	url?: string;
	width?: number;
	height?: number;
	layout?: 'horizontal' | 'vertical' | 'grid';
	children?: DesignSpecBlock[];
	typography?: DesignTypographyToken;
	styles?: Record<string, string>;
	assetRef?: string;
	[key: string]: unknown;
}

export interface DesignSpecSection {
	id?: string;
	label?: string;
	blocks: DesignSpecBlock[];
	background?: { color?: string; imageRef?: string };
	fullWidth?: boolean;
}

export interface DesignSpec {
	version: string;
	page: { title: string; description?: string; slug?: string };
	sections: DesignSpecSection[];
	tokens?: DesignTokens;
	assets?: AssetReference[];
	breakpoints?: Array<{ id: string; label?: string; maxWidth?: number }>;
	meta?: Record<string, unknown>;
}

export interface FigmaIngestResult {
	spec: DesignSpec;
	warnings: string[];
	asset_count: number;
}

// ---------------------------------------------------------------------------
// Internal Figma API shapes (partial — only fields we use)
// ---------------------------------------------------------------------------

interface FigmaFill {
	type: string;
	color?: FigmaColor;
	imageRef?: string;
}

interface FigmaTypeStyle {
	fontFamily: string;
	fontSize: number;
	fontWeight: number;
	lineHeightPx?: number;
	letterSpacing?: number;
}

export interface FigmaNode {
	id: string;
	name: string;
	type: string;
	children?: FigmaNode[];
	characters?: string;
	style?: FigmaTypeStyle;
	fills?: FigmaFill[];
	layoutMode?: 'HORIZONTAL' | 'VERTICAL' | 'NONE';
	absoluteBoundingBox?: { width: number; height: number; x: number; y: number };
}

interface FigmaFileResponse {
	document?: FigmaNode;
	nodes?: Record<string, { document: FigmaNode }>;
}

interface FigmaExportResponse {
	images: Record<string, string>;
}

// ---------------------------------------------------------------------------
// URL parser
// ---------------------------------------------------------------------------

/**
 * Parses a Figma share URL into a `FigmaRef`.
 *
 * Supported forms:
 *   https://www.figma.com/file/<key>/Title?node-id=<id>
 *   https://www.figma.com/design/<key>/Title?node-id=<id>
 */
export function parseUrl(url: string): FigmaRef {
	let parsed: URL;
	try {
		parsed = new URL(url);
	} catch {
		throw new Error(`Invalid Figma URL: "${url}"`);
	}

	const segments = parsed.pathname.split('/').filter(Boolean);
	// /file/<key>/... or /design/<key>/...
	const typeIdx = segments.findIndex(s => s === 'file' || s === 'design');
	if (typeIdx === -1 || !segments[typeIdx + 1]) {
		throw new Error(`Cannot extract file key from Figma URL: "${url}"`);
	}
	const fileKey = segments[typeIdx + 1];
	if (fileKey === undefined) {
		throw new Error(`Cannot extract file key from Figma URL: "${url}"`);
	}
	const rawNodeId = parsed.searchParams.get('node-id');
	// Figma encodes node IDs as "123-456" in URLs but accepts "123:456" in the API.
	const nodeId = rawNodeId ? rawNodeId.replace(/-/g, ':') : null;

	return { fileKey, nodeId };
}

// ---------------------------------------------------------------------------
// REST helpers
// ---------------------------------------------------------------------------

const FIGMA_BASE = 'https://api.figma.com/v1';

async function figmaGet<T>(path: string, token: string): Promise<T> {
	const url = `${FIGMA_BASE}${path}`;
	log.debug('Figma GET', { url });
	const res = await fetch(url, {
		headers: { 'X-Figma-Token': token },
	});
	if (!res.ok) {
		const body = await res.text();
		throw new Error(`Figma API error ${res.status}: ${body}`);
	}
	return res.json() as Promise<T>;
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Fetch a specific node (or the root document if nodeId is null) from Figma.
 */
export async function fetchNode(fileKey: string, nodeId: string, token: string): Promise<FigmaFetchResult> {
	const logger = log.child({ fileKey, nodeId });
	logger.info('Fetching Figma node');

	const data = await figmaGet<FigmaFileResponse>(
		`/files/${fileKey}/nodes?ids=${encodeURIComponent(nodeId)}`,
		token,
	);

	const entry = data.nodes?.[nodeId];
	if (!entry) {
		throw new Error(`Node "${nodeId}" not found in file "${fileKey}"`);
	}
	const rawNode = entry.document;
	const blocks = mapNode(rawNode);

	logger.info('Node fetched', { blockCount: blocks.length });
	return { fileKey, nodeId, rawNode, blocks };
}

/**
 * Batch-export images (PNG, 2x) for a list of node IDs.
 */
export async function exportImages(
	fileKey: string,
	ids: string[],
	token: string,
	scale = 2,
): Promise<FigmaExportResult> {
	if (ids.length === 0) return { fileKey, images: {} };

	log.info('Exporting Figma images', { fileKey, count: ids.length });
	const idsParam = ids.map(encodeURIComponent).join(',');
	const data = await figmaGet<FigmaExportResponse>(
		`/images/${fileKey}?ids=${idsParam}&scale=${scale}&format=png`,
		token,
	);

	return { fileKey, images: data.images };
}

// ---------------------------------------------------------------------------
// Heuristic node mapper
// ---------------------------------------------------------------------------

function resolveKind(node: FigmaNode): BlockKind {
	const type = node.type.toUpperCase();
	const nameLower = node.name.toLowerCase();

	if (type === 'SECTION' || type === 'FRAME') {
		if (nameLower.includes('section') || nameLower.includes('hero') || nameLower.includes('banner')) {
			return 'section';
		}
		return 'group';
	}
	if (type === 'TEXT') {
		const fontSize = node.style?.fontSize ?? 16;
		if (nameLower.startsWith('heading') || nameLower.startsWith('h1') || nameLower.startsWith('h2') || fontSize >= 24) {
			return 'heading';
		}
		return 'paragraph';
	}
	if (type === 'RECTANGLE' || type === 'ELLIPSE' || type === 'VECTOR') {
		// Check for image fills
		const hasImageFill = node.fills?.some(f => f.type === 'IMAGE') ?? false;
		if (hasImageFill) return 'image';
	}
	if (nameLower.includes('button') || nameLower.includes('btn') || nameLower.includes('cta')) {
		return 'button';
	}
	if (type === 'COMPONENT' || type === 'INSTANCE') {
		return 'group';
	}
	return 'unknown';
}

function mapFill(fill: FigmaFill): FigmaColor | null {
	if (fill.type === 'SOLID' && fill.color) return fill.color;
	return null;
}

function mapNode(node: FigmaNode): MappedBlock[] {
	// Skip invisible/utility nodes
	if (node.name.startsWith('_') || node.name.toLowerCase() === 'bg') return [];

	const kind = resolveKind(node);
	const fills = (node.fills ?? []).map(mapFill).filter((c): c is FigmaColor => c !== null);
	const box = node.absoluteBoundingBox;

	const block: MappedBlock = {
		id: node.id,
		name: node.name,
		kind,
		children: [],
		...(node.characters !== undefined ? { text: node.characters } : {}),
		...(node.style ? {
			typography: {
				fontFamily: node.style.fontFamily,
				fontSize: node.style.fontSize,
				fontWeight: node.style.fontWeight,
				...(node.style.lineHeightPx !== undefined ? { lineHeightPx: node.style.lineHeightPx } : {}),
				...(node.style.letterSpacing !== undefined ? { letterSpacing: node.style.letterSpacing } : {}),
			},
		} : {}),
		...(fills.length > 0 ? { fills } : {}),
		...(node.layoutMode ? { autoLayout: node.layoutMode } : {}),
		...(box ? { width: box.width, height: box.height } : {}),
	};

	if (node.children) {
		block.children = node.children.flatMap(mapNode);
	}

	return [block];
}

// ---------------------------------------------------------------------------
// DesignSpec builder — converts MappedBlocks to full DesignSpec
// ---------------------------------------------------------------------------

/**
 * Convert an RGBA FigmaColor to a CSS hex string (#rrggbbaa or #rrggbb).
 */
function colorToCss(c: FigmaColor): string {
	const hex = (n: number) => Math.round(n * 255).toString(16).padStart(2, '0');
	const alpha = c.a !== undefined && c.a < 1 ? hex(c.a) : '';
	return `#${hex(c.r)}${hex(c.g)}${hex(c.b)}${alpha}`;
}

/**
 * Convert a MappedBlock (and its children) into a DesignSpecBlock.
 * Collects discovered asset references into the `assets` array.
 */
function mappedBlockToSpecBlock(
	block: MappedBlock,
	assets: AssetReference[],
	warnings: string[],
): DesignSpecBlock | null {
	const base: DesignSpecBlock = {
		type: block.kind === 'unknown' ? 'unknown' : block.kind,
		...(block.id ? { id: block.id } : {}),
	};

	if (block.typography) {
		base.typography = {
			fontFamily: block.typography.fontFamily,
			fontSize: block.typography.fontSize,
			fontWeight: block.typography.fontWeight,
			...(block.typography.lineHeightPx !== undefined ? { lineHeightPx: block.typography.lineHeightPx } : {}),
			...(block.typography.letterSpacing !== undefined ? { letterSpacing: block.typography.letterSpacing } : {}),
		};
	}

	if (block.fills && block.fills.length > 0) {
		const primaryFill = block.fills[0];
		base.styles = { backgroundColor: colorToCss(primaryFill) };
	}

	switch (block.kind) {
		case 'heading':
			base.text = block.text ?? block.name;
			base.level = block.typography?.fontSize !== undefined && block.typography.fontSize >= 36 ? 1
				: block.typography?.fontSize !== undefined && block.typography.fontSize >= 28 ? 2
				: 3;
			break;

		case 'paragraph':
			base.text = block.text ?? block.name;
			break;

		case 'button':
			base.text = block.text ?? block.name;
			base.url = '#';
			break;

		case 'image': {
			const imageUrl = block.imageExportUrl ?? '';
			if (imageUrl) {
				const assetId = `asset_${block.id.replace(/[^a-z0-9]/gi, '_')}`;
				const assetRef: AssetReference = {
					id: assetId,
					url: imageUrl,
					...(block.width ? { width: block.width } : {}),
					...(block.height ? { height: block.height } : {}),
					mimeType: 'image/png',
				};
				assets.push(assetRef);
				base.src = imageUrl;
				base.assetRef = assetId;
				base.alt = block.name;
			} else {
				base.src = '';
				base.alt = block.name;
				warnings.push(`Image block "${block.name}" (id: ${block.id}) has no export URL — asset sideloading will be skipped.`);
			}
			if (block.width) base.width = block.width;
			if (block.height) base.height = block.height;
			break;
		}

		case 'section':
		case 'group': {
			base.type = 'group';
			base.layout = block.autoLayout === 'HORIZONTAL' ? 'horizontal' : 'vertical';
			const children = block.children
				.map(child => mappedBlockToSpecBlock(child, assets, warnings))
				.filter((b): b is DesignSpecBlock => b !== null);
			if (children.length > 0) base.children = children;
			break;
		}

		case 'unknown':
			warnings.push(`Unsupported Figma node type for "${block.name}" (id: ${block.id}) — mapped as unknown block.`);
			break;
	}

	return base;
}

/**
 * Partition a flat list of MappedBlocks into DesignSpec sections.
 *
 * Top-level section/group nodes each become a section.
 * If the root block is not itself a section, wrap everything in a single section.
 */
function buildSections(
	blocks: MappedBlock[],
	assets: AssetReference[],
	warnings: string[],
): DesignSpecSection[] {
	const sections: DesignSpecSection[] = [];

	for (const block of blocks) {
		if (block.kind === 'section' || block.kind === 'group') {
			const sectionBlocks: DesignSpecBlock[] = [];
			for (const child of block.children) {
				const specBlock = mappedBlockToSpecBlock(child, assets, warnings);
				if (specBlock) sectionBlocks.push(specBlock);
			}
			// A section block with no children still produces a section — renderer handles empty.
			sections.push({
				id: block.id,
				label: block.name,
				blocks: sectionBlocks.length > 0 ? sectionBlocks : [{
					type: 'paragraph',
					id: `placeholder_${block.id}`,
					text: block.name,
				}],
			});
		} else {
			// Non-container top-level block — fold into an implicit section.
			const specBlock = mappedBlockToSpecBlock(block, assets, warnings);
			if (specBlock) {
				if (sections.length === 0) {
					sections.push({ id: 'section_0', blocks: [] });
				}
				sections[sections.length - 1].blocks.push(specBlock);
			}
		}
	}

	// Guard: must have at least one section with at least one block.
	if (sections.length === 0) {
		warnings.push('Figma node produced no mappable sections — generating a placeholder section.');
		sections.push({
			id: 'section_placeholder',
			blocks: [{ type: 'paragraph', text: 'Empty design — no sections found.' }],
		});
	}

	return sections;
}

/**
 * Extract named color tokens from top-level fills on mapped blocks (heuristic).
 */
function extractColorTokens(blocks: MappedBlock[]): Record<string, string> {
	const colors: Record<string, string> = {};
	let idx = 0;
	for (const block of blocks) {
		if (block.fills) {
			for (const fill of block.fills) {
				const key = `color_${idx++}`;
				colors[key] = colorToCss(fill);
			}
		}
	}
	return colors;
}

/**
 * Main entry point: ingest a Figma node and return a full DesignSpec + metadata.
 *
 * This does NOT write to WordPress.
 *
 * @param fileKey   Figma file key.
 * @param nodeId    Figma node ID (colon form).
 * @param token     Figma personal access token.
 * @param pageName  Optional page title override (defaults to root node name).
 */
export async function ingestFigmaNode(
	fileKey: string,
	nodeId: string,
	token: string,
	pageName?: string,
): Promise<FigmaIngestResult> {
	const logger = log.child({ fileKey, nodeId });
	logger.info('Starting Figma ingest');

	const warnings: string[] = [];
	const assets: AssetReference[] = [];

	// 1. Fetch node.
	const fetchResult = await fetchNode(fileKey, nodeId, token);
	const { blocks, rawNode } = fetchResult;

	// 2. Export images for image-fill nodes.
	const imageNodeIds = collectImageNodeIds(rawNode);
	let exportedImages: Record<string, string> = {};
	if (imageNodeIds.length > 0) {
		try {
			const exportResult = await exportImages(fileKey, imageNodeIds, token);
			exportedImages = exportResult.images;
			// Attach export URLs to mapped blocks.
			attachExportUrls(blocks, exportedImages);
		} catch (err) {
			warnings.push(`Image export failed: ${err instanceof Error ? err.message : String(err)}`);
		}
	}

	// 3. Build sections + collect assets.
	const sections = buildSections(blocks, assets, warnings);

	// 4. Extract design tokens from top-level fills (heuristic).
	const colors = extractColorTokens(blocks);
	const tokens: DesignTokens | undefined = Object.keys(colors).length > 0
		? { colors }
		: undefined;

	// 5. Assemble DesignSpec.
	const spec: DesignSpec = {
		version: '1.0.0',
		page: {
			title: pageName ?? rawNode.name,
		},
		sections,
		...(tokens ? { tokens } : {}),
		...(assets.length > 0 ? { assets } : {}),
		meta: {
			source: 'figma',
			figma_file_key: fileKey,
			figma_node_id: nodeId,
			ingested_at: new Date().toISOString(),
		},
	};

	logger.info('Figma ingest complete', { sectionCount: sections.length, assetCount: assets.length, warningCount: warnings.length });

	return { spec, warnings, asset_count: assets.length };
}

/**
 * Collect node IDs that have IMAGE fills — for batch export.
 */
function collectImageNodeIds(node: FigmaNode): string[] {
	const ids: string[] = [];
	const hasImageFill = node.fills?.some(f => f.type === 'IMAGE') ?? false;
	if (hasImageFill) ids.push(node.id);
	if (node.children) {
		for (const child of node.children) {
			ids.push(...collectImageNodeIds(child));
		}
	}
	return ids;
}

/**
 * Walk mapped blocks and attach export URL from the Figma images map.
 */
function attachExportUrls(blocks: MappedBlock[], images: Record<string, string>): void {
	for (const block of blocks) {
		if (block.kind === 'image' && images[block.id]) {
			block.imageExportUrl = images[block.id];
		}
		if (block.children.length > 0) {
			attachExportUrls(block.children, images);
		}
	}
}
