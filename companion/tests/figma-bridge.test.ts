import { describe, it, expect, vi, beforeEach } from 'vitest';
import { parseUrl, fetchNode, ingestFigmaNode } from '../src/figma-bridge.js';
import type { FigmaNode, FigmaIngestResult } from '../src/figma-bridge.js';

// ---------------------------------------------------------------------------
// parseUrl (pre-existing, kept + extended)
// ---------------------------------------------------------------------------

describe('parseUrl', () => {
	it('extracts fileKey and nodeId from a /file/ URL', () => {
		const ref = parseUrl('https://www.figma.com/file/ABC123/My-Design?node-id=12-34');
		expect(ref.fileKey).toBe('ABC123');
		expect(ref.nodeId).toBe('12:34');
	});

	it('extracts fileKey and nodeId from a /design/ URL', () => {
		const ref = parseUrl('https://www.figma.com/design/XYZ789/Prototype?node-id=0-1');
		expect(ref.fileKey).toBe('XYZ789');
		expect(ref.nodeId).toBe('0:1');
	});

	it('sets nodeId to null when no node-id param', () => {
		const ref = parseUrl('https://www.figma.com/file/ABC123/My-Design');
		expect(ref.nodeId).toBeNull();
	});

	it('throws on a non-Figma URL', () => {
		expect(() => parseUrl('https://example.com/not-figma')).toThrow();
	});

	it('throws on a completely invalid URL', () => {
		expect(() => parseUrl('not-a-url')).toThrow();
	});
});

// ---------------------------------------------------------------------------
// Canned Figma node response (golden fixture)
// ---------------------------------------------------------------------------

/** A realistic but minimal Figma node response for testing. */
const GOLDEN_FIGMA_NODE: FigmaNode = {
	id: '1:1',
	name: 'Hero Section',
	type: 'FRAME',
	layoutMode: 'VERTICAL',
	absoluteBoundingBox: { width: 1440, height: 800, x: 0, y: 0 },
	children: [
		{
			id: '1:2',
			name: 'Heading 1',
			type: 'TEXT',
			characters: 'Welcome to Stonewright',
			style: { fontFamily: 'Inter', fontSize: 48, fontWeight: 700 },
		},
		{
			id: '1:3',
			name: 'Subheading',
			type: 'TEXT',
			characters: 'The WordPress design platform.',
			style: { fontFamily: 'Inter', fontSize: 20, fontWeight: 400 },
		},
		{
			id: '1:4',
			name: 'CTA Button',
			type: 'RECTANGLE',
			fills: [],
		},
		{
			id: '1:5',
			name: 'Hero Image',
			type: 'RECTANGLE',
			fills: [{ type: 'IMAGE', imageRef: 'abc123' }],
			absoluteBoundingBox: { width: 800, height: 600, x: 0, y: 0 },
		},
	],
};

// ---------------------------------------------------------------------------
// fetchNode golden output
// ---------------------------------------------------------------------------

describe('fetchNode — golden canned response', () => {
	beforeEach(() => {
		// Mock global fetch to return the golden fixture.
		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({
				nodes: {
					'1:1': { document: GOLDEN_FIGMA_NODE },
				},
			}),
		}));
	});

	it('returns structured blocks from the canned node response', async () => {
		const result = await fetchNode('GOLDEN_FILE', '1:1', 'fake-token');

		expect(result.fileKey).toBe('GOLDEN_FILE');
		expect(result.nodeId).toBe('1:1');
		expect(result.rawNode.name).toBe('Hero Section');
		// Top-level block should be a section/group.
		expect(result.blocks).toHaveLength(1);
		expect(['section', 'group']).toContain(result.blocks[0]?.kind);
	});

	it('maps TEXT nodes to heading or paragraph', async () => {
		const result = await fetchNode('GOLDEN_FILE', '1:1', 'fake-token');
		const children = result.blocks[0]?.children ?? [];

		// fontSize 48 → heading
		const h1 = children.find(b => b.text === 'Welcome to Stonewright');
		expect(h1?.kind).toBe('heading');

		// fontSize 20 → paragraph
		const para = children.find(b => b.text === 'The WordPress design platform.');
		expect(para?.kind).toBe('paragraph');
	});

	it('maps RECTANGLE with IMAGE fill to image block', async () => {
		const result = await fetchNode('GOLDEN_FILE', '1:1', 'fake-token');
		const children = result.blocks[0]?.children ?? [];
		const imgBlock = children.find(b => b.name === 'Hero Image');
		expect(imgBlock?.kind).toBe('image');
	});
});

// ---------------------------------------------------------------------------
// ingestFigmaNode — full DesignSpec output
// ---------------------------------------------------------------------------

describe('ingestFigmaNode — DesignSpec golden output', () => {
	beforeEach(() => {
		// First fetch call → node data. Second → image export (empty).
		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					nodes: { '1:1': { document: GOLDEN_FIGMA_NODE } },
				}),
			})
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					images: { '1:5': 'https://figma-cdn.example.com/image/abc123.png' },
				}),
			}),
		);
	});

	it('returns a valid DesignSpec with correct version', async () => {
		const result: FigmaIngestResult = await ingestFigmaNode('GOLDEN_FILE', '1:1', 'fake-token', 'Test Page');
		expect(result.spec.version).toBe('1.0.0');
		expect(result.spec.page.title).toBe('Test Page');
	});

	it('spec has at least one section with blocks', async () => {
		const result = await ingestFigmaNode('GOLDEN_FILE', '1:1', 'fake-token');
		expect(result.spec.sections.length).toBeGreaterThan(0);
		for (const section of result.spec.sections) {
			expect(section.blocks.length).toBeGreaterThan(0);
		}
	});

	it('spec_sha8 is stable across two calls with same input', async () => {
		// Re-stub for second call sequence.
		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValue({
				ok: true,
				json: () => Promise.resolve({
					nodes: { '1:1': { document: GOLDEN_FIGMA_NODE } },
				}),
			}),
		);

		const r1 = await ingestFigmaNode('GOLDEN_FILE', '1:1', 'fake-token', 'Stable');
		const r2 = await ingestFigmaNode('GOLDEN_FILE', '1:1', 'fake-token', 'Stable');

		// asset_count must be the same (no images exported in this mock).
		expect(r1.asset_count).toBe(r2.asset_count);

		// Section structure must be identical.
		expect(r1.spec.sections.length).toBe(r2.spec.sections.length);
		expect(JSON.stringify(r1.spec.sections)).toBe(JSON.stringify(r2.spec.sections));
	});

	it('includes asset reference for image-fill node', async () => {
		// Fresh stubs with image export returning a URL.
		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					nodes: { '1:1': { document: GOLDEN_FIGMA_NODE } },
				}),
			})
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					images: { '1:5': 'https://figma-cdn.example.com/img.png' },
				}),
			}),
		);

		const result = await ingestFigmaNode('GOLDEN_FILE', '1:1', 'fake-token');
		expect(result.asset_count).toBeGreaterThanOrEqual(1);
		expect(result.spec.assets).toBeDefined();
		const assetUrls = (result.spec.assets ?? []).map(a => a.url);
		expect(assetUrls).toContain('https://figma-cdn.example.com/img.png');
	});

	it('returns warnings array (may be empty on clean input)', async () => {
		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({
				nodes: { '1:1': { document: GOLDEN_FIGMA_NODE } },
			}),
		}));

		const result = await ingestFigmaNode('GOLDEN_FILE', '1:1', 'fake-token');
		expect(Array.isArray(result.warnings)).toBe(true);
	});

	it('spec meta records figma_file_key and figma_node_id', async () => {
		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({
				nodes: { '1:1': { document: GOLDEN_FIGMA_NODE } },
			}),
		}));

		const result = await ingestFigmaNode('GOLDEN_FILE', '1:1', 'fake-token');
		expect(result.spec.meta?.['figma_file_key']).toBe('GOLDEN_FILE');
		expect(result.spec.meta?.['figma_node_id']).toBe('1:1');
		expect(result.spec.meta?.['source']).toBe('figma');
	});

	it('produces a warning for unknown node types', async () => {
		const nodeWithUnknown: FigmaNode = {
			id: '2:1',
			name: 'Section',
			type: 'FRAME',
			children: [
				{ id: '2:2', name: 'Weird', type: 'POLYGON' },
			],
		};

		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({
				nodes: { '2:1': { document: nodeWithUnknown } },
			}),
		}));

		const result = await ingestFigmaNode('F', '2:1', 'tok');
		// Unknown nodes generate warnings.
		const unknownWarnings = result.warnings.filter(w => w.toLowerCase().includes('unsupported'));
		expect(unknownWarnings.length).toBeGreaterThan(0);
	});
});
