#!/usr/bin/env node

/**
 * Admin UI consistency: a button or an input is sized by the control-size tokens of the shared layer
 * (plugin/assets/admin/sw-ui.css), never by a page.
 *
 * Reads the Stonewright admin stylesheets and flags a rule that gives a height, a min-height, a max-height, a
 * line-height or a padding to a selector that targets a button, an input or a select, outside sw-ui.css. The older
 * stylesheets that still define their own buttons are listed in `LEGACY` with the reason; a rule there is not flagged,
 * and the list only shrinks.
 *
 * A row of actions is the layer's too: a page that groups its buttons uses `sw-ui-actions` (`Button::group`), and a
 * page stylesheet places the group but never lays it out. The check flags a rule of a page stylesheet whose last
 * selector is the group itself and that sets how the row is arranged (display, gap, alignment, direction, wrapping,
 * height or padding), and a page of the Rescue and Changes screens that prints a button of the older `sw-btn` system.
 */

import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, resolve, sep } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const assets = join(root, 'plugin', 'assets');

/** Stylesheets that are the layer itself, or still carry their own button system. */
const LEGACY = new Map([
	['plugin/assets/admin/sw-ui.css', 'the layer: the control-size tokens are defined and applied here'],
	['plugin/assets/admin/shell.css', 'the older .sw-btn and core .button styles of the shell, kept to the layer sizes (AdminShellCssTest)'],
	['plugin/assets/admin/block-queue.css', 'the status panel inside a native editor, not an admin page'],
	['plugin/assets/admin/design-studio.css', 'not loaded: the Design Studio page is not registered'],
	['plugin/assets/admin/visual-workspace.css', 'not loaded: the Visual Workspace page is not registered'],
]);

const SIZING = /^(?:height|min-height|max-height|line-height|padding(?:-[a-z]+)*)$/;
const CONTROL = /(?:^|[\s>+~,(.:#\[])(?:button|input|select|textarea)\b|\.button(?![\w-])|[.\w-]*(?:-btn|-button)(?![\w])|\[type=/;

function stylesheets(directory) {
	const found = [];
	if (!existsSync(directory)) return found;
	for (const name of readdirSync(directory)) {
		const path = join(directory, name);
		if (statSync(path).isDirectory()) found.push(...stylesheets(path));
		else if (name.endsWith('.css')) found.push(path);
	}
	return found;
}

const findings = [];
for (const file of stylesheets(assets)) {
	const name = relative(root, file).split(sep).join('/');
	if (LEGACY.has(name)) continue;
	const css = readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
	for (const [, selectorText, body] of css.matchAll(/([^{}@][^{}]*)\{([^{}]*)\}/g)) {
		const selector = selectorText.trim();
		// A toggle or a checkbox is not a row control; a text area is a block of its own.
		if (!CONTROL.test(selector) || /toggle|switch|checkbox|textarea/.test(selector)) continue;
		for (const declaration of body.split(';')) {
			const [property] = declaration.split(':');
			if (property && SIZING.test(property.trim())) {
				findings.push(`${name}: "${selector.replace(/\s+/g, ' ')}" sets ${property.trim()}`);
			}
		}
	}
}

/** The properties that lay a row of actions out: the layer's `.sw-ui-actions` owns them. */
const ROW_LAYOUT = /^(?:display|gap|row-gap|column-gap|align-items|align-content|flex-direction|flex-wrap|flex-flow|height|min-height|max-height|padding(?:-[a-z]+)*)$/;
const GROUP_SELECTOR = /(?:\.sw-ui-actions(?![\w-])|\[data-sw-actions\])$/;

const rowFindings = [];
for (const file of stylesheets(assets)) {
	const name = relative(root, file).split(sep).join('/');
	if (name === 'plugin/assets/admin/sw-ui.css') continue;
	const css = readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
	for (const [, selectorText, body] of css.matchAll(/([^{}@][^{}]*)\{([^{}]*)\}/g)) {
		for (const selector of selectorText.split(',').map((part) => part.trim())) {
			// Only the last compound selector counts: ".page .sw-ui-actions > form" places a form, not the row.
			if (!GROUP_SELECTOR.test(selector)) continue;
			for (const declaration of body.split(';')) {
				const [property] = declaration.split(':');
				if (property && ROW_LAYOUT.test(property.trim())) {
					rowFindings.push(`${name}: "${selector.replace(/\s+/g, ' ')}" sets ${property.trim()} on a row of actions`);
				}
			}
		}
	}
}

/** The screens of Rescue and Changes print their buttons with the layer's helpers, never with the older sw-btn classes. */
const SCREENS = ['RescuePage.php', 'ChangesPage.php', 'ChangeUndo.php', 'ChangeDetail.php'];
for (const screen of SCREENS) {
	const path = join(root, 'plugin', 'includes', 'Admin', screen);
	if (!existsSync(path)) continue;
	readFileSync(path, 'utf8').split('\n').forEach((line, index) => {
		if (/\bsw-btn\b/.test(line)) {
			rowFindings.push(`plugin/includes/Admin/${screen}:${index + 1}: prints a button of the older sw-btn system; use Ui\\Button and Button::group`);
		}
	});
}

if (findings.length > 0 || rowFindings.length > 0) {
	if (findings.length > 0) {
		console.error('Admin controls are sized by the control-size tokens of plugin/assets/admin/sw-ui.css, not by a page:');
		for (const finding of findings) console.error(`  ${finding}`);
		console.error('Use the tokens (--sw-control-h, --sw-control-pad-x, ...) in sw-ui.css; see "Admin UI consistency" in AGENTS.md.');
	}
	if (rowFindings.length > 0) {
		console.error('A row of actions is the layer\'s `sw-ui-actions`: a page places it and does not lay it out:');
		for (const finding of rowFindings) console.error(`  ${finding}`);
		console.error('Put the buttons of a group in Button::group() (or a `sw-ui-actions` row); see "Admin UI consistency" in AGENTS.md.');
	}
	process.exit(1);
}
console.log('Admin control sizing: no page stylesheet sizes a button or an input or lays out a row of actions.');
