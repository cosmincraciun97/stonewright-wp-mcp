// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from novamira-visual/src/tools/use-skill.ts
// Source SHA-256: 4ba576a344ceef78316c9a87910f7e21823c6ab37bea880ae545588156126079

import type { NestedEditorTool, NestedToolResult } from "../types.js";
import type { SkillEditor } from "./skill-registry.js";
import { SkillRegistry } from "./skill-registry.js";

function textResult(text: string, details: Record<string, unknown> = {}): NestedToolResult {
  return { content: [{ type: "text", text }], details };
}

export function makeUseSkillTool(
  registry: SkillRegistry,
  editor: Exclude<SkillEditor, "shared">,
): NestedEditorTool {
  return {
    name: "use_skill",
    label: "Use Skill",
    description: "List available skills or load one skill body/reference on demand.",
    batchable: true,
    parameters: {
      type: "object",
      additionalProperties: false,
      properties: {
        name: { type: "string", description: "Skill name. Omit to list skills." },
        file: { type: "string", description: 'Exact reference path, e.g. "references/api.md".' },
      },
    },
    execute: async (args) => {
      const name = typeof args.name === "string" ? args.name : "";
      const file = typeof args.file === "string" ? args.file : "";
      if (!name) {
        const list = registry.list(editor);
        if (list.length === 0) return textResult("No skills available.");
        return textResult(list.map((skill) => {
          const refs = Object.keys(skill.references);
          return `- **${skill.name}**: ${skill.description}${refs.length ? ` (references: ${refs.join(", ")})` : ""}`;
        }).join("\n"));
      }

      const skill = registry.get(name);
      if (!skill) return textResult(`Skill "${name}" not found. Available: ${registry.list(editor).map((item) => item.name).join(", ") || "none"}`);
      if (skill.editor !== "shared" && skill.editor !== editor) {
        return textResult(`Skill "${name}" is for ${skill.editor} only (current editor: ${editor}).`);
      }
      if (file) {
        if (!/^references\/[A-Za-z0-9._/-]+\.md$/.test(file) || file.includes("..")) {
          return textResult(`Invalid reference path "${file}".`);
        }
        const content = skill.references[file];
        if (!content) return textResult(`File "${file}" not found in skill "${name}". Available: ${Object.keys(skill.references).join(", ") || "none"}`);
        return textResult(content, { skill: name, file });
      }

      const refs = Object.keys(skill.references);
      const hint = refs.length
        ? `\n\n---\nReferences: ${refs.join(", ")}. Load one with use_skill(name: "${name}", file: "...").`
        : "";
      return textResult(skill.body + hint, { skill: name });
    },
  };
}
