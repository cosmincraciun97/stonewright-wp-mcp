// SPDX-License-Identifier: AGPL-3.0-or-later
import { describe, expect, it } from "vitest";
import { checkSchema, validateArgs, summarizeSchema } from "../src/editor-tools/schema-guard.js";
import { DeclaredToolSet } from "../src/editor-tools/declared-tool-set.js";

const schema = { type: "object", additionalProperties: false, properties: { count: { type: "integer", minimum: 1, maximum: 3 } }, required: ["count"] };
describe("declared catalog", () => {
  it("validates exact schema requirements and bounds", () => {
    const checked = checkSchema(schema);
    expect(() => validateArgs(checked, { count: 2 })).not.toThrow();
    for (const value of [{}, { count: 0 }, { count: 2, surprise: true }, { count: "2" }]) expect(() => validateArgs(checked, value)).toThrow();
    expect(summarizeSchema(checked)).toMatchObject({ required: ["count"], properties: { count: { minimum: 1, maximum: 3 } } });
  });
  it("rejects unsupported constraints rather than silently authorizing", () => {
    expect(() => checkSchema({ type: "object", patternProperties: { ".*": {} } })).toThrow();
    expect(() => checkSchema({ $ref: "https://example.test/schema" })).toThrow();
  });
  it("rejects duplicate tools and invalid arguments before executing", async () => {
    let calls = 0;
    const tool = { name: "read", parameters: schema, execute: async () => { calls++; return { content: [{ type: "text" as const, text: "read" }] }; } };
    expect(() => new DeclaredToolSet([tool, tool])).toThrow();
    const set = new DeclaredToolSet([tool]);
    await expect(set.call("missing", {})).rejects.toThrow();
    await expect(set.call("read", { count: 0 })).rejects.toThrow();
    expect(calls).toBe(0);
    await set.call("read", { count: 1 });
    expect(calls).toBe(1);
  });
  it("requires mutation readback", () => {
    expect(() => new DeclaredToolSet([{ name: "write", parameters: schema, mutates: true, execute: async () => ({ content: [] }) }])).toThrow();
  });
});
