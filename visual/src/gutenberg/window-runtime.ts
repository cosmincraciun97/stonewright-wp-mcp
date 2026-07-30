// SPDX-License-Identifier: AGPL-3.0-or-later

import type { GutenbergBlock, GutenbergBlockSchema, GutenbergRuntime } from "./types.js";

interface WpBlock { clientId?: string; name?: string; attributes?: Record<string, unknown>; innerBlocks?: WpBlock[]; }
interface WpBlockType { name?: string; title?: string; category?: string; attributes?: Record<string, unknown>; supports?: Record<string, unknown>; }
interface BlockEditorSelect { getBlocks: (rootClientId?: string) => WpBlock[]; getBlock: (clientId: string) => WpBlock | null; getBlockRootClientId?: (clientId: string) => string | null; getBlockIndex?: (clientId: string, rootClientId?: string) => number; }
interface BlockEditorDispatch { insertBlock: (block: WpBlock, index?: number, rootClientId?: string) => void; updateBlockAttributes: (clientId: string, attributes: Record<string, unknown>) => void; moveBlockToPosition: (clientId: string, fromRootClientId: string, toRootClientId: string, index: number) => void; removeBlock: (clientId: string) => void; undo: () => void; redo: () => void; }
interface EditorSelect { getCurrentPostId?: () => string | number; isEditedPostDirty?: () => boolean; }
interface EditorDispatch { savePost: () => Promise<unknown>; }
interface WindowLike {
  wp?: {
    blocks?: { getBlockTypes: () => WpBlockType[]; getBlockType: (name: string) => WpBlockType | undefined; createBlock: (name: string, attributes?: Record<string, unknown>, innerBlocks?: WpBlock[]) => WpBlock; serialize: (blocks: WpBlock[]) => string; };
    data?: { select: (store: string) => unknown; dispatch: (store: string) => unknown; };
  };
}

export function createWindowGutenbergRuntime(target: WindowLike = window as unknown as WindowLike): GutenbergRuntime {
  const blocks = target.wp?.blocks;
  const data = target.wp?.data;
  if (!blocks || !data) throw new Error("Gutenberg editor runtime is unavailable.");
  const selectBlocks = () => data.select("core/block-editor") as BlockEditorSelect;
  const dispatchBlocks = () => data.dispatch("core/block-editor") as BlockEditorDispatch;
  const selectEditor = () => data.select("core/editor") as EditorSelect;
  const dispatchEditor = () => data.dispatch("core/editor") as EditorDispatch;
  const normalize = (block: WpBlock, parentClientId?: string, position?: number): GutenbergBlock => ({
    clientId: String(block.clientId || ""),
    name: String(block.name || ""),
    attributes: cloneRecord(block.attributes),
    innerBlocks: (block.innerBlocks || []).map((child, index) => normalize(child, String(block.clientId || ""), index)),
    ...(parentClientId ? { parentClientId } : {}),
    ...(position === undefined ? {} : { position }),
  });
  const toWp = (block: GutenbergBlock): WpBlock => blocks.createBlock(block.name, block.attributes, block.innerBlocks.map(toWp));

  return {
    get postId() { return String(selectEditor().getCurrentPostId?.() || ""); },
    async listBlockTypes() { return blocks.getBlockTypes().map(schema).sort((a, b) => a.name.localeCompare(b.name)); },
    async getBlockSchema(name) { const value = blocks.getBlockType(name); return value ? schema(value) : null; },
    async getBlocks(parentClientId) { return selectBlocks().getBlocks(parentClientId).map((block, index) => normalize(block, parentClientId, index)); },
    async getBlock(clientId) {
      const block = selectBlocks().getBlock(clientId);
      if (!block) return null;
      const parentClientId = selectBlocks().getBlockRootClientId?.(clientId) || undefined;
      const position = selectBlocks().getBlockIndex?.(clientId, parentClientId) ?? undefined;
      return normalize(block, parentClientId, position);
    },
    async insertBlock(input) {
      const block = blocks.createBlock(input.name, input.attributes, input.innerBlocks.map(toWp));
      dispatchBlocks().insertBlock(block, input.position, input.parentClientId);
      const id = String(block.clientId || "");
      const readback = id ? await this.getBlock(id) : null;
      if (!readback) throw new Error("Gutenberg insert produced no readable block.");
      return readback;
    },
    async updateBlock(clientId, attributes) { dispatchBlocks().updateBlockAttributes(clientId, attributes); },
    async moveBlock(clientId, parentClientId, position) {
      const currentParent = selectBlocks().getBlockRootClientId?.(clientId) || "";
      dispatchBlocks().moveBlockToPosition(clientId, currentParent, parentClientId || "", position ?? selectBlocks().getBlocks(parentClientId).length);
    },
    async deleteBlock(clientId) { dispatchBlocks().removeBlock(clientId); },
    async undo() { dispatchBlocks().undo(); },
    async redo() { dispatchBlocks().redo(); },
    async save() { await dispatchEditor().savePost(); },
    async isDirty() { return Boolean(selectEditor().isEditedPostDirty?.()); },
    async serialize() { return blocks.serialize(selectBlocks().getBlocks()); },
  };
}

function schema(value: WpBlockType): GutenbergBlockSchema {
  return { name: String(value.name || ""), title: String(value.title || value.name || ""), ...(value.category ? { category: String(value.category) } : {}), attributes: cloneRecord(value.attributes), supports: cloneRecord(value.supports), source: "wordpress-block-registry" };
}
function cloneRecord(value: unknown): Record<string, unknown> { return value && typeof value === "object" && !Array.isArray(value) ? structuredClone(value as Record<string, unknown>) : {}; }
