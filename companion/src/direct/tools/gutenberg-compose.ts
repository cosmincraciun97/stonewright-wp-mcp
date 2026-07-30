import { assertToolEnabled } from "../writes.js";
import type { ResolvedSite } from "../sites-config.js";

export type GutenbergBlockSpec =
  | { type: "heading"; level?: number; content: string; align?: string }
  | { type: "paragraph"; content: string; align?: string }
  | {
      type: "image";
      url: string;
      alt?: string;
      id?: number;
      width?: number;
      height?: number;
    }
  | { type: "columns"; columns?: number; children?: GutenbergBlockSpec[] }
  | { type: "group"; layout?: string; children?: GutenbergBlockSpec[] }
  | {
      type: "buttons";
      children?: Array<{
        type: "button";
        text: string;
        url?: string;
        style?: string;
      }>;
    }
  | { type: "button"; text: string; url?: string; style?: string }
  | { type: "list"; ordered?: boolean; items: string[] }
  | { type: "separator" }
  | { type: "spacer"; height?: string }
  | { type: "html"; content: string }
  | { type: "raw"; markup: string };

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function attrsJson(attrs: Record<string, unknown>): string {
  const entries = Object.entries(attrs).filter(
    ([, v]) => v !== undefined && v !== null && v !== "",
  );
  if (entries.length === 0) return "";
  return ` ${JSON.stringify(Object.fromEntries(entries))}`;
}

function composeBlock(spec: GutenbergBlockSpec): string {
  switch (spec.type) {
    case "raw":
      return spec.markup;
    case "html":
      return `<!-- wp:html -->\n${spec.content}\n<!-- /wp:html -->`;
    case "heading": {
      const level = Math.min(Math.max(spec.level ?? 2, 1), 6);
      const attrs: Record<string, unknown> = { level };
      if (spec.align) attrs.align = spec.align;
      const tag = `h${level}`;
      return `<!-- wp:heading${attrsJson(attrs)} -->\n<${tag} class="wp-block-heading">${escapeHtml(spec.content)}</${tag}>\n<!-- /wp:heading -->`;
    }
    case "paragraph": {
      const attrs: Record<string, unknown> = {};
      if (spec.align) attrs.align = spec.align;
      const alignClass = spec.align ? ` has-text-align-${spec.align}` : "";
      return `<!-- wp:paragraph${attrsJson(attrs)} -->\n<p class="wp-block-paragraph${alignClass}">${escapeHtml(spec.content)}</p>\n<!-- /wp:paragraph -->`;
    }
    case "image": {
      const attrs: Record<string, unknown> = {};
      if (spec.id !== undefined) attrs.id = spec.id;
      if (spec.width !== undefined) attrs.width = spec.width;
      if (spec.height !== undefined) attrs.height = spec.height;
      const alt = escapeHtml(spec.alt ?? "");
      const size = [
        spec.width !== undefined ? `width="${spec.width}"` : "",
        spec.height !== undefined ? `height="${spec.height}"` : "",
      ]
        .filter(Boolean)
        .join(" ");
      const img = `<img src="${escapeHtml(spec.url)}" alt="${alt}"${size ? ` ${size}` : ""}/>`;
      return `<!-- wp:image${attrsJson(attrs)} -->\n<figure class="wp-block-image">${img}</figure>\n<!-- /wp:image -->`;
    }
    case "columns": {
      const children = (spec.children ?? []).map(composeBlock).join("\n\n");
      const cols = Math.min(
        Math.max(spec.columns ?? Math.max((spec.children ?? []).length, 2), 1),
        6,
      );
      const columnBlocks =
        (spec.children ?? []).length > 0
          ? (spec.children ?? [])
              .map((child) => {
                if (child.type === "group" || child.type === "columns") {
                  return `<!-- wp:column -->\n<div class="wp-block-column">\n\n${composeBlock(child)}\n\n</div>\n<!-- /wp:column -->`;
                }
                return `<!-- wp:column -->\n<div class="wp-block-column">\n\n${composeBlock(child)}\n\n</div>\n<!-- /wp:column -->`;
              })
              .join("\n\n")
          : Array.from(
              { length: cols },
              () =>
                '<!-- wp:column -->\n<div class="wp-block-column"></div>\n<!-- /wp:column -->',
            ).join("\n\n");
      return `<!-- wp:columns -->\n<div class="wp-block-columns">\n\n${columnBlocks || children}\n\n</div>\n<!-- /wp:columns -->`;
    }
    case "group": {
      const children = (spec.children ?? []).map(composeBlock).join("\n\n");
      const attrs: Record<string, unknown> = {};
      if (spec.layout) attrs.layout = { type: spec.layout };
      return `<!-- wp:group${attrsJson(attrs)} -->\n<div class="wp-block-group">\n\n${children}\n\n</div>\n<!-- /wp:group -->`;
    }
    case "buttons": {
      const buttons = (spec.children ?? [])
        .map((btn) => composeBlock(btn))
        .join("\n\n");
      return `<!-- wp:buttons -->\n<div class="wp-block-buttons">\n\n${buttons}\n\n</div>\n<!-- /wp:buttons -->`;
    }
    case "button": {
      const attrs: Record<string, unknown> = {};
      if (spec.style) attrs.className = `is-style-${spec.style}`;
      const href = escapeHtml(spec.url ?? "#");
      return `<!-- wp:button${attrsJson(attrs)} -->\n<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="${href}">${escapeHtml(spec.text)}</a></div>\n<!-- /wp:button -->`;
    }
    case "list": {
      const ordered = spec.ordered === true;
      const tag = ordered ? "ol" : "ul";
      const block = ordered ? "list" : "list";
      const attrs: Record<string, unknown> = {};
      if (ordered) attrs.ordered = true;
      const items = spec.items
        .map(
          (item) =>
            `<!-- wp:list-item -->\n<li>${escapeHtml(item)}</li>\n<!-- /wp:list-item -->`,
        )
        .join("\n");
      return `<!-- wp:${block}${attrsJson(attrs)} -->\n<${tag} class="wp-block-list">\n${items}\n</${tag}>\n<!-- /wp:${block} -->`;
    }
    case "separator":
      return '<!-- wp:separator -->\n<hr class="wp-block-separator has-alpha-channel-opacity"/>\n<!-- /wp:separator -->';
    case "spacer": {
      const height = spec.height ?? "40px";
      return `<!-- wp:spacer ${JSON.stringify({ height })} -->\n<div style="height:${escapeHtml(height)}" aria-hidden="true" class="wp-block-spacer"></div>\n<!-- /wp:spacer -->`;
    }
  }
}

/**
 * Local-only helper: compose Gutenberg block markup from a simple JSON spec.
 * No network calls. Pair with stonewright-content-update to write the markup.
 */
export function gutenbergCompose(
  ctx: { site: ResolvedSite },
  input: { blocks: GutenbergBlockSpec[] },
) {
  assertToolEnabled(ctx.site, "stonewright-gutenberg-compose");
  if (!Array.isArray(input.blocks) || input.blocks.length === 0) {
    throw new Error("blocks must be a non-empty array");
  }
  const markup = input.blocks.map(composeBlock).join("\n\n");
  return {
    markup,
    block_count: input.blocks.length,
    usage:
      "Pass markup as content to stonewright-content-create-page, stonewright-content-create-post, or stonewright-content-update.",
  };
}
