// SPDX-License-Identifier: AGPL-3.0-or-later
import { readFileSync } from "node:fs";
import { runInNewContext } from "node:vm";
import { expect, it } from "vitest";
function client(): any { const scope: any = { TextEncoder, TextDecoder, crypto: globalThis.crypto, setTimeout, console }; scope.globalThis = scope; runInNewContext(readFileSync(new URL("../../plugin/assets/admin/block-queue.js", import.meta.url), "utf8"), scope); return scope.StonewrightQueueClient; }
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
