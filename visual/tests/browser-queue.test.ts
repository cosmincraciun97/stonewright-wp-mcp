// SPDX-License-Identifier: GPL-2.0-or-later
import { readFileSync } from "node:fs";
import { runInNewContext } from "node:vm";
import { expect, it } from "vitest";
function client(extra: object = {}): any { const scope: any = { TextEncoder, TextDecoder, crypto: globalThis.crypto, setTimeout, console, ...extra }; scope.globalThis = scope; runInNewContext(readFileSync(new URL("../../plugin/assets/admin/block-queue.js", import.meta.url), "utf8"), scope); return scope.StonewrightQueueClient; }
it("keeps retryable 409 and ambiguous terminal receipts pending", () => {
  const api = client();
  expect(api.classifyReceipt({ status: "serialized", retryable: true }, 409)).toBe("pending");
  expect(api.classifyReceipt({ status: "serialized" }, 200)).toBe("pending");
  expect(api.classifyReceipt({ status: "serialized", retryable: false }, 200)).toBe("serialized");
});
it("retries identical result bytes with bounded backoff", async () => {
  const api = client(); let attempts = 0, now = 0; const delays: number[] = [], payloads: string[] = [];
  const response = await api.requestWithBackoff(async (payload: object) => { payloads.push(JSON.stringify(payload)); attempts++; return { status: 409, payload: { status: "serialized", retryable: attempts < 3 } }; }, { result_id: "synthetic", html: "bytes" }, { now: () => now, sleep: async (delay: number) => { delays.push(delay); now += delay; } });
  expect(response.state).toBe("serialized"); expect(delays).toEqual([250, 500]); expect(new Set(payloads).size).toBe(1);
  const paused = await api.requestWithBackoff(async () => ({ status: 409, payload: { retryable: true } }), {}, { now: () => now, sleep: async (delay: number) => { now += delay; } });
  expect(paused.paused).toBe(true); expect(paused.attempts).toBe(8);
});
it("serializes only live registered block attributes and rejects arbitrary spec fields", async () => {
  const api = client(); const blocks = { getBlockType: (name: string) => name === "example/card" ? { attributes: { title: { type: "string" } } } : undefined, createBlock: (name: string, attributes: object, innerBlocks: object[]) => ({ name, attributes, innerBlocks }), serialize: (nodes: object[]) => JSON.stringify(nodes) };
  expect(api.serializeSpec({ name: "example/card", attributes: { title: "Example" }, innerBlocks: [] }, blocks)).toContain("Example");
  expect(() => api.serializeSpec({ name: "example/card", attributes: { unknown: true }, innerBlocks: [] }, blocks)).toThrow();
  expect(() => api.serializeSpec({ name: "example/card", attributes: {}, innerBlocks: [], html: "<script>" }, blocks)).toThrow();
});
it("retains an immutable unresolved receipt for explicit resume", async () => {
  const api = client(); let accepted = false, now = 0; const sent: string[] = [];
  const journal = api.createReceiptJournal(async (input: object) => { sent.push(JSON.stringify(input)); return { status: accepted ? 200 : 409, payload: { status: "serialized", retryable: !accepted } }; }, { now: () => now, sleep: async (delay: number) => { now += delay; } });
  const input = { result_id: "example-result", html: "original bytes" };
  expect((await journal.submit("example-change", input)).paused).toBe(true);
  input.html = "changed"; accepted = true;
  const resumed = await journal.resume();
  expect(resumed[0]).toMatchObject({ id: "example-change", receipt: { state: "serialized", paused: false } });
  expect(new Set(sent).size).toBe(1); expect(journal.pending()).toBe(0);
});
function editor(attributes: object): any { return { getBlockType: (name: string) => name === "example/block" ? { attributes } : undefined, createBlock: (name: string, values: object, innerBlocks: object[]) => ({ name, attributes: values, innerBlocks }), serialize: (nodes: object[]) => JSON.stringify(nodes) }; }
const spec = (attributes: object) => ({ name: "example/block", attributes, innerBlocks: [] });
it("accepts a string or the editor's rich text value for a rich-text attribute", () => {
  class RichTextData { toHTMLString() { return "Example"; } }
  const api = client({ wp: { richText: { RichTextData } } }), blocks = editor({ content: { type: "rich-text", source: "rich-text", selector: "p" } });
  expect(api.serializeSpec(spec({ content: "Example" }), blocks)).toContain("example/block");
  expect(api.serializeSpec(spec({ content: "" }), blocks)).toContain("example/block");
  expect(api.serializeSpec(spec({ content: new RichTextData() }), blocks)).toContain("example/block");
  for (const value of [7, true, null, ["Example"], { html: "Example" }]) expect(() => api.serializeSpec(spec({ content: value }), blocks)).toThrow("Invalid native attribute type.");
  expect(() => client().serializeSpec(spec({ content: { toHTMLString: () => "Example" } }), blocks)).toThrow("Invalid native attribute type.");
});
it("accepts a value that fits any type of a declared type list", () => {
  const api = client(), blocks = editor({ templateLock: { type: ["string", "boolean"], enum: ["all", "insert", "contentOnly", false] } });
  for (const value of ["all", "contentOnly", false]) expect(api.serializeSpec(spec({ templateLock: value }), blocks)).toContain("example/block");
  for (const value of [3, null, ["all"], { all: true }]) expect(() => api.serializeSpec(spec({ templateLock: value }), blocks)).toThrow("Invalid native attribute type.");
  expect(() => api.serializeSpec(spec({ templateLock: "nope" }), blocks)).toThrow("Invalid native attribute option.");
  expect(api.serializeSpec(spec({ either: 4 }), editor({ either: { type: ["rich-text", "number"] } }))).toContain("example/block");
});
it("does not check the value of an attribute declared without a type", () => {
  const api = client(), blocks = editor({ anything: {}, restricted: { enum: ["a", 1] } });
  for (const value of ["text", 5, false, null, [1], { key: "value" }]) expect(api.serializeSpec(spec({ anything: value }), blocks)).toContain("example/block");
  expect(api.serializeSpec(spec({ restricted: 1 }), blocks)).toContain("example/block");
  expect(() => api.serializeSpec(spec({ restricted: "b" }), blocks)).toThrow("Invalid native attribute option.");
});
it("keeps refusing a wrong value for every other declared type", () => {
  const api = client(), blocks = editor({ text: { type: "string" }, flag: { type: "boolean" }, amount: { type: "number" }, whole: { type: "integer" }, list: { type: "array" }, map: { type: "object" }, nothing: { type: "null" } });
  expect(api.serializeSpec(spec({ text: "a", flag: true, amount: 1.5, whole: 2, list: [1], map: { a: 1 }, nothing: null }), blocks)).toContain("example/block");
  const wrong: Record<string, unknown[]> = { text: [1, null, ["a"], { a: 1 }], flag: ["true", 1, null], amount: ["1", true, null], whole: [1.5, "2", null], list: [{}, "a", null], map: [[], "a", null, 1], nothing: [0, "", false, {}] };
  for (const [name, values] of Object.entries(wrong)) for (const value of values) expect(() => api.serializeSpec(spec({ [name]: value }), blocks), name + " " + JSON.stringify(value)).toThrow("Invalid native attribute type.");
});
