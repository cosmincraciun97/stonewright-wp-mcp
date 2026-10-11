import { createHash } from "node:crypto";
import { assertToolEnabled, assertWriteAllowed, DirectSafetyBlockedError } from "../writes.js";
import { appendDirectAudit } from "../audit.js";
import {
  addressableBlocks,
  blockAt,
  countBlocks,
  normalizeBlockName,
  parseBlockMarkup,
  serializeBlocks,
  writtenBlockNames,
  type BlockNode,
} from "../block-markup.js";
import { isKnownBlock, readBlockRegistry, type BlockRegistry } from "../block-registry.js";
import { scanBlockTree } from "../block-guard.js";
import {
  refuseCustomCode,
  scanMarkupFields,
  scanTextFields,
  type CustomCodeFinding,
} from "../custom-code-guard.js";
import { storePreImage } from "../preimage.js";
import { attachVerification, verifyWrite } from "../verify.js";
import { probeAfterWrite, type ProbeOptions } from "../probe.js";
import { WpRestError } from "../wp-rest-client.js";
import { collectionFor } from "./content.js";
import type { DirectToolContext } from "./types.js";

/**
 * Surgical edits to the block content of a post: insert, update or remove blocks by path, instead of
 * sending the whole content back. The argument names are the Plugin ability's (`post_id`, `operations`,
 * `expected_content_hash`, `dry_run`), so a client that knows one knows the other.
 *
 * A write needs the hash of the content it was planned against (compare-and-set, checked again just before
 * the write), saves a copy of the post first, reads the post back, and refuses custom code in what the
 * operations add. The batch is all or nothing: any operation that cannot be applied stops it before a request
 * is sent. Paths address blocks the way the editor does: top-level block 1, its second inner block is [1, 1].
 */

export const BLOCKS_MUTATE_TOOL = "stonewright-blocks-batch-mutate";

export interface BlockSpec {
  blockName?: string;
  /** Alias of blockName. */
  name?: string;
  attrs?: Record<string, unknown>;
  innerHTML?: string;
  /** Strings and null placeholders for the inner blocks, in order. */
  innerContent?: Array<string | null>;
  innerBlocks?: BlockSpec[];
}

export interface BlockOperation {
  action: "insert" | "update" | "remove";
  /** update, remove: the block. insert: the parent block ([] or absent for the top level). */
  path?: number[];
  position?: number;
  before_path?: number[];
  after_path?: number[];
  block?: BlockSpec;
  attrs?: Record<string, unknown>;
  innerHTML?: string;
}

export type BlocksMutateInput = {
  post_id: number;
  type?: string | undefined;
  operations: BlockOperation[];
  expected_content_hash?: string | undefined;
  dry_run?: boolean | undefined;
} & ProbeOptions;

const MAX_INSERTED_BLOCKS = 500;
const MAX_DEPTH = 24;
const BLOCK_COMMENT = /<!--\s*\/?\s*wp:/i;
const BLOCK_NAME = /^[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?$/;
const SEPARATOR = "\n\n";

const sha256 = (text: string): string => createHash("sha256").update(text).digest("hex");

function refuse(ctx: DirectToolContext, code: string, message: string): DirectSafetyBlockedError {
  return new DirectSafetyBlockedError(code, `${message} [${code}]`, BLOCKS_MUTATE_TOOL, ctx.site.alias);
}

/** One operation that cannot be applied. The batch stops and nothing is written. */
class OperationError extends Error {
  constructor(
    readonly code: string,
    message: string,
  ) {
    super(message);
  }
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

function isPath(value: unknown): value is number[] {
  return Array.isArray(value) && value.every((step) => Number.isInteger(step) && (step as number) >= 0);
}

// --- reading the post ---------------------------------------------------------------------------

type PostRow = {
  id?: number;
  status?: string;
  link?: string;
  content?: { raw?: string } | string;
};

async function readPost(
  ctx: DirectToolContext,
  postId: number,
  type: string | undefined,
): Promise<{ collection: string; row: PostRow; raw: string }> {
  const candidates = type !== undefined && type.trim() !== "" ? [await collectionFor(ctx, type)] : ["pages", "posts"];
  let last: unknown;
  for (const collection of candidates) {
    try {
      const row = await ctx.client.get<PostRow>(`/wp/v2/${collection}/${postId}`, {
        query: { context: "edit", _fields: "id,status,link,content" },
      });
      const raw = typeof row.content === "string" ? row.content : row.content?.raw;
      if (typeof raw !== "string") {
        throw refuse(
          ctx,
          "content_not_readable",
          "The post's raw block content was not returned (the signed-in user may not be allowed to edit it). Nothing was changed.",
        );
      }
      return { collection, row, raw };
    } catch (error) {
      if (error instanceof WpRestError && error.status === 404 && candidates.length > 1) {
        last = error;
        continue;
      }
      throw error;
    }
  }
  throw last ?? refuse(ctx, "post_not_found", `No page or post has the id ${postId}.`);
}

// --- building blocks ----------------------------------------------------------------------------

function ownStrings(parts: ReadonlyArray<string | null>): string {
  return parts.filter((part): part is string => part !== null).join("");
}

function assertNoBlockComment(text: string, where: string): void {
  if (BLOCK_COMMENT.test(text)) {
    throw new OperationError(
      "block_comment_in_markup",
      `${where} contains a block comment (<!-- wp:... -->), which would open or close blocks inside it. Insert a block with the comment's name instead.`,
    );
  }
}

function buildNode(spec: unknown, where: string, registry: BlockRegistry, budget: { left: number }, depth = 0): BlockNode {
  if (!isPlainObject(spec)) throw new OperationError("invalid_block", `${where} must be an object.`);
  if (depth > MAX_DEPTH) throw new OperationError("invalid_block", `${where} is nested deeper than ${MAX_DEPTH} levels.`);
  budget.left -= 1;
  if (budget.left < 0) {
    throw new OperationError("invalid_block", `An operation may insert at most ${MAX_INSERTED_BLOCKS} blocks.`);
  }
  const rawName = spec["blockName"] ?? spec["name"];
  if (typeof rawName !== "string" || !BLOCK_NAME.test(rawName)) {
    throw new OperationError(
      "invalid_block",
      `${where} needs blockName, a lowercase block name such as core/paragraph or acme/hero.`,
    );
  }
  const name = normalizeBlockName(rawName);
  if (!isKnownBlock(registry, name)) {
    throw new OperationError("unknown_block", `The block ${name} is not registered on this site, so it cannot be inserted.`);
  }
  const attrs = spec["attrs"] ?? {};
  if (!isPlainObject(attrs)) throw new OperationError("invalid_block", `${where}.attrs must be an object.`);
  try {
    JSON.stringify(attrs);
  } catch {
    throw new OperationError("invalid_block", `${where}.attrs cannot be written as JSON.`);
  }
  if (spec["innerHTML"] !== undefined && typeof spec["innerHTML"] !== "string") {
    throw new OperationError("invalid_block", `${where}.innerHTML must be a string.`);
  }
  const rawChildren = spec["innerBlocks"] ?? [];
  if (!Array.isArray(rawChildren)) throw new OperationError("invalid_block", `${where}.innerBlocks must be an array.`);
  const innerBlocks = rawChildren.map((child, index) =>
    buildNode(child, `${where}.innerBlocks.${index}`, registry, budget, depth + 1),
  );
  const innerHTML = typeof spec["innerHTML"] === "string" ? spec["innerHTML"] : "";

  let innerContent: Array<string | null>;
  const given = spec["innerContent"];
  if (given !== undefined) {
    if (!Array.isArray(given) || !given.every((part) => part === null || typeof part === "string")) {
      throw new OperationError("unsafe_nested_structure", `${where}.innerContent must be an array of strings and nulls.`);
    }
    innerContent = given as Array<string | null>;
    if (innerContent.filter((part) => part === null).length !== innerBlocks.length) {
      throw new OperationError(
        "unsafe_nested_structure",
        `${where}.innerContent needs exactly one null for each of its ${innerBlocks.length} inner blocks.`,
      );
    }
    if (typeof spec["innerHTML"] === "string" && ownStrings(innerContent) !== innerHTML) {
      throw new OperationError("unsafe_nested_structure", `${where}.innerHTML is not the strings of innerContent joined.`);
    }
  } else if (innerBlocks.length === 0) {
    innerContent = innerHTML === "" ? [] : [innerHTML];
  } else if (innerHTML === "") {
    innerContent = innerBlocks.flatMap((_, index) => (index === 0 ? [null] : [SEPARATOR, null]));
  } else {
    throw new OperationError(
      "unsafe_nested_structure",
      `${where} has both innerHTML and inner blocks, so it needs innerContent to say where the inner blocks go.`,
    );
  }
  const own = ownStrings(innerContent);
  assertNoBlockComment(own, `${where} markup`);
  return {
    name,
    attrs: structuredClone(attrs),
    innerBlocks,
    innerContent,
    innerHTML: own,
    ...(innerContent.length === 0 && innerBlocks.length === 0 ? { selfClosing: true } : {}),
    dirty: true,
  };
}

// --- changing the tree --------------------------------------------------------------------------

const blank = (text: string): BlockNode => ({
  name: null,
  attrs: {},
  innerBlocks: [],
  innerContent: [text],
  innerHTML: text,
  raw: text,
  blank: true,
});

/** The position in the full top-level list (blank text included) of the k-th addressable block. */
function realIndex(root: BlockNode[], k: number): number {
  let seen = -1;
  for (let i = 0; i < root.length; i += 1) {
    if (root[i]?.blank !== true) seen += 1;
    if (seen === k) return i;
  }
  return -1;
}

function insertTopLevel(root: BlockNode[], position: number, node: BlockNode): void {
  const count = addressableBlocks(root).length;
  if (position >= count) {
    if (count === 0) {
      root.push(node);
      return;
    }
    root.splice(realIndex(root, count - 1) + 1, 0, blank(SEPARATOR), node);
    return;
  }
  root.splice(realIndex(root, position), 0, node, blank(SEPARATOR));
}

function removeTopLevel(root: BlockNode[], k: number): void {
  const at = realIndex(root, k);
  const last = k === addressableBlocks(root).length - 1;
  const next = root[at + 1];
  const previous = root[at - 1];
  if (!last && next?.blank === true) root.splice(at, 2);
  else if (last && previous?.blank === true) root.splice(at - 1, 2);
  else root.splice(at, 1);
}

function nullIndexes(parts: ReadonlyArray<string | null>): number[] {
  return parts.flatMap((part, index) => (part === null ? [index] : []));
}

function insertChild(parent: BlockNode, position: number, node: BlockNode): void {
  const slots = nullIndexes(parent.innerContent);
  if (slots.length === 0) {
    throw new OperationError(
      "unsafe_nested_structure",
      `${parent.name ?? "This block"} has no inner blocks yet, so Direct cannot tell where inside its markup a new one goes. Replace the block, or edit the post with stonewright-content-update.`,
    );
  }
  if (position < slots.length) {
    parent.innerContent.splice(slots[position] ?? 0, 0, null, SEPARATOR);
    parent.innerBlocks.splice(position, 0, node);
  } else {
    parent.innerContent.splice((slots.at(-1) as number) + 1, 0, SEPARATOR, null);
    parent.innerBlocks.push(node);
  }
  parent.dirty = true;
}

function removeChild(parent: BlockNode, position: number): void {
  const slots = nullIndexes(parent.innerContent);
  const at = slots[position];
  if (at === undefined) throw new OperationError("invalid_path", "The block to remove is not in its parent.");
  const parts = parent.innerContent;
  if (slots.length === 1) {
    const joined = `${(parts[at - 1] as string | null | undefined) ?? ""}${(parts[at + 1] as string | null | undefined) ?? ""}`;
    const from = at - (typeof parts[at - 1] === "string" ? 1 : 0);
    const to = at + (typeof parts[at + 1] === "string" ? 1 : 0);
    parts.splice(from, to - from + 1, ...(joined === "" ? [] : [joined]));
  } else if (position < slots.length - 1) {
    const separator = parts[at + 1];
    if (typeof separator === "string" && separator.trim() !== "") {
      throw new OperationError(
        "unsafe_nested_structure",
        "Markup sits between this block and the next one, so removing it would remove that markup too.",
      );
    }
    parts.splice(at, 2);
  } else {
    const separator = parts[at - 1];
    if (typeof separator === "string" && separator.trim() !== "") {
      throw new OperationError(
        "unsafe_nested_structure",
        "Markup sits right before this block, so removing it would remove that markup too.",
      );
    }
    parts.splice(at - 1, 2);
  }
  parent.innerBlocks.splice(position, 1);
  parent.dirty = true;
}

interface Applied {
  path: number[];
  findings: CustomCodeFinding[];
}

function asPath(value: unknown, field: string): number[] {
  if (!isPath(value)) throw new OperationError("invalid_path", `${field} must be a list of block positions such as [1, 0].`);
  return value;
}

function applyOperation(root: BlockNode[], operation: BlockOperation, registry: BlockRegistry, budget: { left: number }, index: number): Applied {
  const prefix = `operations.${index}`;
  if (operation.action === "insert") {
    if (operation.before_path !== undefined && operation.after_path !== undefined) {
      throw new OperationError("ambiguous_anchor", "Use either before_path or after_path, not both.");
    }
    let parentPath: number[];
    let position: number;
    const anchor = operation.before_path ?? operation.after_path;
    if (anchor !== undefined) {
      const anchorPath = asPath(anchor, operation.before_path !== undefined ? "before_path" : "after_path");
      if (anchorPath.length === 0 || blockAt(root, anchorPath) === null) {
        throw new OperationError("invalid_anchor", "The block to insert next to does not exist.");
      }
      parentPath = anchorPath.slice(0, -1);
      position = (anchorPath.at(-1) as number) + (operation.after_path !== undefined ? 1 : 0);
    } else {
      parentPath = operation.path === undefined ? [] : asPath(operation.path, "path");
      position = operation.position === undefined ? Number.MAX_SAFE_INTEGER : Math.max(0, Math.trunc(operation.position));
    }
    const parent = parentPath.length === 0 ? null : blockAt(root, parentPath);
    if (parentPath.length > 0 && parent === null) {
      throw new OperationError("invalid_path", "The block to insert into does not exist.");
    }
    const node = buildNode(operation.block, `${prefix}.block`, registry, budget);
    const findings = scanBlockTree([node]).map((finding) => ({ kind: finding.kind, path: `${prefix}.block` }));
    if (parent === null) {
      const at = Math.min(position, addressableBlocks(root).length);
      insertTopLevel(root, at, node);
      return { path: [at], findings };
    }
    const at = Math.min(position, parent.innerBlocks.length);
    insertChild(parent, at, node);
    return { path: [...parentPath, at], findings };
  }

  const path = asPath(operation.path ?? [], "path");
  const target = path.length === 0 ? null : blockAt(root, path);
  if (target === null) throw new OperationError("invalid_path", "The block at that path does not exist.");

  if (operation.action === "remove") {
    const position = path.at(-1) as number;
    if (path.length === 1) removeTopLevel(root, position);
    else removeChild(blockAt(root, path.slice(0, -1)) as BlockNode, position);
    return { path, findings: [] };
  }

  // update
  if (operation.attrs === undefined && operation.innerHTML === undefined) {
    throw new OperationError("empty_update", "An update needs attrs or innerHTML.");
  }
  if (operation.attrs !== undefined && !isPlainObject(operation.attrs)) {
    throw new OperationError("invalid_block", "attrs must be an object.");
  }
  if (operation.innerHTML !== undefined && typeof operation.innerHTML !== "string") {
    throw new OperationError("invalid_block", "innerHTML must be a string.");
  }
  if (target.name === null) {
    throw new OperationError("invalid_path", "That position holds loose HTML, not a block; replace it by removing it and inserting a block.");
  }
  if (operation.innerHTML !== undefined && target.innerBlocks.length > 0) {
    throw new OperationError(
      "unsafe_nested_structure",
      "innerHTML cannot be replaced on a block that holds inner blocks; change its inner blocks instead.",
    );
  }
  if (operation.attrs !== undefined && operation.innerHTML === undefined) {
    // The saved markup of a block follows from its attributes (a class, a tag), and Direct cannot render it.
    // Only a block with no markup of its own can take new attributes alone.
    if (target.innerBlocks.length > 0 || target.innerHTML.trim() !== "") {
      throw new OperationError(
        "markup_not_regenerated",
        "Changing the attributes of a block that has saved markup also changes that markup, which Direct cannot generate. Send innerHTML with the matching markup in the same operation, or use stonewright-content-update, or Plugin mode.",
      );
    }
  }
  const findings: CustomCodeFinding[] = [];
  if (operation.innerHTML !== undefined) {
    assertNoBlockComment(operation.innerHTML, `${prefix}.innerHTML`);
    findings.push(...scanMarkupFields({ [`${prefix}.innerHTML`]: operation.innerHTML }).map((finding) => ({ kind: finding.kind, path: finding.path })));
    target.innerHTML = operation.innerHTML;
    target.innerContent = operation.innerHTML === "" ? [] : [operation.innerHTML];
  }
  if (operation.attrs !== undefined) {
    findings.push(...scanTextFields({ [`${prefix}.attrs`]: operation.attrs }).map((finding) => ({ kind: finding.kind, path: finding.path })));
    const merged: Record<string, unknown> = { ...target.attrs };
    for (const [key, value] of Object.entries(operation.attrs)) {
      // null removes the attribute.
      if (value === null) delete merged[key];
      else merged[key] = value;
    }
    try {
      JSON.stringify(merged);
    } catch {
      throw new OperationError("invalid_block", "attrs cannot be written as JSON.");
    }
    target.attrs = merged;
  }
  if (target.innerBlocks.length === 0 && target.innerContent.length === 0) target.selfClosing = true;
  else delete target.selfClosing;
  target.dirty = true;
  return { path, findings };
}

// --- the tool -----------------------------------------------------------------------------------

function summarize(root: BlockNode[]) {
  return {
    root_block_count: addressableBlocks(root).length,
    block_count: countBlocks(root),
    block_names: writtenBlockNames(root).slice(0, 25),
  };
}

export async function blocksBatchMutate(ctx: DirectToolContext, input: BlocksMutateInput) {
  assertToolEnabled(ctx.site, BLOCKS_MUTATE_TOOL);
  const dryRun = input.dry_run === true;
  const env = ctx.env ?? process.env;
  if (!dryRun) {
    assertWriteAllowed({ site: ctx.site, mode: ctx.writeMode, destructive: false, tool: BLOCKS_MUTATE_TOOL });
  }
  if (!Array.isArray(input.operations) || input.operations.length === 0) {
    throw refuse(ctx, "missing_operations", "At least one block operation is required.");
  }

  const { collection, row, raw } = await readPost(ctx, input.post_id, input.type);
  const resource = `${collection}/${input.post_id}`;
  const beforeHash = sha256(raw);
  const expected = (input.expected_content_hash ?? "").trim().toLowerCase();
  if (!dryRun && expected === "") {
    throw refuse(
      ctx,
      "missing_expected_content_hash",
      `A write needs expected_content_hash, the before_hash of a dry run on the current content. The current content hash is ${beforeHash}.`,
    );
  }
  if (expected !== "" && expected !== beforeHash) {
    throw refuse(
      ctx,
      "content_conflict",
      `The post content changed after it was planned (expected ${expected}, current ${beforeHash}). Run a dry run again on the current content. Nothing was written.`,
    );
  }

  const parsed = parseBlockMarkup(raw);
  const broken = parsed.diagnostics.filter((diagnostic) => diagnostic.severity === "error");
  if (broken.length > 0) {
    throw refuse(
      ctx,
      "invalid_block_markup",
      `The post's block markup is already broken, so Direct will not edit it block by block: ${broken
        .slice(0, 3)
        .map((diagnostic) => `${diagnostic.message} (line ${diagnostic.line})`)
        .join(" ")} Fix it first (stonewright-gutenberg-validate lists the problems). Nothing was written.`,
    );
  }

  const root = parsed.blocks;
  const registry: BlockRegistry = input.operations.some((operation) => operation.action === "insert")
    ? await readBlockRegistry(ctx.client, ctx.site.url)
    : { status: "unavailable", reason: "not needed" };
  const budget = { left: MAX_INSERTED_BLOCKS };
  const items: Array<Record<string, unknown>> = [];
  const findings: CustomCodeFinding[] = [];
  for (const [index, operation] of input.operations.entries()) {
    try {
      const applied = applyOperation(root, operation, registry, budget, index);
      findings.push(...applied.findings);
      items.push({ index, ok: true, action: operation.action, path: applied.path });
    } catch (error) {
      if (!(error instanceof OperationError)) throw error;
      throw refuse(
        ctx,
        "batch_operation_failed",
        `Operation ${index} (${String(operation.action)}) was refused: ${error.message} (${error.code}). No operation was applied and nothing was written.`,
      );
    }
  }

  // Only what the operations add is judged: code that the page already holds stays as it is, and an edit
  // that removes it is allowed.
  const refusal = refuseCustomCode(ctx.site, BLOCKS_MUTATE_TOOL, resource, findings, env);
  if (refusal) return refusal;

  const after = serializeBlocks(root);
  const recheck = parseBlockMarkup(after).diagnostics.filter((diagnostic) => diagnostic.severity === "error");
  if (recheck.length > 0) {
    throw refuse(
      ctx,
      "serialization_check_failed",
      `The edited markup does not read back as valid blocks (${recheck[0]?.message ?? "unknown problem"}), so it was not written.`,
    );
  }
  const afterHash = sha256(after);
  const base = {
    post_id: input.post_id,
    type: collection,
    before_hash: beforeHash,
    after_hash: afterHash,
    applied: items.length,
    items,
    preview_summary: summarize(root),
  };

  if (dryRun) {
    return {
      ok: true as const,
      dry_run: true,
      written: false,
      unchanged: after === raw,
      ...base,
      next: "Write it with dry_run:false and expected_content_hash set to before_hash.",
    };
  }
  if (after === raw) {
    return { ok: true as const, dry_run: false, written: false, unchanged: true, ...base };
  }

  const preImage = await storePreImage({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: BLOCKS_MUTATE_TOOL,
    restPath: `/wp/v2/${collection}/${input.post_id}`,
    resourceId: `${collection}-${input.post_id}`,
  });
  // Compare-and-set, again, as late as possible: the check at the top may be a while ago.
  const current = await readPost(ctx, input.post_id, collection);
  if (sha256(current.raw) !== beforeHash) {
    throw refuse(
      ctx,
      "content_conflict",
      `The post content changed just before the write (expected ${beforeHash}, current ${sha256(current.raw)}). Run a dry run again on the current content. Nothing was written.`,
    );
  }

  const body = { content: after };
  let saved: PostRow;
  try {
    saved = await ctx.client.put<PostRow>(`/wp/v2/${collection}/${input.post_id}`, { body });
  } catch (error) {
    appendDirectAudit({
      tool: BLOCKS_MUTATE_TOOL,
      site: ctx.site.alias,
      targetIdentity: ctx.site.url,
      resource,
      status: "error",
    });
    throw error;
  }
  const savedRaw = typeof saved.content === "string" ? saved.content : saved.content?.raw;
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: BLOCKS_MUTATE_TOOL,
    resource,
    resourceType: collection,
    readPath: `/wp/v2/${collection}/${input.post_id}`,
    sent: body,
    beforeSha256: preImage.before_sha256,
    backupId: preImage.backup_id,
  });
  return probeAfterWrite(
    ctx,
    {
      tool: BLOCKS_MUTATE_TOOL,
      resource,
      input,
      target: { kind: "page", status: saved.status ?? row.status, link: saved.link ?? row.link },
      sentText: after,
      backupId: preImage.backup_id,
    },
    attachVerification(
      {
        ok: true as const,
        dry_run: false,
        written: true,
        ...base,
        readback_hash: savedRaw === undefined ? null : sha256(savedRaw),
        ...preImage,
      },
      verification,
      { backupId: preImage.backup_id },
    ),
  );
}
