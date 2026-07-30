import { expect, test, type Page } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';
import { restGet, restPost, wpRestNonce } from './helpers/wp-rest';

/**
 * Front-end visual matrix — layout contracts on public pages.
 *
 * Creates seed pages via authenticated REST (with rest_route fallback for
 * wp-env/Apache), then checks overflow + hero centering. Pinned to one
 * Playwright project so the 5×2 matrix does not multiply expensive writes.
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

async function ensureSeedPages(
	page: Page,
	nonce: string,
): Promise<Array<{ slug: string; link: string }>> {
	const seeds = [
		{
			slug: 'sw-visual-dental',
			title: 'Precision dental care',
			kicker: 'Calm clinic landing for visual matrix proof.',
		},
		{
			slug: 'sw-visual-saas',
			title: 'Ship product faster',
			kicker: 'SaaS hero with constrained, centered content.',
		},
		{
			slug: 'sw-visual-restaurant',
			title: 'Reserve a table tonight',
			kicker: 'Restaurant landing centered for overflow checks.',
		},
	];

	const created: Array<{ slug: string; link: string }> = [];
	for (const seed of seeds) {
		const list = await restGet(
			page,
			`/wp/v2/pages?slug=${encodeURIComponent(seed.slug)}&status=publish,draft,private`,
			nonce,
		);
		let link = '';
		let id = 0;
		if (list.ok && Array.isArray(list.body) && list.body.length > 0) {
			const row = list.body[0] as { id: number; link?: string };
			id = Number(row.id);
			link = String(row.link || '');
			const updated = await restPost(
				page,
				`/wp/v2/pages/${id}`,
				{
					status: 'publish',
					title: seed.title,
					content: constrainedLandingMarkup(seed.title, seed.kicker),
				},
				nonce,
			);
			if (!updated.ok) {
				throw new Error(
					`Failed to update page ${seed.slug}: ${updated.status} ${JSON.stringify(updated.body)} via ${updated.url}`,
				);
			}
			const body = updated.body as { link?: string };
			link = body.link || link || `/?p=${id}`;
		} else {
			const createRes = await restPost(
				page,
				'/wp/v2/pages',
				{
					status: 'publish',
					slug: seed.slug,
					title: seed.title,
					content: constrainedLandingMarkup(seed.title, seed.kicker),
				},
				nonce,
			);
			if (!createRes.ok) {
				throw new Error(
					`Failed to create page ${seed.slug}: ${createRes.status} ${JSON.stringify(createRes.body)} via ${createRes.url}`,
				);
			}
			const body = createRes.body as { id?: number; link?: string };
			id = Number(body.id || 0);
			link = body.link || (id > 0 ? `/?p=${id}` : `/${seed.slug}/`);
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
		// One project only — admin matrix already covers 5×2 viewports.
		test.skip(
			testInfo.project.name !== 'desktop-1440-light',
			'Front-end visual proof runs once.',
		);
		fs.mkdirSync(artifactDir, { recursive: true });
		await login(page);
		const nonce = await wpRestNonce(page);
		const pages = await ensureSeedPages(page, nonce);

		for (const entry of pages) {
			const url = entry.link.startsWith('http')
				? new URL(entry.link).pathname + new URL(entry.link).search
				: entry.link || `/${entry.slug}/`;
			await page.goto(url, { waitUntil: 'domcontentloaded' });
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
