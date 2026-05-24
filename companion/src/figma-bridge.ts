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
	isBackgroundCandidate?: boolean;
	autoLayout?: 'HORIZONTAL' | 'VERTICAL' | 'NONE';
	x?: number;
	y?: number;
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
	layout?: 'flex' | 'grid';
	direction?: 'row' | 'column';
	blocks?: DesignSpecBlock[];
	typography?: DesignTypographyToken;
	styles?: Record<string, string>;
	assetRef?: string;
	[key: string]: unknown;
}

export interface DesignSpecSection {
	id?: string;
	name?: string;
	blocks: DesignSpecBlock[];
	background?: { color?: string; imageRef?: string; position?: string; size?: string; repeat?: string };
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
	opacity?: number;
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
	effects?: Array<{ type: string; [key: string]: unknown }>;
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
	const hasImageFill = node.fills?.some(f => f.type === 'IMAGE') ?? false;
	const isEmptyVisualNode = !node.children || node.children.length === 0;

	if (hasImageFill && isEmptyVisualNode) {
		return 'image';
	}
	if (shouldExportAsVectorIconAsset(node)) {
		return 'image';
	}

	if (type === 'SECTION' || type === 'FRAME' || type === 'GROUP') {
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
		if (hasImageFill) return 'image';
	}
	if (/\b(button|btn|cta)\b/.test(nameLower)) {
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

function hasComplexBackgroundVisual(node: FigmaNode): boolean {
	const fills = node.fills ?? [];
	const hasGradientOrBlendFill = fills.some(fill => fill.type !== 'SOLID' && fill.type !== 'IMAGE');
	const hasMultipleFills = fills.length > 1;
	const hasEffects = (node.effects?.length ?? 0) > 0;
	return hasGradientOrBlendFill || hasMultipleFills || hasEffects;
}

function hasBlurOrShadowEffect(node: FigmaNode): boolean {
	return (node.effects ?? []).some(effect => {
		const effectType = String(effect.type ?? '').toUpperCase();
		return effectType.includes('BLUR') || effectType.includes('SHADOW');
	});
}

function hasLargeBlurEffect(node: FigmaNode): boolean {
	return (node.effects ?? []).some(effect => {
		const effectType = String(effect.type ?? '').toUpperCase();
		const radius = typeof effect.radius === 'number' ? effect.radius : 0;
		return effectType.includes('BLUR') && radius >= 40;
	});
}

function hasNonImageFill(node: FigmaNode): boolean {
	return (node.fills ?? []).some(fill => fill.type !== 'IMAGE');
}

function hasDecorativeBounds(node: FigmaNode): boolean {
	const box = node.absoluteBoundingBox;
	return Boolean(box && box.width >= 64 && box.height >= 64);
}

function isBackgroundLikeNode(node: FigmaNode): boolean {
	const name = node.name.toLowerCase();
	return [
		'background',
		'bg',
		'glow',
		'blur',
		'orb',
		'gradient',
		'rectangle',
	].some(token => name.includes(token));
}

function isDecorativeBackgroundSubtree(node: FigmaNode): boolean {
	const type = node.type.toUpperCase();
	if (!['FRAME', 'GROUP', 'RECTANGLE', 'VECTOR', 'ELLIPSE'].includes(type)) {
		return false;
	}
	if (!hasDecorativeBounds(node) || !hasBlurOrShadowEffect(node) || !hasNonImageFill(node)) {
		return false;
	}

	const children = node.children ?? [];
	return children.length === 0 || children.every(child => isDecorativeBackgroundSubtree(child));
}

function isGenericDecorativeBlurNode(node: FigmaNode): boolean {
	const type = node.type.toUpperCase();
	if (!['FRAME', 'GROUP', 'RECTANGLE', 'VECTOR', 'ELLIPSE'].includes(type)) {
		return false;
	}
	if (hasDecorativeBounds(node) && hasLargeBlurEffect(node) && hasNonImageFill(node)) {
		return true;
	}
	return isDecorativeBackgroundSubtree(node);
}

function shouldExportAsBackgroundAsset(node: FigmaNode): boolean {
	const type = node.type.toUpperCase();
	if (!['FRAME', 'GROUP', 'RECTANGLE', 'VECTOR', 'ELLIPSE'].includes(type)) {
		return false;
	}
	return hasComplexBackgroundVisual(node) && (isBackgroundLikeNode(node) || isGenericDecorativeBlurNode(node));
}

function mapNode(node: FigmaNode): MappedBlock[] {
	// Skip explicit utility nodes.
	if (node.name.startsWith('_')) return [];

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
		...(shouldExportAsBackgroundAsset(node) ? { isBackgroundCandidate: true } : {}),
		...(node.layoutMode ? { autoLayout: node.layoutMode } : {}),
		...(box ? { x: box.x, y: box.y, width: box.width, height: box.height } : {}),
	};

	if (node.children) {
		block.children = kind === 'image' && shouldExportAsVectorIconAsset(node)
			? []
			: node.children.flatMap(mapNode);
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
	if (block.kind === 'unknown') {
		warnings.push(`Unsupported Figma node type for "${block.name}" (id: ${block.id}) — skipped.`);
		return null;
	}

	const base: DesignSpecBlock = {
		type: block.kind,
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

	const primaryFill = block.fills?.[0];
	if (primaryFill) {
		const fillColor = colorToCss(primaryFill);
		if (block.kind === 'heading' || block.kind === 'paragraph') {
			base.styles = { color: fillColor };
		} else {
			base.styles = { backgroundColor: fillColor };
		}
	}

	const nativeBlock = nativePatternToSpecBlock(block, base, assets, warnings);
	if (nativeBlock) return nativeBlock;

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
				warnings.push(`Image block "${block.name}" (id: ${block.id}) has no export URL — asset sideloading will be skipped.`);
				return null;
			}
			if (block.width) base.width = block.width;
			if (block.height) base.height = block.height;
			break;
		}

		case 'section':
		case 'group': {
			if (block.children.length === 0) {
				warnings.push(`Skipped decorative empty Figma container "${block.name}" (id: ${block.id}).`);
				return null;
			}
			base.type = 'container';
			base.layout = 'flex';
			base.direction = block.autoLayout === 'HORIZONTAL'
				? 'row'
				: block.autoLayout === 'VERTICAL'
					? 'column'
					: inferDirectionFromChildBounds(block.children);
			if (block.width) base.width = block.width;
			if (block.height) base.height = block.height;
			const blocks = mapChildrenToSpecBlocks(block, assets, warnings);
			if (blocks.length === 0) {
				warnings.push(`Skipped decorative Figma container "${block.name}" (id: ${block.id}) because all children were decorative or unsupported.`);
				return null;
			}
			const galleryImages = collectImageOnlyGalleryImages(blocks);
			if (galleryImages.length >= 2) {
				base.type = 'image-gallery';
				delete base.layout;
				delete base.direction;
				base.images = galleryImages;
				base.columns = inferGalleryColumns(galleryImages.length);
				break;
			}
			if (blocks.length > 0) base.blocks = blocks;
			break;
		}
	}

	return base;
}

function mapChildrenToSpecBlocks(
	block: MappedBlock,
	assets: AssetReference[],
	warnings: string[],
): DesignSpecBlock[] {
	const videoComposition = findVideoSiblingComposition(block);
	const blocks: DesignSpecBlock[] = [];

	for (const child of block.children) {
		if (videoComposition?.play.id === child.id) {
			continue;
		}
		if (videoComposition?.poster.id === child.id) {
			const video = videoPosterToSpecBlock(child, assets, warnings);
			if (video) blocks.push(video);
			continue;
		}

		const specBlock = mappedBlockToSpecBlock(child, assets, warnings);
		if (specBlock) blocks.push(specBlock);
	}

	return blocks;
}

function findVideoSiblingComposition(block: MappedBlock): { poster: MappedBlock; play: MappedBlock } | null {
	const blob = `${block.name} ${textDescendants(block).join(' ')}`.toLowerCase();
	if (!/\b(video|aftermovie|poster)\b/.test(blob)) {
		return null;
	}

	const poster = block.children
		.filter(child => child.kind === 'image' && (child.width ?? 0) >= 320 && (child.height ?? 0) >= 180)
		.sort((a, b) => blockArea(b) - blockArea(a))[0];
	if (!poster) return null;

	const play = block.children.find(child => child.id !== poster.id && looksLikePlayControl(child));
	if (!play) return null;

	return { poster, play };
}

function videoPosterToSpecBlock(
	poster: MappedBlock,
	assets: AssetReference[],
	warnings: string[],
): DesignSpecBlock | null {
	if (!poster.imageExportUrl) {
		warnings.push(`Video poster "${poster.name}" (id: ${poster.id}) has no export URL - native video poster skipped.`);
		return null;
	}

	return {
		type: 'video',
		id: poster.id,
		intent: 'video',
		url: '',
		...(poster.width ? { width: poster.width } : {}),
		...(poster.height ? { height: poster.height } : {}),
		poster: {
			url: poster.imageExportUrl,
			assetRef: addAssetReference(poster, assets),
			alt: poster.name,
		},
	};
}

function inferDirectionFromChildBounds(children: MappedBlock[]): 'row' | 'column' {
	const positioned = children.filter(child =>
		child.x !== undefined
		&& child.y !== undefined
		&& child.width !== undefined
		&& child.height !== undefined,
	);
	if (positioned.length < 2) return 'column';

	const minX = Math.min(...positioned.map(child => child.x ?? 0));
	const maxX = Math.max(...positioned.map(child => (child.x ?? 0) + (child.width ?? 0)));
	const minY = Math.min(...positioned.map(child => child.y ?? 0));
	const maxY = Math.max(...positioned.map(child => (child.y ?? 0) + (child.height ?? 0)));
	const horizontalSpan = maxX - minX;
	const verticalSpan = maxY - minY;

	return horizontalSpan > verticalSpan * 1.2 ? 'row' : 'column';
}

function collectImageOnlyGalleryImages(blocks: DesignSpecBlock[]): Array<Record<string, unknown>> {
	const images: Array<Record<string, unknown>> = [];

	const visit = (node: DesignSpecBlock): boolean => {
		if (node.type === 'image') {
			const image: Record<string, unknown> = {};
			if (node.assetRef) image.assetRef = node.assetRef;
			if (node.src) {
				image.src = node.src;
				image.url = node.src;
			}
			if (node.url) image.url = node.url;
			if (node.alt) image.alt = node.alt;
			if (node.width) image.width = node.width;
			if (node.height) image.height = node.height;
			images.push(image);
			return true;
		}

		if ((node.type === 'container' || node.type === 'group' || node.type === 'section') && Array.isArray(node.blocks)) {
			return node.blocks.length > 0 && node.blocks.every(child => visit(child));
		}

		return false;
	};

	const imageOnly = blocks.length > 0 && blocks.every(block => visit(block));
	return imageOnly ? images : [];
}

function inferGalleryColumns(imageCount: number): number {
	if (imageCount >= 4) {
		return 4;
	}
	return Math.max(1, imageCount);
}

function nativePatternToSpecBlock(
	block: MappedBlock,
	base: DesignSpecBlock,
	assets: AssetReference[],
	warnings: string[],
): DesignSpecBlock | null {
	if (looksLikeVideoPoster(block)) {
		const poster = firstImageDescendant(block);
		if (!poster?.imageExportUrl) {
			warnings.push(`Video poster "${block.name}" (id: ${block.id}) has no export URL — native video poster skipped.`);
			return null;
		}
		return {
			...base,
			type: 'video',
			intent: 'video',
			url: '',
			poster: {
				url: poster.imageExportUrl,
				assetRef: addAssetReference(poster, assets),
				alt: poster.name,
			},
		};
	}

	if (looksLikeSectionLabel(block)) {
		return {
			...base,
			type: 'container',
			intent: 'section-label',
			layout: 'flex',
			direction: 'column',
			blocks: [
				{
					type: 'paragraph',
					text: firstTextDescendant(block) ?? block.name,
					styles: {
						fontWeight: '600',
						letterSpacing: '2px',
						textTransform: 'uppercase',
					},
				},
				{
					type: 'divider',
					weight: 1,
				},
			],
		};
	}

	if (looksLikeNewsletterForm(block)) {
		return {
			...base,
			type: 'form',
			intent: 'newsletter-form',
			form_name: 'Newsletter',
			button_text: formButtonText(block) ?? 'Aboneaza-te la newsletter',
			fields: formFields(block),
		};
	}

	const gridColumns = inferGridColumnsFromBounds(block);
	if (gridColumns !== null) {
		const childBlocks = block.children
			.map(child => mappedBlockToSpecBlock(child, assets, warnings))
			.filter((child): child is DesignSpecBlock => child !== null);
		if (childBlocks.length > 0) {
			return {
				...base,
				type: 'container',
				layout: 'grid',
				columns: gridColumns,
				blocks: childBlocks,
			};
		}
	}

	return null;
}

function textDescendants(block: MappedBlock): string[] {
	const texts: string[] = [];
	if (block.text?.trim()) texts.push(block.text.trim());
	for (const child of block.children) {
		texts.push(...textDescendants(child));
	}
	return texts;
}

function firstTextDescendant(block: MappedBlock): string | null {
	return textDescendants(block).find(text => text.trim().length > 0) ?? null;
}

function looksLikeNewsletterForm(block: MappedBlock): boolean {
	const blob = `${block.name} ${textDescendants(block).join(' ')}`.toLowerCase();
	const hasFormHint = blob.includes('newsletter') || blob.includes('formular') || /\bform\b/.test(blob);
	const hasEmail = blob.includes('email') || blob.includes('e-mail');
	const hasSubmit = blob.includes('aboneaz') || blob.includes('subscribe') || blob.includes('trimite') || blob.includes('submit');
	return hasFormHint && hasEmail && hasSubmit && textDescendants(block).length >= 3;
}

function looksLikeSectionLabel(block: MappedBlock): boolean {
	const hasLabelText = textDescendants(block).some(text => /^\s*\d{2}\s*-/.test(text));
	return hasLabelText && (hasLineOrThinRectangle(block) || isCompactSectionLabelFrame(block));
}

function hasLineOrThinRectangle(block: MappedBlock): boolean {
	const name = block.name.toLowerCase();
	if (name.includes('underline') || name.includes('line')) return true;
	if ((block.width ?? 0) >= 24 && (block.height ?? 999) <= 2) return true;
	return block.children.some(hasLineOrThinRectangle);
}

function isCompactSectionLabelFrame(block: MappedBlock): boolean {
	const texts = textDescendants(block);
	const firstText = texts[0] ?? '';
	if (!/^\s*\d{2}\s*-/.test(firstText)) {
		return false;
	}
	if (texts.length > 2) {
		return false;
	}
	const width = block.width ?? 0;
	const height = block.height ?? 0;
	const hasCompactBounds = width > 0 && width <= 360 && height > 0 && height <= 96;
	const hasTinyText = block.children.some(child =>
		(child.kind === 'paragraph' || child.kind === 'heading')
		&& (child.typography?.fontSize ?? 99) <= 18,
	);
	return hasCompactBounds || hasTinyText;
}

function looksLikeVideoPoster(block: MappedBlock): boolean {
	const name = block.name.toLowerCase();
	const hasVideoHint = name.includes('video') || name.includes('aftermovie') || name.includes('poster');
	return hasVideoHint && firstImageDescendant(block) !== null && hasPlayControlDescendant(block);
}

function firstImageDescendant(block: MappedBlock): MappedBlock | null {
	if (block.kind === 'image') return block;
	for (const child of block.children) {
		const image = firstImageDescendant(child);
		if (image) return image;
	}
	return null;
}

function hasPlayControlDescendant(block: MappedBlock): boolean {
	return looksLikePlayControl(block) || block.children.some(hasPlayControlDescendant);
}

function looksLikePlayControl(block: MappedBlock): boolean {
	const name = block.name.toLowerCase();
	if (/\b(play|playback)\b/.test(name)) return true;

	const width = block.width ?? 0;
	const height = block.height ?? 0;
	const roughlySquare = width >= 48 && width <= 120 && height >= 48 && height <= 120 && Math.abs(width - height) <= 12;
	const hasIconChild = block.children.some(child => {
		const childName = child.name.toLowerCase();
		return child.kind === 'image' || childName.includes('icon') || childName.includes('vector');
	});
	return roughlySquare && hasIconChild;
}

function formButtonText(block: MappedBlock): string | null {
	return textDescendants(block).find(looksLikeSubmitText) ?? null;
}

function formFields(block: MappedBlock): Array<Record<string, unknown>> {
	return textDescendants(block)
		.filter(text => !looksLikeSubmitText(text))
		.map(text => {
			const label = text.replace(/\*/g, '').trim();
			const lower = label.toLowerCase();
			const type = lower.includes('email') || lower.includes('e-mail')
				? 'email'
				: lower.includes('interes') || lower.includes('categorie') || lower.includes('domeniu')
					? 'select'
					: 'text';
			return {
				type,
				label,
				required: text.includes('*'),
			};
		})
		.filter(field => String(field.label).length > 0);
}

function looksLikeSubmitText(text: string): boolean {
	const lower = text.toLowerCase();
	return lower.includes('aboneaz') || lower.includes('subscribe') || lower.includes('trimite') || lower.includes('submit');
}

function inferGridColumnsFromBounds(block: MappedBlock): number | null {
	const name = block.name.toLowerCase();
	const hasGridHint = name.includes('grid') || name.includes('speaker') || name.includes('card') || name.includes('team');

	const positioned = block.children.filter(child =>
		child.x !== undefined
		&& child.y !== undefined
		&& child.width !== undefined
		&& child.height !== undefined,
	);
	if (positioned.length < 4) return null;
	if (!hasGridHint && !looksLikeRepeatedCardRow(positioned)) return null;

	const sorted = [...positioned].sort((a, b) => (a.y ?? 0) - (b.y ?? 0) || (a.x ?? 0) - (b.x ?? 0));
	const firstY = sorted[0]?.y ?? 0;
	const firstRow = sorted.filter(child => Math.abs((child.y ?? 0) - firstY) <= 4);
	const columns = firstRow.length;
	if (columns < 2) return null;

	const widths = positioned.map(child => child.width ?? 0);
	const heights = positioned.map(child => child.height ?? 0);
	const maxWidth = Math.max(...widths);
	const maxHeight = Math.max(...heights);
	if (Math.max(...widths) - Math.min(...widths) > Math.max(8, maxWidth * 0.08)) return null;
	if (Math.max(...heights) - Math.min(...heights) > Math.max(8, maxHeight * 0.08)) return null;

	return Math.min(6, columns);
}

function looksLikeRepeatedCardRow(children: MappedBlock[]): boolean {
	if (children.length < 4) return false;
	const cardLike = children.filter(child => {
		const width = child.width ?? 0;
		const height = child.height ?? 0;
		const name = child.name.toLowerCase();
		const hasCardBounds = width >= 120 && height >= 180;
		const hasContent = firstImageDescendant(child) !== null || textDescendants(child).length > 0;
		return hasCardBounds && (hasContent || name.startsWith('frame '));
	});
	return cardLike.length >= 4;
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
	const pageBlocks = selectPageContentSections(blocks);

	for (const block of pageBlocks) {
		if (block.kind === 'section' || block.kind === 'group') {
			const backgroundBlocks = collectBackgroundAssetBlocks(block);
			const backgroundBlock = selectPrimaryBackgroundBlock(backgroundBlocks);
			const contentBlock: MappedBlock = backgroundBlocks.length > 0
				? {
						...block,
						children: block.children.filter(child =>
							!backgroundBlocks.some(candidate => containsBlock(child, candidate)),
						),
					}
				: block;
			const sectionBlocks = mapChildrenToSpecBlocks(contentBlock, assets, warnings);
			const background = sectionBackground(block, backgroundBlock, assets);
			// A section block with no children still produces a section — renderer handles empty.
			sections.push({
				id: block.id,
				name: block.name,
				blocks: sectionBlocks.length > 0 ? sectionBlocks : [{
					type: 'paragraph',
					id: `placeholder_${block.id}`,
					text: block.name,
				}],
				...(background ? { background } : {}),
				...(block.width !== undefined && block.width >= 1024 ? { fullWidth: true } : {}),
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

function selectPageContentSections(blocks: MappedBlock[]): MappedBlock[] {
	if (blocks.length !== 1) {
		return blocks.filter(block => !isTemplateOrOverlayBlock(block));
	}

	const root = blocks[0];
	if (!root || !isProbablePageFrame(root)) {
		return blocks;
	}

	const explicitSections = collectContentSections(root);
	if (explicitSections.length > 0) {
		return explicitSections;
	}

	return root.children.filter(block => !isTemplateOrOverlayBlock(block));
}

function isProbablePageFrame(block: MappedBlock): boolean {
	if (block.kind !== 'group' && block.kind !== 'section') {
		return false;
	}
	if (isTemplateOrOverlayBlock(block)) {
		return false;
	}
	if (block.children.some(child => isPageBodyWrapper(child) || isHeaderFooterBlock(child))) {
		return true;
	}
	return collectContentSections(block).length >= 2;
}

function collectContentSections(block: MappedBlock): MappedBlock[] {
	const sections: MappedBlock[] = [];

	const visit = (node: MappedBlock, insideBody: boolean): void => {
		if (isTemplateOrOverlayBlock(node)) {
			return;
		}

		const inBody = insideBody || isPageBodyWrapper(node);
		if (node !== block && inBody && node.kind === 'section' && !isTemplateOrOverlayBlock(node)) {
			sections.push(node);
			return;
		}

		for (const child of node.children) {
			visit(child, inBody);
		}
	};

	visit(block, false);
	return sections;
}

function isPageBodyWrapper(block: MappedBlock): boolean {
	const name = block.name.trim().toLowerCase();
	return name === 'body'
		|| name === 'main'
		|| name === 'content'
		|| /^t\d+$/.test(name);
}

function isTemplateOrOverlayBlock(block: MappedBlock): boolean {
	const name = block.name.trim().toLowerCase();
	if (isHeaderFooterBlock(block)) {
		return true;
	}
	if (name.startsWith('group ') || name.startsWith('rectangle ') || name.startsWith('vector ')) {
		return true;
	}
	return false;
}

function isHeaderFooterBlock(block: MappedBlock): boolean {
	const name = block.name.trim().toLowerCase();
	return name.includes('header') || name.includes('footer');
}

function findBackgroundAssetBlock(block: MappedBlock): MappedBlock | null {
	const candidates = collectBackgroundAssetBlocks(block);
	return selectPrimaryBackgroundBlock(candidates);
}

function selectPrimaryBackgroundBlock(candidates: MappedBlock[]): MappedBlock | null {
	if (candidates.length === 0) {
		return null;
	}

	return candidates.sort((a, b) => blockArea(b) - blockArea(a))[0] ?? null;
}

function collectBackgroundAssetBlocks(block: MappedBlock): MappedBlock[] {
	const candidates: MappedBlock[] = [];
	if (block.isBackgroundCandidate && block.imageExportUrl) {
		candidates.push(block);
	}
	for (const child of block.children) {
		candidates.push(...collectBackgroundAssetBlocks(child));
	}
	return candidates;
}

function blockArea(block: MappedBlock): number {
	return (block.width ?? 0) * (block.height ?? 0);
}

function containsBlock(root: MappedBlock, target: MappedBlock): boolean {
	return root.id === target.id || root.children.some(child => containsBlock(child, target));
}

function sectionBackground(
	sectionBlock: MappedBlock,
	backgroundBlock: MappedBlock | null,
	assets: AssetReference[],
): DesignSpecSection['background'] | undefined {
	const background: NonNullable<DesignSpecSection['background']> = {};
	const color = sectionBlock.fills?.[0];
	if (color) {
		background.color = colorToCss(color);
	}

	if (backgroundBlock?.imageExportUrl) {
		background.imageRef = addAssetReference(backgroundBlock, assets);
		background.position = 'center center';
		background.size = 'cover';
		background.repeat = 'no-repeat';
	}

	return Object.keys(background).length > 0 ? background : undefined;
}

function addAssetReference(block: MappedBlock, assets: AssetReference[]): string {
	const assetId = `asset_${block.id.replace(/[^a-z0-9]/gi, '_')}`;
	if (!assets.some(asset => asset.id === assetId)) {
		assets.push({
			id: assetId,
			url: block.imageExportUrl ?? '',
			...(block.width ? { width: block.width } : {}),
			...(block.height ? { height: block.height } : {}),
			mimeType: 'image/png',
		});
	}
	return assetId;
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

	// 2. Export images for image-fill and complex background nodes.
	const imageExportPlan = collectImageExportPlan(rawNode);
	const imageNodeIds = imageExportPlan.ids;
	let exportedImages: Record<string, string> = {};
	if (imageNodeIds.length > 0) {
		try {
			const exportResult = await exportImages(fileKey, imageNodeIds, token);
			exportedImages = exportResult.images;
			// Attach export URLs to mapped blocks.
			attachExportUrls(blocks, exportedImages, imageExportPlan.aliases);
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
interface ImageExportPlan {
	ids: string[];
	aliases: Record<string, string>;
}

function collectImageExportPlan(node: FigmaNode): ImageExportPlan {
	const ids = new Set<string>();
	const aliases: Record<string, string> = {};

	const visit = (current: FigmaNode, nearestInstanceId: string | null): void => {
		const hasImageFill = current.fills?.some(f => f.type === 'IMAGE') ?? false;

		if (shouldExportAsBackgroundAsset(current)) {
			ids.add(current.id);
			return;
		}

		if (hasImageFill) {
			if (nearestInstanceId && current.id.startsWith('I')) {
				ids.add(nearestInstanceId);
				aliases[current.id] = nearestInstanceId;
			} else {
				ids.add(current.id);
			}
		}

		if (shouldExportAsVectorIconAsset(current)) {
			ids.add(current.id);
		}

		const nextInstanceId = current.type.toUpperCase() === 'INSTANCE' ? current.id : nearestInstanceId;
		for (const child of current.children ?? []) {
			visit(child, nextInstanceId);
		}
	};

	visit(node, null);

	return {
		ids: Array.from(ids),
		aliases,
	};
}

function collectImageNodeIds(node: FigmaNode): string[] {
	return collectImageExportPlan(node).ids;
}

function shouldExportAsVectorIconAsset(node: FigmaNode): boolean {
	const type = node.type.toUpperCase();
	if (!['FRAME', 'GROUP', 'COMPONENT', 'INSTANCE'].includes(type)) {
		return false;
	}
	const name = node.name.trim().toLowerCase();
	if (!['icon', 'svg'].includes(name) && !name.includes('icon') && !name.includes('svg')) {
		return false;
	}
	return hasOnlyVectorDescendants(node);
}

function hasOnlyVectorDescendants(node: FigmaNode): boolean {
	const children = node.children ?? [];
	if (children.length === 0) {
		return false;
	}
	return children.every(child => {
		const type = child.type.toUpperCase();
		if (['VECTOR', 'BOOLEAN_OPERATION', 'STAR', 'LINE', 'POLYGON'].includes(type)) {
			return true;
		}
		if (['FRAME', 'GROUP'].includes(type)) {
			return hasOnlyVectorDescendants(child);
		}
		return false;
	});
}

/**
 * Walk mapped blocks and attach export URL from the Figma images map.
 */
function attachExportUrls(
	blocks: MappedBlock[],
	images: Record<string, string>,
	aliases: Record<string, string> = {},
): void {
	for (const block of blocks) {
		const exportId = aliases[block.id] ?? block.id;
		if (images[exportId]) {
			block.imageExportUrl = images[exportId];
		}
		if (block.children.length > 0) {
			attachExportUrls(block.children, images, aliases);
		}
	}
}
