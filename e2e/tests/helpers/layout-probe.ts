/**
 * The layout check of the Stonewright admin pages, shared by `admin-layout.spec.ts`.
 *
 * Five measurements, each a list of offenders (empty is a pass) so a failing run names the element:
 *
 * - controls that share a row have the same height (within 1px);
 * - controls that share a row are centred on the same line (within 1px);
 * - no control and no text runs past the edge of the box that holds it, unless that box clips or scrolls on purpose;
 * - no two interactive controls overlap;
 * - every action group (`.sw-ui-actions`, `[data-sw-actions]`, the one row a page puts its buttons in) holds controls of
 *   the height of their size tier (`--sw-control-h`, `-sm`, `-xs`: the height a button of that size has in the layer at
 *   this width, found by measuring a hidden reference of the same size), level on one top, with one gap between
 *   neighbours (the group's own `column-gap`), on one line when they fit on one, and with the text that explains them
 *   under the row.
 *
 * "Share a row" means two controls sit in the same small layout group (their nearest common ancestor is at most three
 * levels above each) and their vertical extents overlap by at least half of the smaller one. A page whose controls
 * are in different cards or columns is not compared across them.
 */
export interface LayoutProbe {
	/** Controls on the page that were measured. */
	controls: number;
	/** Rows whose controls differ in height by more than 1px, as "row y=120: button.sw-ui-btn 40, input.sw-ui-input 32". */
	heightMismatches: string[];
	/** Rows whose controls are not centred on one line (more than 1px apart). */
	centreMismatches: string[];
	/** Controls and text that run past the box that holds them, as "span.x right edge 12px beyond div.y". */
	overflows: string[];
	/** Pairs of interactive controls whose boxes overlap, as "a.x overlaps button.y". */
	overlaps: string[];
	/** Action groups that were measured. A populated page has some: a fixture that has none proves nothing. */
	actionGroups: number;
	/** Action groups that break the rules above, as "group y=120: button.sw-ui-btn 36px, the height of its size is 32px". */
	actionGroupMismatches: string[];
}

/**
 * Runs inside the page, so it must stay self-contained: Playwright serialises the function and it can close over
 * nothing from this module. Read-only: it never clicks, types or submits.
 *
 * `scope` is a selector for the region to measure (the page content, or an open dialog); `<body>` when it matches nothing.
 */
export function probeLayout(options: { scope: string }): LayoutProbe {
	const root = (document.querySelector(options.scope) as HTMLElement | null) ?? document.body;

	const describe = (el: Element): string => {
		const classes = (el.getAttribute('class') ?? '').split(/\s+/).filter(Boolean).slice(0, 2);
		return el.tagName.toLowerCase() + (classes.length ? '.' + classes.join('.') : '');
	};
	const visible = (el: Element): boolean => {
		if (!el.getClientRects().length) {
			return false;
		}
		// Closed <details> content and anything under content-visibility: hidden has boxes but is not shown.
		if (typeof el.checkVisibility === 'function' && !el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true, contentVisibilityAuto: true })) {
			return false;
		}
		const style = getComputedStyle(el);
		if (style.visibility === 'hidden' || style.display === 'none' || Number(style.opacity) === 0) {
			return false;
		}
		const box = el.getBoundingClientRect();
		return box.width > 1 && box.height > 1;
	};
	// Chrome of WordPress itself, text for assistive technology, and markup the plugin does not own.
	const excluded = (el: Element): boolean =>
		Boolean(
			el.closest(
				'#wpadminbar, #adminmenumain, #wpfooter, .screen-reader-text, .sw-visually-hidden, .sw-ui-visually-hidden, .notice-dismiss, .sw-ui-band, .sw-ui-band-tip, [aria-hidden="true"], [data-sw-layout-ignore]',
			),
		);

	const controlSelector = [
		'button',
		'a.button',
		'a[class*="-btn"]',
		'a[class*="sw-button"]',
		'[role="button"]',
		'select',
		'input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"]):not([type="file"])',
	].join(',');
	// Tabs and the link-style button are a different component (a tab bar, an inline text link); they are not
	// measured as a control of a row.
	const controls = Array.from(root.querySelectorAll<HTMLElement>(controlSelector)).filter(
		(el) => visible(el) && !excluded(el) && !el.closest('[role="tablist"]') && !el.matches('[role="tab"], .button-link, .sw-ui-btn--link'),
	);

	// 1 + 2. Rows of controls.
	const parent = new Map<HTMLElement, HTMLElement>();
	const find = (el: HTMLElement): HTMLElement => {
		let top = el;
		while (parent.get(top) && parent.get(top) !== top) {
			top = parent.get(top) as HTMLElement;
		}
		return top;
	};
	controls.forEach((el) => parent.set(el, el));
	const depthTo = (el: Element, ancestor: Element): number => {
		let depth = 0;
		for (let node: Element | null = el; node && node !== ancestor; node = node.parentElement) {
			depth++;
		}
		return depth;
	};
	const commonAncestor = (a: Element, b: Element): Element | null => {
		for (let node: Element | null = a; node; node = node.parentElement) {
			if (node.contains(b)) {
				return node;
			}
		}
		return null;
	};
	for (let i = 0; i < controls.length; i++) {
		for (let j = i + 1; j < controls.length; j++) {
			const a = controls[i];
			const b = controls[j];
			if (a.contains(b) || b.contains(a)) {
				continue;
			}
			const boxA = a.getBoundingClientRect();
			const boxB = b.getBoundingClientRect();
			const overlap = Math.min(boxA.bottom, boxB.bottom) - Math.max(boxA.top, boxB.top);
			if (overlap < Math.min(boxA.height, boxB.height) / 2) {
				continue;
			}
			const common = commonAncestor(a, b);
			if (!common || common === document.body || common === root || depthTo(a, common) > 3 || depthTo(b, common) > 3) {
				continue;
			}
			parent.set(find(a), find(b));
		}
	}
	const rows = new Map<HTMLElement, HTMLElement[]>();
	controls.forEach((el) => {
		const key = find(el);
		rows.set(key, [...(rows.get(key) ?? []), el]);
	});
	const heightMismatches: string[] = [];
	const centreMismatches: string[] = [];
	rows.forEach((members) => {
		if (members.length < 2) {
			return;
		}
		const boxes = members.map((el) => el.getBoundingClientRect());
		const heights = boxes.map((box) => box.height);
		const centres = boxes.map((box) => box.top + box.height / 2);
		const y = Math.round(Math.min(...boxes.map((box) => box.top + window.scrollY)));
		const list = members.map((el, index) => `${describe(el)} ${Math.round(heights[index] * 10) / 10}`).join(', ');
		if (Math.max(...heights) - Math.min(...heights) > 1) {
			heightMismatches.push(`row y=${y}: ${list}`);
		}
		if (Math.max(...centres) - Math.min(...centres) > 1) {
			centreMismatches.push(`row y=${y}: ${members.map((el, index) => `${describe(el)} centre ${Math.round(centres[index] * 10) / 10}`).join(', ')}`);
		}
	});

	// 3. Overflow of controls and text. Elements placed out of the flow (tooltips, popovers) and boxes that clip or
	// scroll on purpose are not measured.
	const overflows = new Set<string>();
	const clips = (el: Element): boolean => {
		const style = getComputedStyle(el);
		return style.overflowX !== 'visible';
	};
	const outOfFlow = (el: Element): boolean => {
		for (let node: Element | null = el; node && node !== root; node = node.parentElement) {
			const position = getComputedStyle(node).position;
			if (position === 'absolute' || position === 'fixed') {
				return true;
			}
		}
		return false;
	};
	const checkAgainst = (box: DOMRect, label: string, holder: Element | null): void => {
		if (!holder) {
			return;
		}
		const holderBox = holder.getBoundingClientRect();
		const right = box.right - holderBox.right;
		const left = holderBox.left - box.left;
		if (right > 1 || left > 1) {
			overflows.add(`${label} ${Math.round(Math.max(right, left))}px beyond ${describe(holder)}`);
		}
	};
	const inClippedHolder = (el: Element): boolean => {
		for (let node = el.parentElement; node && node !== root.parentElement; node = node.parentElement) {
			if (clips(node)) {
				return true;
			}
		}
		return false;
	};
	controls.forEach((el) => {
		if (outOfFlow(el) || inClippedHolder(el)) {
			return;
		}
		// A control wider than its holder, or a label wider than the button that carries it.
		checkAgainst(el.getBoundingClientRect(), describe(el), el.parentElement);
		if (el.scrollWidth - el.clientWidth > 1 && !el.matches('input, select')) {
			overflows.add(`${describe(el)} content ${el.scrollWidth - el.clientWidth}px wider than the control`);
		}
	});
	const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
	const range = document.createRange();
	while (walker.nextNode()) {
		const node = walker.currentNode;
		const holder = node.parentElement;
		if (!node.nodeValue || !node.nodeValue.trim() || !holder) {
			continue;
		}
		if (/^(SCRIPT|STYLE|NOSCRIPT|OPTION|TEXTAREA)$/.test(holder.tagName) || !visible(holder) || excluded(holder) || outOfFlow(holder) || inClippedHolder(holder)) {
			continue;
		}
		range.selectNodeContents(node);
		// The nearest ancestor that is a box of its own (an inline span has no box edge to run past).
		let box: Element | null = holder;
		while (box && getComputedStyle(box).display === 'inline') {
			box = box.parentElement;
		}
		checkAgainst(range.getBoundingClientRect(), `text "${node.nodeValue.trim().slice(0, 24)}"`, box);
	}
	const page = document.documentElement;
	if (page.scrollWidth - page.clientWidth > 0) {
		overflows.add(`page ${page.scrollWidth - page.clientWidth}px wider than the viewport`);
	}

	// 4. Overlap of interactive controls: the measured controls, plus links with a destination and summaries.
	const interactive = Array.from(root.querySelectorAll<HTMLElement>(`${controlSelector}, a[href], summary, textarea, [role="tab"]`)).filter(
		(el) => visible(el) && !excluded(el),
	);
	const overlaps = new Set<string>();
	for (let i = 0; i < interactive.length; i++) {
		for (let j = i + 1; j < interactive.length; j++) {
			const a = interactive[i];
			const b = interactive[j];
			if (a.contains(b) || b.contains(a)) {
				continue;
			}
			// Line boxes, so a link that wraps over two lines is not taken for one tall box over its neighbours.
			const hit = Array.from(a.getClientRects()).some((boxA) =>
				Array.from(b.getClientRects()).some(
					(boxB) => Math.min(boxA.right, boxB.right) - Math.max(boxA.left, boxB.left) > 1 && Math.min(boxA.bottom, boxB.bottom) - Math.max(boxA.top, boxB.top) > 1,
				),
			);
			if (hit) {
				overlaps.add(`${describe(a)} "${(a.textContent ?? '').trim().slice(0, 20)}" overlaps ${describe(b)} "${(b.textContent ?? '').trim().slice(0, 20)}"`);
			}
		}
	}

	// 5. Action groups.
	const groups = Array.from(root.querySelectorAll<HTMLElement>('.sw-ui-actions, [data-sw-actions]')).filter((el) => visible(el) && !excluded(el));
	const actionGroupMismatches: string[] = [];
	let measuredGroups = 0;
	// The height a control of this size has in the layer at this width: a hidden reference of the same size, in the same
	// scope, so the narrow-screen tiers (44px and 40px at 782px and below) are the layer's own and not copied here.
	const referenceHeight = (group: HTMLElement, el: HTMLElement): number => {
		const tag = el.matches('select') ? 'select' : el.matches('input') ? 'input' : 'button';
		const reference = document.createElement(tag);
		reference.className = Array.from(el.classList)
			.filter((name) => ['sw-ui-btn', 'sw-ui-btn--sm', 'sw-ui-btn--xs', 'sw-ui-input', 'sw-ui-select'].includes(name))
			.join(' ');
		reference.setAttribute('aria-hidden', 'true');
		reference.setAttribute('tabindex', '-1');
		reference.setAttribute('style', 'position:absolute;visibility:hidden;pointer-events:none;');
		if (tag === 'button') {
			reference.textContent = 'x';
		}
		group.appendChild(reference);
		const height = reference.getBoundingClientRect().height;
		reference.remove();
		return height;
	};
	groups.forEach((group) => {
		const members = Array.from(group.querySelectorAll<HTMLElement>(controlSelector)).filter(
			(el) => visible(el) && !excluded(el) && !el.matches('.sw-ui-btn--icon, [role="tab"], .button-link, .sw-ui-btn--link'),
		);
		if (!members.length) {
			return;
		}
		measuredGroups++;
		const boxes = members.map((el) => el.getBoundingClientRect());
		const y = Math.round(Math.min(...boxes.map((box) => box.top + window.scrollY)));
		const where = `group ${describe(group)} y=${y}`;
		const style = getComputedStyle(group);

		// The height of the size tier. A control taller than its tier is one whose label wrapped, or one a page sized.
		members.forEach((el, index) => {
			const expected = referenceHeight(group, el);
			if (expected > 1 && Math.abs(boxes[index].height - expected) > 1) {
				actionGroupMismatches.push(`${where}: ${describe(el)} "${(el.textContent ?? '').trim().slice(0, 24)}" is ${Math.round(boxes[index].height * 10) / 10}px, the height of its size is ${expected}px`);
			}
		});

		// Lines: controls whose vertical extents overlap by more than half share a line.
		const lines: number[][] = [];
		boxes.forEach((box, index) => {
			const line = lines.find((members_) => members_.some((other) => Math.min(box.bottom, boxes[other].bottom) - Math.max(box.top, boxes[other].top) > Math.min(box.height, boxes[other].height) / 2));
			if (line) {
				line.push(index);
			} else {
				lines.push([index]);
			}
		});
		lines.forEach((line) => {
			const tops = line.map((index) => boxes[index].top);
			if (Math.max(...tops) - Math.min(...tops) > 1) {
				actionGroupMismatches.push(`${where}: tops differ by ${Math.round((Math.max(...tops) - Math.min(...tops)) * 10) / 10}px on one line (${line.map((index) => describe(members[index])).join(', ')})`);
			}
			const heights = line.map((index) => boxes[index].height);
			if (Math.max(...heights) - Math.min(...heights) > 1) {
				actionGroupMismatches.push(`${where}: heights differ on one line (${line.map((index) => `${describe(members[index])} ${Math.round(boxes[index].height * 10) / 10}`).join(', ')})`);
			}
			// One gap between neighbours: the group's own column gap.
			const ordered = [...line].sort((a, b) => boxes[a].left - boxes[b].left);
			const wanted = parseFloat(style.columnGap);
			for (let k = 1; k < ordered.length; k++) {
				const gap = boxes[ordered[k]].left - boxes[ordered[k - 1]].right;
				if (Number.isFinite(wanted) && Math.abs(gap - wanted) > 1) {
					actionGroupMismatches.push(`${where}: gap of ${Math.round(gap * 10) / 10}px between ${describe(members[ordered[k - 1]])} and ${describe(members[ordered[k]])}, the group's gap is ${wanted}px`);
				}
			}
		});

		// Controls that fit on one line are on one line: a row that stacks while there is room is the row that was reported.
		if (members.length > 1 && lines.length > 1) {
			const gap = Number.isFinite(parseFloat(style.columnGap)) ? parseFloat(style.columnGap) : 0;
			const needed = boxes.reduce((sum, box) => sum + box.width, 0) + gap * (members.length - 1);
			if (needed <= group.getBoundingClientRect().width - 1) {
				actionGroupMismatches.push(`${where}: ${lines.length} lines for ${members.length} controls that fit on one`);
			}
		}

		// What explains the row is under it.
		const note = group.nextElementSibling;
		if (note instanceof HTMLElement && note.matches('.sw-rescue-note, .sw-ui-hint, .sw-ui-field__help, [data-sw-action-help]') && visible(note)) {
			const under = note.getBoundingClientRect().top - Math.max(...boxes.map((box) => box.bottom));
			if (under < -1) {
				actionGroupMismatches.push(`${where}: its help text starts ${Math.round(-under)}px above the bottom of the row`);
			}
		}
	});

	return {
		controls: controls.length,
		heightMismatches,
		centreMismatches,
		overflows: Array.from(overflows),
		overlaps: Array.from(overlaps),
		actionGroups: measuredGroups,
		actionGroupMismatches,
	};
}
