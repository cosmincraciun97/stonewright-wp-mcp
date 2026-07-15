/**
 * Minimal DesignSpec → Gutenberg block markup for Direct mode blueprint apply.
 * Mirrors the shape produced by plugin GutenbergSpecRenderer + serialize.
 */

type SpecBlock = {
	type?: string;
	level?: number;
	text?: string;
	url?: string;
	alt?: string;
	height?: number | string;
	blocks?: SpecBlock[];
	style?: Record<string, unknown>;
	variant?: string;
};

type SpecSection = {
	id?: string;
	style?: { background?: { color?: string }; padding?: string };
	background?: { color?: string };
	blocks?: SpecBlock[];
};

export type DesignSpec = {
	page?: { title?: string };
	tokens?: {
		colors?: Record<string, string>;
		typography?: Record<string, { font_family?: string } | string>;
	};
	sections?: SpecSection[];
};

function esc(s: string): string {
	return s
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;');
}

function heading(level: number, text: string, font?: string): string {
	const l = Math.max(1, Math.min(6, level || 2));
	const attrs =
		l === 1
			? font
				? ` ${JSON.stringify({ style: { typography: { fontFamily: font } } })}`
				: ''
			: ` ${JSON.stringify({
					level: l,
					...(font ? { style: { typography: { fontFamily: font } } } : {}),
				})}`;
	return `<!-- wp:heading${attrs} -->\n<h${l} class="wp-block-heading">${esc(text)}</h${l}>\n<!-- /wp:heading -->\n\n`;
}

function paragraph(text: string): string {
	return `<!-- wp:paragraph -->\n<p>${esc(text)}</p>\n<!-- /wp:paragraph -->\n\n`;
}

function image(url: string, alt: string): string {
	return `<!-- wp:image {"sizeSlug":"large"} -->\n<figure class="wp-block-image size-large"><img src="${esc(url)}" alt="${esc(alt)}"/></figure>\n<!-- /wp:image -->\n\n`;
}

function button(text: string, url: string, bg?: string, fg?: string): string {
	const style =
		bg || fg
			? ` style="${bg ? `background-color:${esc(bg)};` : ''}${fg ? `color:${esc(fg)};` : ''}"`
			: '';
	const attrs = bg || fg ? ` ${JSON.stringify({ style: { color: { background: bg, text: fg } } })}` : '';
	return `<!-- wp:buttons -->\n<div class="wp-block-buttons"><!-- wp:button${attrs} -->\n<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="${esc(url)}"${style}>${esc(text)}</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->\n\n`;
}

function spacer(height: number): string {
	const h = Math.max(1, height || 40);
	return `<!-- wp:spacer {"height":"${h}px"} -->\n<div style="height:${h}px" aria-hidden="true" class="wp-block-spacer"></div>\n<!-- /wp:spacer -->\n\n`;
}

function separator(): string {
	return `<!-- wp:separator -->\n<hr class="wp-block-separator has-alpha-channel-opacity"/>\n<!-- /wp:separator -->\n\n`;
}

function group(inner: string, layout: 'constrained' | 'flex' = 'flex'): string {
	const attrs = JSON.stringify({
		layout: layout === 'flex' ? { type: 'flex', flexWrap: 'wrap' } : { type: 'constrained', contentSize: '1200px' },
	});
	const tag = layout === 'constrained' ? 'section' : 'div';
	return `<!-- wp:group ${attrs} -->\n<${tag} class="wp-block-group">\n${inner}</${tag}>\n<!-- /wp:group -->\n\n`;
}

function renderBlock(block: SpecBlock, tokens: DesignSpec['tokens']): string {
	const type = String(block.type ?? '');
	const colors = tokens?.colors ?? {};
	const headingFont =
		typeof tokens?.typography?.heading === 'object'
			? tokens?.typography?.heading?.font_family
			: typeof tokens?.typography?.heading === 'string'
				? tokens.typography.heading
				: undefined;

	switch (type) {
		case 'heading':
			return heading(Number(block.level ?? 2), String(block.text ?? ''), headingFont);
		case 'paragraph':
			return paragraph(String(block.text ?? ''));
		case 'image':
			return image(String(block.url ?? ''), String(block.alt ?? ''));
		case 'button': {
			const bg =
				(block.style?.background as string | undefined) ||
				colors.primary ||
				colors.accent ||
				'';
			const fg = (block.style?.color as string | undefined) || '#ffffff';
			return button(String(block.text ?? 'Click'), String(block.url ?? '#'), bg, fg);
		}
		case 'spacer': {
			const h = block.height;
			const n = typeof h === 'string' ? parseInt(h, 10) : Number(h ?? 40);
			return spacer(Number.isFinite(n) ? n : 40);
		}
		case 'separator':
			return separator();
		case 'row':
		case 'column': {
			const kids = (block.blocks ?? []).map((c) => renderBlock(c, tokens)).join('');
			return group(kids, 'flex');
		}
		default:
			return '';
	}
}

export function serializeDesignSpec(spec: DesignSpec): string {
	const tokens = spec.tokens ?? {};
	const parts: string[] = [];
	for (const section of spec.sections ?? []) {
		const inner = (section.blocks ?? []).map((b) => renderBlock(b, tokens)).join('');
		parts.push(group(inner, 'constrained'));
	}
	return parts.join('').trim() + '\n';
}
