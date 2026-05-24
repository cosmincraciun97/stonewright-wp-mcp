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

	it('maps empty FRAME nodes with IMAGE fills to image blocks', async () => {
		const imageFrameNode: FigmaNode = {
			id: '9:1',
			name: 'Gallery Section',
			type: 'FRAME',
			children: [
				{
					id: '9:2',
					name: 'Image (Conference Hall)',
					type: 'FRAME',
					fills: [{ type: 'IMAGE', imageRef: 'conference' }],
					absoluteBoundingBox: { width: 294, height: 294, x: 0, y: 0 },
					children: [],
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({
				nodes: { '9:1': { document: imageFrameNode } },
			}),
		}));

		const result = await fetchNode('GOLDEN_FILE', '9:1', 'fake-token');
		const imageBlock = result.blocks[0]?.children.find(block => block.id === '9:2');

		expect(imageBlock?.kind).toBe('image');
	});

	it('maps vector-only Icon groups to exported image blocks', async () => {
		const iconGroupNode: FigmaNode = {
			id: '11:1',
			name: 'Hero Section',
			type: 'FRAME',
			children: [
				{
					id: '11:2',
					name: 'Icon',
					type: 'FRAME',
					absoluteBoundingBox: { width: 24, height: 24, x: 0, y: 0 },
					children: [
						{ id: '11:3', name: 'Vector', type: 'VECTOR' },
						{ id: '11:4', name: 'Vector', type: 'VECTOR' },
					],
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({
				nodes: { '11:1': { document: iconGroupNode } },
			}),
		}));

		const result = await fetchNode('GOLDEN_FILE', '11:1', 'fake-token');
		const icon = result.blocks[0]?.children.find(block => block.id === '11:2');

		expect(icon?.kind).toBe('image');
		expect(icon?.children).toHaveLength(0);
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

	it('maps nested Figma frames to typed flex container blocks', async () => {
		const nodeWithNestedFrame: FigmaNode = {
			id: '3:1',
			name: 'Hero Section',
			type: 'FRAME',
			layoutMode: 'VERTICAL',
			children: [
				{
					id: '3:2',
					name: 'Cards Row',
					type: 'FRAME',
					layoutMode: 'HORIZONTAL',
					children: [
						{
							id: '3:3',
							name: 'Card title',
							type: 'TEXT',
							characters: 'Card title',
							style: { fontFamily: 'Inter', fontSize: 24, fontWeight: 700 },
						},
					],
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({
				nodes: { '3:1': { document: nodeWithNestedFrame } },
			}),
		}));

		const result = await ingestFigmaNode('GOLDEN_FILE', '3:1', 'fake-token');
		const nested = result.spec.sections[0]?.blocks[0];

		expect(nested?.type).toBe('container');
		expect(nested?.layout).toBe('flex');
		expect(nested?.direction).toBe('row');
		expect(nested?.blocks?.[0]?.type).toBe('heading');
	});

	it('infers horizontal containers from absolute child positions when auto-layout is absent', async () => {
		const nodeWithAbsoluteRow: FigmaNode = {
			id: '7:1',
			name: 'Hero Section',
			type: 'FRAME',
			absoluteBoundingBox: { width: 1440, height: 720, x: 0, y: 0 },
			children: [
				{
					id: '7:2',
					name: 'Hero row',
					type: 'FRAME',
					absoluteBoundingBox: { width: 1280, height: 500, x: 80, y: 100 },
					children: [
						{
							id: '7:3',
							name: 'Left column',
							type: 'FRAME',
							absoluteBoundingBox: { width: 600, height: 500, x: 80, y: 100 },
							children: [
								{
									id: '7:4',
									name: 'Hero heading',
									type: 'TEXT',
									characters: 'Hero',
									style: { fontFamily: 'Inter', fontSize: 48, fontWeight: 700 },
								},
							],
						},
						{
							id: '7:5',
							name: 'Right column',
							type: 'FRAME',
							absoluteBoundingBox: { width: 600, height: 500, x: 760, y: 100 },
							children: [
								{
									id: '7:6',
									name: 'Hero image',
									type: 'RECTANGLE',
									fills: [{ type: 'IMAGE', imageRef: 'hero' }],
									absoluteBoundingBox: { width: 600, height: 500, x: 760, y: 100 },
								},
							],
						},
					],
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					nodes: { '7:1': { document: nodeWithAbsoluteRow } },
				}),
			})
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					images: { '7:6': 'https://figma-cdn.example.com/hero.png' },
				}),
			}),
		);

		const result = await ingestFigmaNode('GOLDEN_FILE', '7:1', 'fake-token');
		const hero = result.spec.sections[0]?.blocks[0];

		expect(result.spec.sections[0]?.fullWidth).toBe(true);
		expect(hero?.type).toBe('container');
		expect(hero?.layout).toBe('flex');
		expect(hero?.direction).toBe('row');
	});

	it('maps text fills to text color instead of background color', async () => {
		const nodeWithTextFill: FigmaNode = {
			id: '4:1',
			name: 'Hero Section',
			type: 'FRAME',
			children: [
				{
					id: '4:2',
					name: 'Hero heading',
					type: 'TEXT',
					characters: 'Colored heading',
					style: { fontFamily: 'Inter', fontSize: 48, fontWeight: 700 },
					fills: [{ type: 'SOLID', color: { r: 1, g: 1, b: 1, a: 1 } }],
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({
				nodes: { '4:1': { document: nodeWithTextFill } },
			}),
		}));

		const result = await ingestFigmaNode('GOLDEN_FILE', '4:1', 'fake-token');
		const heading = result.spec.sections[0]?.blocks[0];

		expect(heading?.type).toBe('heading');
		expect(heading?.styles?.['color']).toBe('#ffffff');
		expect(heading?.styles?.['backgroundColor']).toBeUndefined();
	});

	it('skips decorative empty frame containers with only solid fills', async () => {
		const nodeWithDecorativeFrame: FigmaNode = {
			id: '5:1',
			name: 'Hero Section',
			type: 'FRAME',
			children: [
				{
					id: '5:2',
					name: 'Purple blur orb',
					type: 'FRAME',
					fills: [{ type: 'SOLID', color: { r: 0.5, g: 0, b: 1, a: 0.5 } }],
					absoluteBoundingBox: { width: 320, height: 40, x: 0, y: 0 },
				},
				{
					id: '5:3',
					name: 'Hero heading',
					type: 'TEXT',
					characters: 'Real content',
					style: { fontFamily: 'Inter', fontSize: 48, fontWeight: 700 },
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({
				nodes: { '5:1': { document: nodeWithDecorativeFrame } },
			}),
		}));

		const result = await ingestFigmaNode('GOLDEN_FILE', '5:1', 'fake-token');
		const ids = result.spec.sections[0]?.blocks.map(block => block.id);

		expect(ids).not.toContain('5:2');
		expect(ids).toContain('5:3');
	});

	it('skips containers whose only children are decorative empty containers', async () => {
		const nodeWithNestedDecoration: FigmaNode = {
			id: '6:1',
			name: 'Hero Section',
			type: 'FRAME',
			children: [
				{
					id: '6:2',
					name: 'Decorative group',
					type: 'FRAME',
					children: [
						{
							id: '6:3',
							name: 'Blur fill',
							type: 'FRAME',
							fills: [{ type: 'SOLID', color: { r: 0.2, g: 0.1, b: 1, a: 0.5 } }],
							absoluteBoundingBox: { width: 320, height: 40, x: 0, y: 0 },
						},
					],
				},
				{
					id: '6:4',
					name: 'Hero heading',
					type: 'TEXT',
					characters: 'Real content',
					style: { fontFamily: 'Inter', fontSize: 48, fontWeight: 700 },
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({
				nodes: { '6:1': { document: nodeWithNestedDecoration } },
			}),
		}));

		const result = await ingestFigmaNode('GOLDEN_FILE', '6:1', 'fake-token');
		const ids = result.spec.sections[0]?.blocks.map(block => block.id);

		expect(ids).not.toContain('6:2');
		expect(ids).toContain('6:4');
	});

	it('exports complex decorative background nodes as section background assets', async () => {
		const nodeWithGlowBackground: FigmaNode = {
			id: '8:1',
			name: 'Hero Section',
			type: 'FRAME',
			absoluteBoundingBox: { width: 1440, height: 760, x: 0, y: 0 },
			children: [
				{
					id: '8:2',
					name: 'Hero glow background',
					type: 'RECTANGLE',
					fills: [{ type: 'GRADIENT_RADIAL' } as never],
					effects: [{ type: 'LAYER_BLUR', radius: 120 } as never],
					absoluteBoundingBox: { width: 1440, height: 760, x: 0, y: 0 },
				} as FigmaNode,
				{
					id: '8:3',
					name: 'Hero heading',
					type: 'TEXT',
					characters: 'nZEB Expo Bucuresti 2025',
					style: { fontFamily: 'Montserrat', fontSize: 72, fontWeight: 700 },
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					nodes: { '8:1': { document: nodeWithGlowBackground } },
				}),
			})
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					images: { '8:2': 'https://figma-cdn.example.com/hero-glow-bg.png' },
				}),
			}),
		);

		const result = await ingestFigmaNode('GOLDEN_FILE', '8:1', 'fake-token');
		const section = result.spec.sections[0];

		expect(result.asset_count).toBe(1);
		expect(section?.background?.imageRef).toBe('asset_8_2');
		expect(section?.background?.position).toBe('center center');
		expect(section?.background?.size).toBe('cover');
		expect(result.spec.assets?.[0]?.url).toBe('https://figma-cdn.example.com/hero-glow-bg.png');
		expect(section?.blocks.map(block => block.id)).not.toContain('8:2');
		expect(section?.blocks.map(block => block.id)).toContain('8:3');
	});

	it('exports generic blurred translucent containers as background assets', async () => {
		const nodeWithGenericGlow: FigmaNode = {
			id: '8:10',
			name: 'Section',
			type: 'FRAME',
			fills: [{ type: 'SOLID', color: { r: 0.01, g: 0.02, b: 0.06, a: 1 } }],
			absoluteBoundingBox: { width: 1723, height: 1000, x: 0, y: 0 },
			children: [
				{
					id: '8:11',
					name: 'Container',
					type: 'FRAME',
					fills: [{ type: 'SOLID', opacity: 0.2, color: { r: 0.6, g: 0.06, b: 0.98, a: 1 } } as never],
					effects: [{ type: 'LAYER_BLUR', radius: 240 } as never],
					absoluteBoundingBox: { width: 877, height: 877, x: -156, y: 607 },
					children: [],
				},
				{
					id: '8:12',
					name: 'Hero heading',
					type: 'TEXT',
					characters: 'nZEB Expo Bucuresti 2025',
					style: { fontFamily: 'Montserrat', fontSize: 72, fontWeight: 700 },
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					nodes: { '8:10': { document: nodeWithGenericGlow } },
				}),
			})
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					images: { '8:11': 'https://figma-cdn.example.com/generic-container-glow.png' },
				}),
			}),
		);

		const result = await ingestFigmaNode('GOLDEN_FILE', '8:10', 'fake-token');
		const section = result.spec.sections[0];

		expect(result.asset_count).toBe(1);
		expect(section?.background?.color).toBe('#03050f');
		expect(section?.background?.imageRef).toBe('asset_8_11');
		expect(result.spec.assets?.[0]?.url).toBe('https://figma-cdn.example.com/generic-container-glow.png');
		expect(section?.blocks.map(block => block.id)).not.toContain('8:11');
		expect(section?.blocks.map(block => block.id)).toContain('8:12');
	});

	it('exports nested generic blur groups as one section background asset', async () => {
		const nodeWithNestedGenericGlow: FigmaNode = {
			id: '8:20',
			name: 'Section',
			type: 'FRAME',
			fills: [{ type: 'SOLID', color: { r: 0.01, g: 0.02, b: 0.06, a: 1 } }],
			absoluteBoundingBox: { width: 1723, height: 1000, x: 0, y: 0 },
			children: [
				{
					id: '8:21',
					name: 'Container',
					type: 'FRAME',
					fills: [{ type: 'SOLID', opacity: 0.2, color: { r: 0.6, g: 0.06, b: 0.98, a: 1 } } as never],
					effects: [{ type: 'LAYER_BLUR', radius: 240 } as never],
					absoluteBoundingBox: { width: 877, height: 877, x: -156, y: 607 },
					children: [
						{
							id: '8:22',
							name: 'Container',
							type: 'FRAME',
							fills: [{ type: 'SOLID', opacity: 0.6, color: { r: 0.08, g: 0.36, b: 0.98, a: 1 } } as never],
							effects: [{ type: 'LAYER_BLUR', radius: 120 } as never],
							absoluteBoundingBox: { width: 480, height: 480, x: 40, y: 40 },
							children: [],
						},
					],
				},
				{
					id: '8:23',
					name: 'Hero heading',
					type: 'TEXT',
					characters: 'nZEB Expo Bucuresti 2025',
					style: { fontFamily: 'Montserrat', fontSize: 72, fontWeight: 700 },
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					nodes: { '8:20': { document: nodeWithNestedGenericGlow } },
				}),
			})
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					images: { '8:21': 'https://figma-cdn.example.com/nested-container-glow.png' },
				}),
			}),
		);

		const result = await ingestFigmaNode('GOLDEN_FILE', '8:20', 'fake-token');
		const section = result.spec.sections[0];

		expect(result.asset_count).toBe(1);
		expect(section?.background?.imageRef).toBe('asset_8_21');
		expect(result.spec.assets?.[0]?.url).toBe('https://figma-cdn.example.com/nested-container-glow.png');
		expect(section?.blocks.map(block => block.id)).not.toContain('8:21');
		expect(section?.blocks.map(block => block.id)).not.toContain('8:22');
		expect(section?.blocks.map(block => block.id)).toContain('8:23');
		expect(result.warnings.join('\n')).not.toContain('8:21');
	});

	it('chooses the largest exported glow candidate for the section background', async () => {
		const nodeWithMultipleGlows: FigmaNode = {
			id: '8:30',
			name: 'Section',
			type: 'FRAME',
			fills: [{ type: 'SOLID', color: { r: 0.01, g: 0.02, b: 0.06, a: 1 } }],
			absoluteBoundingBox: { width: 1723, height: 1000, x: 0, y: 0 },
			children: [
				{
					id: '8:31',
					name: 'Container',
					type: 'FRAME',
					fills: [{ type: 'SOLID', opacity: 0.2, color: { r: 0.6, g: 0.06, b: 0.98, a: 1 } } as never],
					effects: [{ type: 'LAYER_BLUR', radius: 240 } as never],
					absoluteBoundingBox: { width: 300, height: 300, x: 0, y: 700 },
					children: [],
				},
				{
					id: '8:32',
					name: 'Container',
					type: 'FRAME',
					fills: [{ type: 'SOLID', opacity: 0.15, color: { r: 0.31, g: 0.22, b: 0.96, a: 1 } } as never],
					effects: [{ type: 'LAYER_BLUR', radius: 240 } as never],
					absoluteBoundingBox: { width: 1360, height: 1034, x: 180, y: -20 },
					children: [
						{
							id: '8:33',
							name: 'Container',
							type: 'FRAME',
							fills: [{ type: 'SOLID', opacity: 0.2, color: { r: 0.08, g: 0.36, b: 0.98, a: 1 } } as never],
							effects: [{ type: 'LAYER_BLUR', radius: 240 } as never],
							absoluteBoundingBox: { width: 836, height: 836, x: 570, y: -40 },
							children: [],
						},
					],
				},
				{
					id: '8:34',
					name: 'Hero heading',
					type: 'TEXT',
					characters: 'nZEB Expo Bucuresti 2025',
					style: { fontFamily: 'Montserrat', fontSize: 72, fontWeight: 700 },
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					nodes: { '8:30': { document: nodeWithMultipleGlows } },
				}),
			})
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					images: {
						'8:31': 'https://figma-cdn.example.com/small-glow.png',
						'8:32': 'https://figma-cdn.example.com/large-glow.png',
					},
				}),
			}),
		);

		const result = await ingestFigmaNode('GOLDEN_FILE', '8:30', 'fake-token');
		const section = result.spec.sections[0];

		expect(section?.background?.imageRef).toBe('asset_8_32');
		expect(result.spec.assets?.find(asset => asset.id === 'asset_8_32')?.url).toBe('https://figma-cdn.example.com/large-glow.png');
		expect(section?.blocks.map(block => block.id)).not.toContain('8:31');
		expect(section?.blocks.map(block => block.id)).not.toContain('8:32');
	});

	it('uses the nearest instance export for image fills inside instance layer IDs', async () => {
		const nodeWithInstanceImage: FigmaNode = {
			id: '13:1',
			name: 'Speaker Section',
			type: 'FRAME',
			absoluteBoundingBox: { width: 800, height: 500, x: 0, y: 0 },
			children: [
				{
					id: '13:2',
					name: 'Speaker Card Instance',
					type: 'INSTANCE',
					absoluteBoundingBox: { width: 294, height: 394, x: 40, y: 40 },
					children: [
						{
							id: 'I13:2;3740:793',
							name: 'Vector 3',
							type: 'VECTOR',
							fills: [{ type: 'IMAGE', imageRef: 'speaker-photo' }],
							absoluteBoundingBox: { width: 254, height: 258, x: 60, y: 60 },
						},
						{
							id: 'I13:2;3740:798',
							name: 'Speaker name',
							type: 'TEXT',
							characters: 'Adrian Stoichina',
							style: { fontFamily: 'Montserrat', fontSize: 16, fontWeight: 600 },
						},
					],
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					nodes: { '13:1': { document: nodeWithInstanceImage } },
				}),
			})
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					images: { '13:2': 'https://figma-cdn.example.com/speaker-card-instance.png' },
				}),
			}),
		);

		const result = await ingestFigmaNode('GOLDEN_FILE', '13:1', 'fake-token');
		const specJson = JSON.stringify(result.spec);

		expect(result.asset_count).toBe(1);
		expect(specJson).toContain('https://figma-cdn.example.com/speaker-card-instance.png');
		expect(result.warnings.join('\n')).not.toContain('I13:2;3740:793');
	});

	it('extracts page body sections from Figma page wrappers without header/footer siblings', async () => {
		const pageFrame: FigmaNode = {
			id: '97:8306',
			name: 'Editie-anterioara',
			type: 'FRAME',
			absoluteBoundingBox: { width: 1723, height: 6246, x: 43764, y: 120 },
			children: [
				{
					id: '97:8307',
					name: 'Body',
					type: 'FRAME',
					absoluteBoundingBox: { width: 1723, height: 6214, x: 43764, y: 109 },
					children: [
						{
							id: '97:8308',
							name: 'T0',
							type: 'FRAME',
							absoluteBoundingBox: { width: 1723, height: 6214, x: 43764, y: 109 },
							children: [
								{
									id: '97:8309',
									name: 'Section',
									type: 'FRAME',
									absoluteBoundingBox: { width: 1723, height: 1000, x: 43764, y: 238 },
									children: [
										{
											id: '97:8316',
											name: 'Hero heading',
											type: 'TEXT',
											characters: 'nZEB Expo Bucuresti 2025',
											style: { fontFamily: 'Montserrat', fontSize: 72, fontWeight: 700 },
										},
									],
								},
								{
									id: '97:8355',
									name: 'Section',
									type: 'FRAME',
									absoluteBoundingBox: { width: 1723, height: 960, x: 43764, y: 1238 },
									children: [
										{
											id: '97:8362',
											name: 'Aftermovie title',
											type: 'TEXT',
											characters: 'Atmosfera editiei nZEB Expo Bucuresti 2025',
											style: { fontFamily: 'Montserrat', fontSize: 48, fontWeight: 700 },
										},
									],
								},
								{
									id: '97:8476',
									name: 'Footer',
									type: 'FRAME',
									absoluteBoundingBox: { width: 1723, height: 422, x: 43764, y: 5944 },
									children: [
										{
											id: '97:8481',
											name: 'Footer text',
											type: 'TEXT',
											characters: 'nZEB Expo',
											style: { fontFamily: 'Montserrat', fontSize: 24, fontWeight: 700 },
										},
									],
								},
							],
						},
					],
				},
				{
					id: '97:8565',
					name: 'Group 19',
					type: 'GROUP',
					absoluteBoundingBox: { width: 1723, height: 154, x: 43764, y: 120 },
					children: [
						{
							id: '97:8566',
							name: 'Header',
							type: 'FRAME',
							children: [
								{
									id: '97:8577',
									name: 'Header menu',
									type: 'TEXT',
									characters: 'Editii',
									style: { fontFamily: 'Montserrat', fontSize: 16, fontWeight: 700 },
								},
							],
						},
					],
				},
				{
					id: '97:8613',
					name: 'Rectangle 3',
					type: 'RECTANGLE',
					absoluteBoundingBox: { width: 41, height: 74, x: 43855, y: 2461 },
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({
				nodes: { '97:8306': { document: pageFrame } },
			}),
		}));

		const result = await ingestFigmaNode('NZEB_FILE', '97:8306', 'fake-token');
		const sectionIds = result.spec.sections.map(section => section.id);
		const joined = JSON.stringify(result.spec.sections);

		expect(sectionIds).toEqual(['97:8309', '97:8355']);
		expect(joined).toContain('nZEB Expo Bucuresti 2025');
		expect(joined).toContain('Atmosfera editiei nZEB Expo Bucuresti 2025');
		expect(joined).not.toContain('Editii');
		expect(joined).not.toContain('Footer text');
		expect(joined).not.toContain('Rectangle 3');
	});

	it('collapses image-only gallery grids to native image-gallery spec blocks', async () => {
		const galleryNode: FigmaNode = {
			id: '10:1',
			name: 'Gallery Section',
			type: 'FRAME',
			children: [
				{
					id: '10:2',
					name: 'Gallery Grid',
					type: 'FRAME',
					layoutMode: 'HORIZONTAL',
					children: [
						{
							id: '10:3',
							name: 'Gallery item 1',
							type: 'FRAME',
							children: [
								{
									id: '10:4',
									name: 'Image (Conference Hall)',
									type: 'FRAME',
									fills: [{ type: 'IMAGE', imageRef: 'one' }],
									absoluteBoundingBox: { width: 294, height: 294, x: 0, y: 0 },
									children: [],
								},
							],
						},
						{
							id: '10:5',
							name: 'Gallery item 2',
							type: 'FRAME',
							children: [
								{
									id: '10:6',
									name: 'Image (Networking)',
									type: 'FRAME',
									fills: [{ type: 'IMAGE', imageRef: 'two' }],
									absoluteBoundingBox: { width: 294, height: 294, x: 314, y: 0 },
									children: [],
								},
							],
						},
					],
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					nodes: { '10:1': { document: galleryNode } },
				}),
			})
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					images: {
						'10:4': 'https://figma-cdn.example.com/conference.png',
						'10:6': 'https://figma-cdn.example.com/networking.png',
					},
				}),
			}),
		);

		const result = await ingestFigmaNode('GOLDEN_FILE', '10:1', 'fake-token');
		const gallery = result.spec.sections[0]?.blocks[0];

		expect(gallery?.type).toBe('image-gallery');
		expect(gallery?.columns).toBe(2);
		expect(gallery?.images).toEqual([
			expect.objectContaining({
				assetRef: 'asset_10_4',
				url: 'https://figma-cdn.example.com/conference.png',
			}),
			expect.objectContaining({
				assetRef: 'asset_10_6',
				url: 'https://figma-cdn.example.com/networking.png',
			}),
		]);
		expect(result.asset_count).toBe(2);
	});

	it('collapses aftermovie poster frames to native video blocks', async () => {
		const videoNode: FigmaNode = {
			id: '14:1',
			name: 'Aftermovie Section',
			type: 'FRAME',
			children: [
				{
					id: '14:2',
					name: 'Aftermovie video',
					type: 'FRAME',
					children: [
						{
							id: '14:3',
							name: 'Poster',
							type: 'RECTANGLE',
							fills: [{ type: 'IMAGE', imageRef: 'poster' }],
							absoluteBoundingBox: { width: 1280, height: 677, x: 0, y: 0 },
						},
						{ id: '14:4', name: 'play', type: 'VECTOR' },
					],
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					nodes: { '14:1': { document: videoNode } },
				}),
			})
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					images: { '14:3': 'https://figma-cdn.example.com/aftermovie-poster.png' },
				}),
			}),
		);

		const result = await ingestFigmaNode('GOLDEN_FILE', '14:1', 'fake-token');
		const video = result.spec.sections[0]?.blocks[0];

		expect(video?.type).toBe('video');
		expect(video?.poster).toEqual(expect.objectContaining({
			url: 'https://figma-cdn.example.com/aftermovie-poster.png',
			assetRef: 'asset_14_3',
		}));
		expect(JSON.stringify(result.spec)).not.toContain('"type":"html"');
	});

	it('collapses real aftermovie image and play siblings to a native video block', async () => {
		const videoNode: FigmaNode = {
			id: '18:1',
			name: 'Section',
			type: 'FRAME',
			children: [
				{
					id: '18:2',
					name: 'Container',
					type: 'FRAME',
					children: [
						{
							id: '18:3',
							name: '01 - aftermovie',
							type: 'TEXT',
							characters: '01 - aftermovie',
							style: { fontFamily: 'Montserrat', fontSize: 14, fontWeight: 400 },
						},
					],
				},
				{
					id: '18:4',
					name: 'Rectangle 7',
					type: 'RECTANGLE',
					fills: [{ type: 'IMAGE', imageRef: 'poster' }],
					absoluteBoundingBox: { width: 1280, height: 677, x: 0, y: 120 },
				},
				{
					id: '18:5',
					name: 'Container',
					type: 'FRAME',
					fills: [{ type: 'SOLID', color: { r: 1, g: 1, b: 1, a: 1 } }],
					absoluteBoundingBox: { width: 80, height: 80, x: 600, y: 420 },
					children: [
						{
							id: '18:6',
							name: 'Icon',
							type: 'FRAME',
							children: [
								{ id: '18:7', name: 'Vector', type: 'VECTOR' },
							],
						},
					],
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					nodes: { '18:1': { document: videoNode } },
				}),
			})
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					images: { '18:4': 'https://figma-cdn.example.com/real-aftermovie.png' },
				}),
			}),
		);

		const result = await ingestFigmaNode('GOLDEN_FILE', '18:1', 'fake-token');
		const json = JSON.stringify(result.spec.sections);

		expect(json).toContain('"type":"video"');
		expect(json).toContain('https://figma-cdn.example.com/real-aftermovie.png');
		expect(json).not.toContain('"id":"18:5"');
	});

	it('maps section labels to paragraph plus divider containers', async () => {
		const labelNode: FigmaNode = {
			id: '15:1',
			name: 'Aftermovie Section',
			type: 'FRAME',
			children: [
				{
					id: '15:2',
					name: 'Label',
					type: 'FRAME',
					children: [
						{
							id: '15:3',
							name: 'Label text',
							type: 'TEXT',
							characters: '01 - AFTERMOVIE',
							style: { fontFamily: 'Montserrat', fontSize: 14, fontWeight: 600 },
						},
						{
							id: '15:4',
							name: 'Underline',
							type: 'LINE',
							absoluteBoundingBox: { width: 136, height: 1, x: 0, y: 24 },
						},
					],
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({
				nodes: { '15:1': { document: labelNode } },
			}),
		}));

		const result = await ingestFigmaNode('GOLDEN_FILE', '15:1', 'fake-token');
		const label = result.spec.sections[0]?.blocks[0];

		expect(label?.type).toBe('container');
		expect(label?.intent).toBe('section-label');
		expect(label?.blocks?.[0]?.type).toBe('paragraph');
		expect(label?.blocks?.[0]?.text).toBe('01 - AFTERMOVIE');
		expect(label?.blocks?.[1]?.type).toBe('divider');
	});

	it('maps compact section labels even when Figma uses a stroked chip instead of a line node', async () => {
		const labelNode: FigmaNode = {
			id: '19:1',
			name: 'Section',
			type: 'FRAME',
			children: [
				{
					id: '19:2',
					name: 'Container',
					type: 'FRAME',
					absoluteBoundingBox: { width: 154, height: 38, x: 0, y: 0 },
					children: [
						{
							id: '19:3',
							name: '01 - aftermovie',
							type: 'TEXT',
							characters: '01 - aftermovie',
							style: { fontFamily: 'Montserrat', fontSize: 14, fontWeight: 400, letterSpacing: 2 },
						},
					],
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({
				nodes: { '19:1': { document: labelNode } },
			}),
		}));

		const result = await ingestFigmaNode('GOLDEN_FILE', '19:1', 'fake-token');
		const label = result.spec.sections[0]?.blocks[0];

		expect(label?.type).toBe('container');
		expect(label?.intent).toBe('section-label');
		expect(label?.blocks?.[0]?.text).toBe('01 - aftermovie');
		expect(label?.blocks?.[1]?.type).toBe('divider');
	});

	it('collapses newsletter form frames to native form blocks', async () => {
		const text = (id: string, characters: string): FigmaNode => ({
			id,
			name: characters,
			type: 'TEXT',
			characters,
			style: { fontFamily: 'Montserrat', fontSize: 16, fontWeight: 500 },
		});
		const formNode: FigmaNode = {
			id: '16:1',
			name: 'Newsletter Section',
			type: 'FRAME',
			children: [
				{
					id: '16:2',
					name: 'Newsletter form',
					type: 'FRAME',
					children: [
						text('16:3', 'Nume *'),
						text('16:4', 'Prenume *'),
						text('16:5', 'Email *'),
						text('16:6', 'Interes *'),
						text('16:7', 'Aboneaza-te la newsletter'),
					],
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({
				nodes: { '16:1': { document: formNode } },
			}),
		}));

		const result = await ingestFigmaNode('GOLDEN_FILE', '16:1', 'fake-token');
		const form = result.spec.sections[0]?.blocks[0];

		expect(form?.type).toBe('form');
		expect(form?.intent).toBe('newsletter-form');
		expect(form?.fields).toEqual([
			expect.objectContaining({ type: 'text', label: 'Nume', required: true }),
			expect.objectContaining({ type: 'text', label: 'Prenume', required: true }),
			expect.objectContaining({ type: 'email', label: 'Email', required: true }),
			expect.objectContaining({ type: 'select', label: 'Interes', required: true }),
		]);
		expect(form?.button_text).toBe('Aboneaza-te la newsletter');
	});

	it('maps speaker card rows to grid containers', async () => {
		const card = (id: string, x: number): FigmaNode => ({
			id,
			name: 'Speaker card',
			type: 'FRAME',
			absoluteBoundingBox: { width: 294, height: 394, x, y: 0 },
			children: [
				{
					id: `${id}:img`,
					name: 'Speaker photo',
					type: 'RECTANGLE',
					fills: [{ type: 'IMAGE', imageRef: `${id}-photo` }],
					absoluteBoundingBox: { width: 254, height: 258, x, y: 0 },
				},
				{
					id: `${id}:name`,
					name: 'Speaker name',
					type: 'TEXT',
					characters: 'Adrian Stoichina',
					style: { fontFamily: 'Montserrat', fontSize: 16, fontWeight: 600 },
				},
			],
		});
		const speakerNode: FigmaNode = {
			id: '17:1',
			name: 'Speaker Section',
			type: 'FRAME',
			children: [
				{
					id: '17:2',
					name: 'Speaker grid',
					type: 'FRAME',
					children: [card('17:3', 0), card('17:4', 318), card('17:5', 636), card('17:6', 954)],
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					nodes: { '17:1': { document: speakerNode } },
				}),
			})
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					images: {
						'17:3:img': 'https://figma-cdn.example.com/speaker-a.png',
						'17:4:img': 'https://figma-cdn.example.com/speaker-b.png',
						'17:5:img': 'https://figma-cdn.example.com/speaker-c.png',
						'17:6:img': 'https://figma-cdn.example.com/speaker-d.png',
					},
				}),
			}),
		);

		const result = await ingestFigmaNode('GOLDEN_FILE', '17:1', 'fake-token');
		const grid = result.spec.sections[0]?.blocks[0];

		expect(grid?.type).toBe('container');
		expect(grid?.layout).toBe('grid');
		expect(grid?.columns).toBe(4);
		expect(grid?.blocks).toHaveLength(4);
	});

	it('maps real Figma speaker instance rows to grid containers', async () => {
		const instance = (id: string, x: number): FigmaNode => ({
			id,
			name: `Frame ${id}`,
			type: 'INSTANCE',
			absoluteBoundingBox: { width: 294, height: 394, x, y: 0 },
			layoutMode: 'HORIZONTAL',
			children: [
				{
					id: `${id}:img`,
					name: 'img',
					type: 'RECTANGLE',
					fills: [{ type: 'IMAGE', imageRef: `${id}-photo` }],
					absoluteBoundingBox: { width: 254, height: 258, x, y: 0 },
				},
				{
					id: `${id}:name`,
					name: 'Speaker name',
					type: 'TEXT',
					characters: 'Adrian Stoichina',
					style: { fontFamily: 'Montserrat', fontSize: 16, fontWeight: 600 },
				},
			],
		});
		const speakerNode: FigmaNode = {
			id: '20:1',
			name: 'section',
			type: 'FRAME',
			children: [
				{
					id: '20:2',
					name: 'Frame 199',
					type: 'FRAME',
					layoutMode: 'HORIZONTAL',
					absoluteBoundingBox: { width: 1272, height: 394, x: 0, y: 0 },
					children: [
						instance('20:3', 0),
						instance('20:4', 326),
						instance('20:5', 652),
						instance('20:6', 978),
					],
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					nodes: { '20:1': { document: speakerNode } },
				}),
			})
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					images: {
						'20:3:img': 'https://figma-cdn.example.com/speaker-a.png',
						'20:4:img': 'https://figma-cdn.example.com/speaker-b.png',
						'20:5:img': 'https://figma-cdn.example.com/speaker-c.png',
						'20:6:img': 'https://figma-cdn.example.com/speaker-d.png',
					},
				}),
			}),
		);

		const result = await ingestFigmaNode('GOLDEN_FILE', '20:1', 'fake-token');
		const grid = result.spec.sections[0]?.blocks[0];

		expect(grid?.type).toBe('container');
		expect(grid?.layout).toBe('grid');
		expect(grid?.columns).toBe(4);
	});

	it('omits all decorative glow background nodes from section content', async () => {
		const glow = (id: string, x: number): FigmaNode => ({
			id,
			name: 'Container',
			type: 'FRAME',
			absoluteBoundingBox: { width: 778, height: 778, x, y: 0 },
			fills: [{ type: 'SOLID', color: { r: 0.49, g: 0.13, b: 0.99, a: 0.1 } }],
			effects: [{ type: 'LAYER_BLUR', radius: 200 }],
			children: [
				{
					id: `${id}:rect`,
					name: 'Rectangle 3',
					type: 'RECTANGLE',
					absoluteBoundingBox: { width: 73, height: 132, x: x + 20, y: 100 },
					fills: [{ type: 'SOLID', color: { r: 0.16, g: 0.49, b: 1, a: 1 } }],
				},
			],
		});
		const sectionNode: FigmaNode = {
			id: '21:1',
			name: 'Section',
			type: 'FRAME',
			children: [
				glow('21:2', 0),
				glow('21:3', 500),
				{
					id: '21:4',
					name: 'Heading 2',
					type: 'TEXT',
					characters: 'Atmosfera editiei',
					style: { fontFamily: 'Montserrat', fontSize: 48, fontWeight: 700 },
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					nodes: { '21:1': { document: sectionNode } },
				}),
			})
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					images: {
						'21:2': 'https://figma-cdn.example.com/glow-a.png',
						'21:3': 'https://figma-cdn.example.com/glow-b.png',
					},
				}),
			}),
		);

		const result = await ingestFigmaNode('GOLDEN_FILE', '21:1', 'fake-token');
		const json = JSON.stringify(result.spec.sections);

		expect(json).toContain('Atmosfera editiei');
		expect(json).not.toContain('Rectangle 3');
		expect(json).not.toContain('"id":"21:2"');
		expect(json).not.toContain('"id":"21:3"');
	});

	it('omits image nodes when Figma export returns no URL', async () => {
		const nodeWithMissingExport: FigmaNode = {
			id: '12:1',
			name: 'Speaker Section',
			type: 'FRAME',
			children: [
				{
					id: '12:2',
					name: 'Speaker name',
					type: 'TEXT',
					characters: 'Adrian Stoichina',
					style: { fontFamily: 'Montserrat', fontSize: 16, fontWeight: 600 },
				},
				{
					id: '12:3',
					name: 'img',
					type: 'FRAME',
					fills: [{ type: 'IMAGE', imageRef: 'missing' }],
					absoluteBoundingBox: { width: 254, height: 278, x: 0, y: 0 },
					children: [],
				},
			],
		};

		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({
					nodes: { '12:1': { document: nodeWithMissingExport } },
				}),
			})
			.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({ images: {} }),
			}),
		);

		const result = await ingestFigmaNode('GOLDEN_FILE', '12:1', 'fake-token');
		const joined = JSON.stringify(result.spec.sections);

		expect(joined).toContain('Adrian Stoichina');
		expect(joined).not.toContain('"id":"12:3"');
		expect(result.warnings.some(warning => warning.includes('has no export URL'))).toBe(true);
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
