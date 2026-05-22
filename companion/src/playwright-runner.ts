/**
 * Playwright screenshot runner.
 *
 * Launches a headless Chromium instance, navigates to the target URL, and
 * returns a PNG buffer along with metadata. Reuses a single browser instance
 * across calls for efficiency; shuts it down on process exit.
 */

import { chromium } from 'playwright';
import type { Browser, Page } from 'playwright';
import { log } from './lib/log.js';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface ScreenshotOptions {
	/** Viewport size. Defaults to 1280×800. */
	viewport?: { width: number; height: number };
	/** Capture the full scrollable page. Defaults to false. */
	full_page?: boolean;
	/** Wait condition before screenshotting. Defaults to 'networkidle'. */
	wait_for?: 'load' | 'domcontentloaded' | 'networkidle' | 'commit';
	/** CSS selector — screenshot only the matched element (overrides full_page). */
	selector?: string;
	/** Extra milliseconds to wait after the page event fires. */
	delay_ms?: number;
}

export interface ScreenshotResult {
	png: Buffer;
	width: number;
	height: number;
	url: string;
	tookMs: number;
}

// ---------------------------------------------------------------------------
// Browser lifecycle
// ---------------------------------------------------------------------------

let sharedBrowser: Browser | null = null;

async function getBrowser(): Promise<Browser> {
	if (sharedBrowser && sharedBrowser.isConnected()) return sharedBrowser;
	log.info('Launching Chromium');
	sharedBrowser = await chromium.launch({
		headless: true,
		args: ['--no-sandbox', '--disable-dev-shm-usage'],
	});
	return sharedBrowser;
}

process.on('exit', () => {
	sharedBrowser?.close().catch(() => undefined);
});

// ---------------------------------------------------------------------------
// Screenshot
// ---------------------------------------------------------------------------

export async function screenshot(url: string, opts: ScreenshotOptions = {}): Promise<ScreenshotResult> {
	const t0 = Date.now();
	const logger = log.child({ url });
	logger.info('Taking screenshot', { opts });

	const browser = await getBrowser();
	const ctx = await browser.newContext({
		viewport: opts.viewport ?? { width: 1280, height: 800 },
	});
	let page: Page | null = null;

	try {
		page = await ctx.newPage();
		await page.goto(url, { waitUntil: opts.wait_for ?? 'networkidle', timeout: 30_000 });

		if (opts.delay_ms && opts.delay_ms > 0) {
			await page.waitForTimeout(opts.delay_ms);
		}

		let png: Buffer;

		if (opts.selector) {
			const el = page.locator(opts.selector).first();
			await el.waitFor({ state: 'visible', timeout: 10_000 });
			const raw = await el.screenshot({ type: 'png' });
			png = Buffer.from(raw);
		} else {
			const raw = await page.screenshot({ type: 'png', fullPage: opts.full_page ?? false });
			png = Buffer.from(raw);
		}

		const viewportSize = page.viewportSize() ?? { width: 1280, height: 800 };
		const tookMs = Date.now() - t0;
		logger.info('Screenshot complete', { bytes: png.length, tookMs });

		return {
			png,
			width: viewportSize.width,
			height: viewportSize.height,
			url,
			tookMs,
		};
	} finally {
		await page?.close().catch(() => undefined);
		await ctx.close().catch(() => undefined);
	}
}

/** Gracefully shut down the shared browser (call on server shutdown). */
export async function closeBrowser(): Promise<void> {
	if (sharedBrowser) {
		await sharedBrowser.close();
		sharedBrowser = null;
	}
}
