import { describe, expect, it } from "vitest";
import { SkillRegistry, makeUseSkillTool } from "../src/index.js";

const registry = new SkillRegistry({
  skills: {
    "skills/native-first/SKILL.md": `---\nname: native-first\ndescription: >\n  Prefer native controls before custom code.\neditor: shared\n---\nInspect schema, then write.`,
    "skills/v4-only/SKILL.md": `---\nname: v4-only\ndescription: Atomic-only guidance.\neditor: elementor-v4\n---\nNever fall back to V3.`,
  },
  references: {
    "skills/native-first/references/checklist.md": "# Checklist\nReadback required.",
  },
});

describe("Visual skill progressive disclosure", () => {
  it("lists metadata without eagerly injecting skill bodies", () => {
    const prompt = registry.promptSection("gutenberg");
    expect(prompt).toContain("native-first");
    expect(prompt).not.toContain("Inspect schema, then write.");
    expect(prompt).not.toContain("v4-only");
  });

  it("loads bodies and exact references through a nested tool", async () => {
    const tool = makeUseSkillTool(registry, "gutenberg");
    const body = await tool.execute({ name: "native-first" });
    expect(body.content[0].text).toContain("Inspect schema, then write.");
    expect(body.content[0].text).toContain("references/checklist.md");
    const reference = await tool.execute({ name: "native-first", file: "references/checklist.md" });
    expect(reference.content[0].text).toContain("Readback required.");
  });

  it("blocks incompatible skills and path traversal", async () => {
    const tool = makeUseSkillTool(registry, "gutenberg");
    expect((await tool.execute({ name: "v4-only" })).content[0].text).toContain("elementor-v4 only");
    expect((await tool.execute({ name: "native-first", file: "../secret.md" })).content[0].text).toContain("Invalid reference path");
  });
});
