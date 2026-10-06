// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, it } from "vitest";
import { NativeBlockTools } from "../src/native-blocks/block-tools.js";
import { bindNativeBlockSession } from "../src/native-blocks/store-session.js";
import type { NativeBlockPort, BlockNode } from "../src/native-blocks/native-port.js";
import { authorizedWrite } from "./authorized-write.js";
function port(): NativeBlockPort {
  let tree: BlockNode[] = []; let dirty = false;
  return { identity: () => "synthetic-session", registeredBlocks: async () => [{ name: "example/card", attributes: { title: { type: "string" } } }], tree: async () => structuredClone(tree), block: async (id) => structuredClone(tree.find((row) => row.clientId === id) ?? null), insert: async (spec) => { const id = "synthetic-block"; tree.push({ clientId: id, name: spec.name, attributes: spec.attributes, innerBlocks: [] }); dirty = true; return [id]; }, update: async (id, attributes) => { tree.find((row) => row.clientId === id)!.attributes = { ...tree.find((row) => row.clientId === id)!.attributes, ...attributes }; dirty = true; }, move: async () => {}, remove: async (ids) => { tree = tree.filter((row) => !ids.includes(row.clientId)); dirty = true; }, undo: async () => {}, redo: async () => {}, save: async () => { dirty = false; }, dirty: async () => dirty, serialize: async () => "native-serialized", checkpoint: async () => structuredClone(tree), restore: async (checkpoint) => { tree = structuredClone(checkpoint as BlockNode[]); } };
}
it("uses live third-party schema and proves insert update delete and save state", async () => {
  const native = port(); const tools = new NativeBlockTools(native).registry();
  await expect(authorizedWrite(tools, "insert_block", { name: "example/card", attributes: { unknown: "bad" }, confirm_write: true, idempotency_key: "bad" })).rejects.toThrow();
  const insert = { name: "example/card", attributes: { title: "First" }, confirm_write: true, idempotency_key: "insert" };
  await authorizedWrite(tools, "insert_block", insert); await authorizedWrite(tools, "insert_block", insert);
  expect(await native.tree()).toHaveLength(1);
  await expect(authorizedWrite(tools, "insert_block", { ...insert, attributes: { title: "Different" } })).rejects.toThrow();
  await authorizedWrite(tools, "update_block", { client_id: "synthetic-block", attributes: { title: "Changed" }, confirm_write: true, idempotency_key: "update" });
  expect((await native.block("synthetic-block"))?.attributes.title).toBe("Changed");
  await authorizedWrite(tools, "save", { confirm_write: true, idempotency_key: "save" }); expect(await native.dirty()).toBe(false);
  await authorizedWrite(tools, "delete_block", { client_id: "synthetic-block", confirm_write: true, confirm_delete: true, idempotency_key: "delete" }); expect(await native.tree()).toEqual([]);
});
it("never binds an admin page without real native stores", () => {
  expect(() => bindNativeBlockSession({ wp: { blocks: {}, data: {} } })).toThrow();
});
