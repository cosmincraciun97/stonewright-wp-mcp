import { expect, test, type Page } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';

/**
 * Front-end visual matrix (Phase 5 live proof).
 *
 * Seeds constrained landing-like pages via the REST API, then asserts on the
 * public front-end: no horizontal overflow, hero/content horizontally centered
 * within 8px of the viewport center. Runs across the Playwright project matrix
 * (5 viewports × light/dark).
 *
 * Full blueprint×engine structural coverage lives in PHPUnit
 * BlueprintRenderOutputSuiteTest; this suite proves front-end layout contracts.
 */

const WP_USER = process.env.WP_USERNAME ?? 'admin';
const WP_PASS = process.env.WP_PASSWORD ?? 'password';
const artifactDir = path.join(process.cwd(), 'artifacts', 'visual-matrix');

async function login(page: Page): Promise<void> {
	await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
	if (!page.url().includes('wp-login.php')) {
		return;
	}
	await page.locator('#user_login').waitFor({ state: 'visible', timeout: 15_000 });
	await page.locator('#user_login').fill(WP_USER);
	await page.locator('#user_pass').fill(WP_PASS);
	await page.locator('#wp-submit').click();
	try {
		await page.waitForURL(/\/wp-admin\//, { timeout: 45_000, waitUntil: 'domcontentloaded' });
	} catch {
		if (page.url().includes('wp-login.php')) {
			await page.locator('#user_login').fill(WP_USER);
			await page.locator('#user_pass').fill(WP_PASS);
			await page.locator('#wp-submit').click();
			await page.waitForURL(/\/wp-admin\//, { timeout: 45_000, waitUntil: 'domcontentloaded' });
		}
	}
}

function constrainedLandingMarkup(title: string, kicker: string): string {
	// Mirrors FSE/gutenberg constrained group wrapper from BlueprintApplier.
	return `<!-- wp:group {"align":"full","layout":{"type":"constrained","contentSize":"720px","wideSize":"1100px","justifyContent":"center"},"className":"stonewright-fse-root stonewright-visual-hero"} -->
<div class="wp-block-group alignfull stonewright-fse-root stonewright-visual-hero">
<!-- wp:heading {"textAlign":"center","level":1} -->
<h1 class="wp-block-heading has-text-align-center">${title}</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">${kicker}</p>
<!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#cta">Get started</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->`;
}

async function ensureSeedPages(page: Page): Promise<Array<{ slug: string; link: string }>> {
	const seeds = [
		{ slug: 'sw-visual-dental', title: 'Precision dental care', kicker: 'Calm clinic landing for visual matrix proof.' },
		{ slug: 'sw-visual-saas', title: 'Ship product faster', kicker: 'SaaS hero with constrained, centered content.' },
		{ slug: 'sw-visual-restaurant', title: 'Reserve a table tonight', kicker: 'Restaurant landing centered for overflow checks.' },
	];

	// Authenticated REST via cookies from login.
	const created: Array<{ slug: string; link: string }> = [];
	for (const seed of seeds) {
		const listRes = await page.request.get(`/wp-json/wp/v2/pages?slug=${seed.slug}&status=publish,draft`);
		let link = '';
		if (listRes.ok()) {
			const existing = (await listRes.json()) as Array<{ id: number; link: string; status: string }>;
			if (existing.length > 0) {
				const id = existing[0].id;
				await page.request.post(`/wp-json/wp/v2/pages/${id}`, {
					data: {
						status: 'publish',
						title: seed.title,
						content: constrainedLandingMarkup(seed.title, seed.kicker),
					},
				});
				link = existing[0].link;
			}
		}
		if (!link) {
			const createRes = await page.request.post('/wp-json/wp/v2/pages', {
				data: {
					status: 'publish',
					slug: seed.slug,
					title: seed.title,
					content: constrainedLandingMarkup(seed.title, seed.kicker),
				},
			});
			if (!createRes.ok()) {
				const body = await createRes.text();
				throw new Error(`Failed to create page ${seed.slug}: ${createRes.status()} ${body}`);
			}
			const body = (await createRes.json()) as { link: string };
			link = body.link;
		}
		created.push({ slug: seed.slug, link });
	}
	return created;
}

async function horizontalOverflow(page: Page): Promise<number> {
	return page.evaluate(() => {
		const docDelta =
			document.documentElement.scrollWidth - document.documentElement.clientWidth;
		return docDelta > 2 ? docDelta : 0;
	});
}

async function heroCenterOffset(page: Page): Promise<number | null> {
	return page.evaluate(() => {
		const hero =
			(document.querySelector('.stonewright-visual-hero h1') as HTMLElement | null) ||
			(document.querySelector('h1') as HTMLElement | null);
		if (!hero) {
			return null;
		}
		const rect = hero.getBoundingClientRect();
		const center = rect.left + rect.width / 2;
		const mid = window.innerWidth / 2;
		return Math.abs(center - mid);
	});
}

test.describe('Front-end visual matrix', () => {
	test('seeded landings: no overflow, hero centered, screenshots archived', async ({
		page,
	}, testInfo) => {
		fs.mkdirSync(artifactDir, { recursive: true });
		await login(page);
		const pages = await ensureSeedPages(page);

		for (const entry of pages) {
			// Prefer path from link; fall back to slug permalink.
			const url = entry.link || `/${entry.slug}/`;
			await page.goto(url, { waitUntil: 'domcontentloaded' });
			// Dismiss cookie/admin bars if any — measure document, not wp-admin.
			expect(page.url()).not.toContain('wp-login.php');

			const overflow = await horizontalOverflow(page);
			expect(overflow, `${entry.slug} horizontal overflow`).toBe(0);

			const offset = await heroCenterOffset(page);
			expect(offset, `${entry.slug} missing hero`).not.toBeNull();
			expect(
				offset as number,
				`${entry.slug} hero not centered (offset ${offset}px)`,
			).toBeLessThanOrEqual(8);

			const shot = path.join(
				artifactDir,
				`${entry.slug}-${testInfo.project.name}.png`,
			);
			await page.screenshot({ path: shot, fullPage: true });
		}
	});
});
