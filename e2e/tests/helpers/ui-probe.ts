import type { Page } from '@playwright/test';

/**
 * What the UI contract measures on one rendered page.
 *
 * Every field is a list of offenders (empty is a pass) or a number in CSS pixels, so a failing run says which
 * element broke the contract, not only that something did.
 */
export interface UiProbe {
	/** Pixels by which the page is wider than the viewport (0 when it fits). */
	horizontalOverflow: number;
	/** Top edge of the first visible h1 in page coordinates, or null when the page has none. */
	h1Top: number | null;
	/** Lowest bottom edge, in viewport pixels, of sticky or fixed chrome that stays on screen after scrolling. */
	stickyChromeBottom: number;
	/** Visible text set under 12px, as "tag.class 11px". */
	smallText: string[];
	/** Controls smaller than 24 x 24 CSS pixels that are not links inside a sentence, as "tag.class 20x20". */
	smallTargets: string[];
	/** Ids used more than once in the document. */
	duplicateIds: string[];
	/** Links, buttons and summaries with no accessible name. */
	unnamedControls: string[];
	/** Inputs, selects and textareas with no label, aria-label, aria-labelledby or title. */
	unlabelledFields: string[];
	/** Plugin-owned notices or callouts inside the "other WordPress notices" drawer. */
	drawerOwnContent: string[];
	/** Primary buttons whose painted fill is not the accent fill of the layer in scope. */
	wrongPrimaryFills: string[];
}

/**
 * Runs inside the page, so it must stay self-contained: Playwright serialises the function and it can close over
 * nothing from this module. Read-only: it never clicks, types or submits (it only scrolls, then scrolls back).
 */
export function probeUi(options: { scope: string }): UiProbe {
	const root = (document.querySelector(options.scope) as HTMLElement | null) ?? document.body;

	const describe = (el: Element): string => {
		const classes = (el.getAttribute('class') ?? '').split(/\s+/).filter(Boolean).slice(0, 3);
		return el.tagName.toLowerCase() + (classes.length ? '.' + classes.join('.') : '');
	};
	const visible = (el: Element): boolean => {
		if (!el.getClientRects().length) {
			return false;
		}
		const style = getComputedStyle(el);
		if (style.visibility === 'hidden' || style.display === 'none') {
			return false;
		}
		const box = el.getBoundingClientRect();
		return !(box.width <= 1 && box.height <= 1);
	};
	const assistiveOnly = (el: Element): boolean =>
		Boolean(el.closest('.screen-reader-text, .sw-visually-hidden, .sw-ui-visually-hidden'));

	// 1. Text under 12px.
	const smallText = new Set<string>();
	const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
	while (walker.nextNode()) {
		const node = walker.currentNode;
		const parent = node.parentElement;
		if (!node.nodeValue || !node.nodeValue.trim() || !parent) {
			continue;
		}
		if (/^(SCRIPT|STYLE|NOSCRIPT|OPTION|TEXTAREA)$/.test(parent.tagName) || !visible(parent) || assistiveOnly(parent)) {
			continue;
		}
		const size = parseFloat(getComputedStyle(parent).fontSize);
		if (size < 12) {
			smallText.add(`${describe(parent)} ${size}px`);
		}
	}

	// 2. Targets under 24 x 24 (WCAG 2.5.8). A link inside a sentence is exempt; the spacing exception is not modelled.
	const smallTargets = new Set<string>();
	const targetSelector =
		'a[href], button, input:not([type=hidden]), select, textarea, summary, [role=button], [role=tab], [role=switch], [tabindex]:not([tabindex="-1"])';
	root.querySelectorAll<HTMLElement>(targetSelector).forEach((el) => {
		if (!visible(el) || assistiveOnly(el)) {
			return;
		}
		const box = el.getBoundingClientRect();
		let width = box.width;
		let height = box.height;
		if (el instanceof HTMLInputElement && (el.type === 'checkbox' || el.type === 'radio') && el.labels && el.labels.length) {
			const labelBox = el.labels[0].getBoundingClientRect();
			width = Math.max(width, labelBox.width);
			height = Math.max(height, labelBox.height);
		}
		const inSentence =
			el.tagName === 'A' &&
			getComputedStyle(el).display === 'inline' &&
			Boolean(el.closest('p, li, td, dd, .description, span, label')) &&
			Boolean(el.parentElement) &&
			(el.parentElement?.textContent ?? '').trim().length > (el.textContent ?? '').trim().length + 8;
		if ((width < 24 || height < 24) && !inSentence) {
			smallTargets.add(`${describe(el)} ${Math.round(width)}x${Math.round(height)}`);
		}
	});

	// 3. Duplicate ids.
	const seen: Record<string, number> = {};
	document.querySelectorAll('[id]').forEach((el) => {
		seen[el.id] = (seen[el.id] ?? 0) + 1;
	});
	const duplicateIds = Object.keys(seen).filter((id) => seen[id] > 1);

	// 4. Names.
	const textOf = (id: string): string => (document.getElementById(id)?.textContent ?? '').trim();
	const nameOf = (el: HTMLElement): string => {
		let name = (el.getAttribute('aria-label') ?? '').trim();
		const labelledby = el.getAttribute('aria-labelledby');
		if (!name && labelledby) {
			name = labelledby
				.split(/\s+/)
				.map(textOf)
				.join(' ')
				.trim();
		}
		if (!name && (el instanceof HTMLInputElement || el instanceof HTMLSelectElement || el instanceof HTMLTextAreaElement) && el.labels?.length) {
			name = Array.from(el.labels)
				.map((label) => label.textContent ?? '')
				.join(' ')
				.trim();
		}
		if (!name && !/^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName)) {
			name = (el.textContent ?? '').trim();
		}
		if (!name && el instanceof HTMLInputElement && /^(submit|button|reset)$/.test(el.type)) {
			name = el.value.trim();
		}
		if (!name) {
			name = (el.getAttribute('title') ?? '').trim();
		}
		return name;
	};
	const unnamedControls = new Set<string>();
	root.querySelectorAll<HTMLElement>('a[href], button, summary, [role=button]').forEach((el) => {
		if (visible(el) && !nameOf(el)) {
			unnamedControls.add(describe(el));
		}
	});
	const unlabelledFields = new Set<string>();
	root
		.querySelectorAll<HTMLElement>('input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=reset]), select, textarea')
		.forEach((el) => {
			const choice = el instanceof HTMLInputElement && (el.type === 'checkbox' || el.type === 'radio');
			if ((visible(el) || choice) && !nameOf(el)) {
				unlabelledFields.add(`${describe(el)} name=${(el.getAttribute('name') ?? '').slice(0, 24)}`);
			}
		});

	// 5. Plugin-owned content inside the foreign-notice drawer.
	const ownedClass = (el: Element): boolean =>
		Array.from(el.classList).some((name) => name.startsWith('sw-') || name.startsWith('stonewright-'));
	const drawerOwnContent: string[] = [];
	document.querySelectorAll('.sw-notice-drawer').forEach((drawer) => {
		drawer.querySelectorAll('.notice, .sw-notice, .sw-callout, [class*="stonewright-"]').forEach((el) => {
			if (ownedClass(el) && !el.classList.contains('sw-notice-drawer') && !el.closest('[data-sw-notice-toggle]')) {
				drawerOwnContent.push(describe(el));
			}
		});
	});

	// 6. Primary buttons are painted with the accent fill of the layer that styles them.
	const paint = (anchor: Element, token: string): string => {
		const probe = document.createElement('i');
		probe.style.background = `var(${token})`;
		anchor.parentElement?.appendChild(probe);
		const colour = getComputedStyle(probe).backgroundColor;
		probe.remove();
		return colour;
	};
	const wrongPrimaryFills: string[] = [];
	root.querySelectorAll<HTMLElement>('input[type=submit].button-primary, button.button-primary, a.button-primary, .sw-ui-btn--primary').forEach((el) => {
		if (!visible(el)) {
			return;
		}
		const token = el.classList.contains('sw-ui-btn--primary') ? '--sw-accent-fill' : '--sw-brand-fill';
		const expected = paint(el, token);
		const actual = getComputedStyle(el).backgroundColor;
		if (expected !== actual) {
			wrongPrimaryFills.push(`${describe(el)} painted ${actual}, expected ${expected} (${token})`);
		}
	});

	// 7. Layout: first h1, overflow, and the chrome that stays on screen while scrolling.
	const h1 = Array.from(root.querySelectorAll('h1')).find(visible);
	const h1Top = h1 ? Math.round(h1.getBoundingClientRect().top + window.scrollY) : null;
	const doc = document.documentElement;
	const horizontalOverflow = Math.max(0, doc.scrollWidth - doc.clientWidth);

	const originalScroll = window.scrollY;
	window.scrollTo(0, Math.min(1500, Math.max(0, doc.scrollHeight - window.innerHeight)));
	let stickyChromeBottom = 0;
	document.querySelectorAll<HTMLElement>('body *').forEach((el) => {
		const style = getComputedStyle(el);
		if ((style.position !== 'sticky' && style.position !== 'fixed') || !visible(el)) {
			return;
		}
		const box = el.getBoundingClientRect();
		if (box.width < window.innerWidth * 0.5 || box.top < -1 || box.top > 300) {
			return;
		}
		stickyChromeBottom = Math.max(stickyChromeBottom, Math.round(box.bottom));
	});
	window.scrollTo(0, originalScroll);

	return {
		horizontalOverflow,
		h1Top,
		stickyChromeBottom,
		smallText: Array.from(smallText),
		smallTargets: Array.from(smallTargets),
		duplicateIds,
		unnamedControls: Array.from(unnamedControls),
		unlabelledFields: Array.from(unlabelledFields),
		drawerOwnContent,
		wrongPrimaryFills,
	};
}

/** Measure the page that is open. The scope is the element whose contents are held to the contract. */
export async function measureUi(page: Page, scope = '#wpbody-content'): Promise<UiProbe> {
	return page.evaluate(probeUi, { scope });
}
