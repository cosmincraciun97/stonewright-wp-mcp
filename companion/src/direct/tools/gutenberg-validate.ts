import { createHash } from "node:crypto";
import { assertToolEnabled } from "../writes.js";
import {
  addressableBlocks,
  countBlocks,
  normalizeBlockName,
  parseBlockMarkup,
  walkBlocks,
  writtenBlockNames,
  type BlockDiagnostic,
} from "../block-markup.js";
import { readBlockRegistry } from "../block-registry.js";
import { scanBlockTree } from "../block-guard.js";
import type { CustomCodeFinding } from "../custom-code-guard.js";
import type { DirectToolContext } from "./types.js";

function restBaseForType(type: string): string {
  if (type === "page") return "pages";
  if (type === "post" || type === "") return "posts";
  return type;
}

const MAX_DIAGNOSTICS = 50;

/**
 * Reads the post's block markup properly: the comment delimiters must balance and match, every block's
 * attributes must be a valid JSON object, and, where the site's block registry can be read, every block
 * name must be one the site has. The custom-code guard runs over every block, html and classic blocks
 * included, so the finding names the block. Read-only.
 */
export async function gutenbergValidate(
  ctx: DirectToolContext,
  input: { post_id: number; type?: string },
) {
  assertToolEnabled(ctx.site, "stonewright-gutenberg-validate");
  const base = restBaseForType(input.type ?? "page");
  const row = await ctx.client.get<{
    id?: number;
    content?: { raw?: string; rendered?: string };
  }>(`/wp/v2/${base}/${input.post_id}`, {
    query: { context: "edit", _fields: "id,content" },
  });

  const raw = String(row.content?.raw ?? "");
  const rendered = String(row.content?.rendered ?? "");
  const { blocks, diagnostics: found } = parseBlockMarkup(raw);
  const diagnostics: BlockDiagnostic[] = [...found];

  const named = new Set<string>();
  walkBlocks(blocks, (block) => {
    if (block.name !== null) named.add(block.name);
  });
  let registryStatus: "checked" | "unavailable" = "unavailable";
  let registryNote: string | undefined;
  const unknown: string[] = [];
  if (named.size > 0) {
    const registry = await readBlockRegistry(ctx.client, ctx.site.url);
    registryStatus = registry.status;
    if (registry.status === "checked") {
      const reported = new Set<string>();
      walkBlocks(blocks, (block, path) => {
        if (block.name === null || registry.names.has(normalizeBlockName(block.name))) return;
        if (reported.has(block.name)) return;
        reported.add(block.name);
        unknown.push(block.name);
        diagnostics.push({
          severity: "warning",
          code: "unknown_block",
          message: `The block ${block.name} is not registered on this site (its plugin or theme may be inactive).`,
          line: block.line ?? 0,
          block: block.name,
          path,
        });
      });
    } else {
      registryNote = `The site's block list could not be read (${registry.reason}); block names were not checked.`;
    }
  }

  const customCode: CustomCodeFinding[] = scanBlockTree(blocks);

  const suspicious: string[] = [];
  if (raw.length > 0 && rendered.trim().length === 0) {
    suspicious.push("raw content present but rendered output empty");
  }
  for (const diagnostic of diagnostics) {
    suspicious.push(`${diagnostic.message} (line ${diagnostic.line})`);
  }
  if (customCode.length > 0) {
    const kinds = [...new Set(customCode.map((finding) => finding.kind))].join(", ");
    const where = [...new Set(customCode.map((finding) => finding.path))].slice(0, 8).join(", ");
    suspicious.push(
      `custom code (${kinds}) found in ${where}: Direct mode refuses to write it; it needs Plugin mode and its custom-code approval`,
    );
  }

  const names = writtenBlockNames(blocks);
  return {
    post_id: input.post_id,
    // Markup that WordPress reads without losing a block.
    valid: !diagnostics.some((diagnostic) => diagnostic.severity === "error"),
    has_blocks: addressableBlocks(blocks).some((block) => block.name !== null),
    block_count: countBlocks(blocks),
    block_names: [...new Set(names)],
    raw_length: raw.length,
    rendered_length: rendered.length,
    // The hash stonewright-blocks-batch-mutate takes as expected_content_hash.
    content_hash: createHash("sha256").update(raw).digest("hex"),
    registry: registryStatus,
    ...(registryNote !== undefined ? { registry_note: registryNote } : {}),
    unknown_blocks: unknown,
    diagnostics: diagnostics.slice(0, MAX_DIAGNOSTICS),
    custom_code: customCode.slice(0, MAX_DIAGNOSTICS),
    suspicious,
  };
}
