// SPDX-License-Identifier: AGPL-3.0-or-later
import { canonical, WorkspaceFailure } from "../session/protocol.js";
import type { NativeBlockPort, BlockNode, BlockSpec } from "./native-port.js";
type Store = Record<string, (...args: any[]) => any>;
export function bindNativeBlockSession(value: unknown): NativeBlockPort {
  const win = value as { closed?: boolean; wp?: { blocks?: Store; data?: { select(name: string): Store; dispatch(name: string): Store } } };
  const wp = win.wp;
  if (!wp?.blocks || !wp.data || typeof wp.blocks.getBlockTypes !== "function" || typeof wp.blocks.createBlock !== "function" || typeof wp.blocks.serialize !== "function" || typeof wp.data.select !== "function" || typeof wp.data.dispatch !== "function") throw new WorkspaceFailure("workspace_editor_unsupported", "Native block registry and editor stores are required.");
  const select = wp.data.select("core/block-editor"), editor = wp.data.select("core/editor"), actions = wp.data.dispatch("core/block-editor"), history = wp.data.dispatch("core/editor");
  for (const [store, names] of [[select, ["getBlocks", "getBlock", "getBlockRootClientId", "getBlockIndex"]], [editor, ["getCurrentPostId", "isEditedPostDirty"]], [actions, ["insertBlocks", "updateBlockAttributes", "moveBlocksToPosition", "removeBlocks"]], [history, ["undo", "redo", "savePost"]]] as Array<[Store, string[]]>) if (!store || names.some((name) => typeof store[name] !== "function")) throw new WorkspaceFailure("workspace_editor_unsupported", "Required native editor APIs are unavailable.");
  const postId = editor.getCurrentPostId(); if (!postId) throw new WorkspaceFailure("workspace_editor_unsupported", "There is no active native post editor.");
  const session = globalThis.crypto.randomUUID(); let lastOwnTree: string | null = null;
  const active = (): void => { if (win.closed || editor.getCurrentPostId() !== postId) throw new WorkspaceFailure("workspace_session_changed", "Native editor target closed or changed."); };
  const project = (node: any): BlockNode => ({ clientId: String(node.clientId), name: String(node.name), attributes: JSON.parse(JSON.stringify(node.attributes ?? {})), innerBlocks: (node.innerBlocks ?? []).map(project) });
  const tree = async (): Promise<BlockNode[]> => { active(); return select.getBlocks().map(project); };
  const native = (spec: BlockSpec): any => wp.blocks!.createBlock(spec.name, spec.attributes, spec.innerBlocks.map(native));
  const own = async (operation: () => unknown): Promise<void> => { active(); await operation(); active(); lastOwnTree = canonical(await tree(), 2097152); };
  return {
    identity: () => session,
    registeredBlocks: async () => { active(); return wp.blocks!.getBlockTypes().map((row: any) => ({ name: row.name, attributes: JSON.parse(JSON.stringify(row.attributes ?? {})) })); },
    tree, block: async (id) => { active(); const found = select.getBlock(id); return found ? project(found) : null; },
    insert: async (spec, parent, index) => { const block = native(spec); await own(() => actions.insertBlocks([block], index, parent)); return [String(block.clientId)]; },
    update: async (id, attrs) => own(() => actions.updateBlockAttributes(id, attrs)),
    move: async (ids, parent, index) => { active(); const source = select.getBlockRootClientId(ids[0]); if (ids.some((id) => select.getBlockRootClientId(id) !== source)) throw new WorkspaceFailure("workspace_move_invalid", "Move targets must share one native root."); await own(() => actions.moveBlocksToPosition(ids, source, parent, index)); },
    remove: async (ids) => own(() => actions.removeBlocks(ids)), undo: async () => own(() => history.undo()), redo: async () => own(() => history.redo()),
    save: async () => { active(); await history.savePost(); active(); }, dirty: async () => { active(); return editor.isEditedPostDirty(); },
    serialize: async (nodes) => { active(); return wp.blocks!.serialize(nodes); },
    checkpoint: async () => ({ session, before: canonical(await tree(), 2097152) }),
    restore: async (raw) => {
      active(); const checkpoint = raw as { session?: string; before?: string };
      if (checkpoint.session !== session || typeof checkpoint.before !== "string") throw new WorkspaceFailure("workspace_rollback_fenced", "Checkpoint belongs to another native session.");
      let current = canonical(await tree(), 2097152);
      if (lastOwnTree !== null && current !== lastOwnTree) throw new WorkspaceFailure("workspace_rollback_fenced", "Editor changed after this writer; native history cannot be reclaimed.");
      for (let attempts = 0; current !== checkpoint.before && attempts < 20; attempts++) { await history.undo(); active(); const next = canonical(await tree(), 2097152); if (next === current) break; current = next; }
      if (current !== checkpoint.before) throw new WorkspaceFailure("workspace_rollback_failed", "Native history did not restore the checkpoint."); lastOwnTree = current;
    },
  };
}
