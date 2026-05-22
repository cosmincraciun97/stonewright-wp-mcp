/**
 * Playwright runner — unit-level smoke test.
 * We mock the playwright module so no real browser is launched in CI.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

// ---------------------------------------------------------------------------
// Mock playwright BEFORE importing the module under test
// ---------------------------------------------------------------------------

const mockPng = Buffer.from([0x89, 0x50, 0x4e, 0x47]); // PNG magic bytes

vi.mock('playwright', () => {
	const mockPage = {
		goto: vi.fn().mockResolvedValue(undefined),
		screenshot: vi.fn().mockResolvedValue(mockPng),
		viewportSize: vi.fn().mockReturnValue({ width: 1280, height: 800 }),
		waitForTimeout: vi.fn().mockResolvedValue(undefined),
		locator: vi.fn().mockReturnThis(),
		first: vi.fn().mockReturnThis(),
		waitFor: vi.fn().mockResolvedValue(undefined),
		close: vi.fn().mockResolvedValue(undefined),
	};
	const mockCtx = {
		newPage: vi.fn().mockResolvedValue(mockPage),
		close: vi.fn().mockResolvedValue(undefined),
	};
	const mockBrowser = {
		isConnected: vi.fn().mockReturnValue(true),
		newContext: vi.fn().mockResolvedValue(mockCtx),
		close: vi.fn().mockResolvedValue(undefined),
	};
	return {
		chromium: {
			launch: vi.fn().mockResolvedValue(mockBrowser),
		},
	};
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('screenshot', () => {
	beforeEach(() => vi.clearAllMocks());

	it('returns a PNG buffer and metadata', async () => {
		const { screenshot } = await import('../src/playwright-runner.js');
		const result = await screenshot('https://example.com');
		expect(result.png).toBeInstanceOf(Buffer);
		expect(result.url).toBe('https://example.com');
		expect(result.width).toBe(1280);
		expect(result.height).toBe(800);
		expect(result.tookMs).toBeTypeOf('number');
	});
});
