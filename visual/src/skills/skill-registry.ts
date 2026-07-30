// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from novamira-visual/src/skills/loader.ts
// Source SHA-256: 89f94dfaaf7995f2ff1fb0a5e50dcda7f418327a223a80d67877fc9f9cdbf96a

export type SkillEditor = "shared" | "gutenberg" | "elementor-v3" | "elementor-v4";

export interface VisualSkill {
  name: string;
  description: string;
  editor: SkillEditor;
  body: string;
  references: Record<string, string>;
}

export interface RawSkillBundle {
  skills: Record<string, string>;
  references?: Record<string, string>;
}

interface SkillMeta {
  name: string;
  description: string;
  editor: SkillEditor;
}

function parseFrontmatter(raw: string): { meta: SkillMeta; body: string } {
  const match = raw.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n([\s\S]*)$/);
  if (!match) throw new Error("SKILL.md missing YAML frontmatter");

  const values: Record<string, string> = {};
  let multilineKey = "";
  let multilineValue = "";

  for (const line of match[1].split(/\r?\n/)) {
    if (multilineKey && /^\s{2}/.test(line)) {
      multilineValue += ` ${line.trim()}`;
      continue;
    }
    if (multilineKey) {
      values[multilineKey] = multilineValue.trim();
      multilineKey = "";
      multilineValue = "";
    }
    const pair = line.match(/^(\w+):\s*(.*)$/);
    if (!pair) continue;
    const value = pair[2].trim().replace(/^(["'])(.*)\1$/, "$2");
    if (value === ">" || value === "|") multilineKey = pair[1];
    else values[pair[1]] = value;
  }
  if (multilineKey) values[multilineKey] = multilineValue.trim();

  const editor = values.editor || "shared";
  if (!["shared", "gutenberg", "elementor-v3", "elementor-v4"].includes(editor)) {
    throw new Error(`Unsupported skill editor: ${editor}`);
  }
  if (!values.name || !values.description) {
    throw new Error("SKILL.md requires name and description frontmatter");
  }

  return {
    meta: { name: values.name, description: values.description, editor: editor as SkillEditor },
    body: match[2],
  };
}

export class SkillRegistry {
  private readonly skills: VisualSkill[];

  constructor(bundle: RawSkillBundle) {
    const references = bundle.references ?? {};
    const names = new Set<string>();
    this.skills = Object.entries(bundle.skills).map(([path, raw]) => {
      const directory = path.match(/(?:^|\/)([^/]+)\/SKILL\.md$/)?.[1];
      if (!directory) throw new Error(`Invalid skill path: ${path}`);
      const { meta, body } = parseFrontmatter(raw);
      if (names.has(meta.name)) throw new Error(`Duplicate skill name: ${meta.name}`);
      names.add(meta.name);

      const prefix = path.slice(0, path.lastIndexOf("SKILL.md")) + "references/";
      const skillReferences = Object.fromEntries(
        Object.entries(references)
          .filter(([referencePath]) => referencePath.startsWith(prefix))
          .map(([referencePath, content]) => [`references/${referencePath.slice(prefix.length)}`, content]),
      );
      return { ...meta, body, references: skillReferences };
    });
  }

  list(editor: Exclude<SkillEditor, "shared">): VisualSkill[] {
    return this.skills.filter((skill) => skill.editor === "shared" || skill.editor === editor);
  }

  get(name: string): VisualSkill | undefined {
    return this.skills.find((skill) => skill.name === name);
  }

  promptSection(editor: Exclude<SkillEditor, "shared">): string {
    const skills = this.list(editor);
    if (skills.length === 0) return "";
    return `\n## Available Skills\n\nUse the nested **use_skill** tool to load full instructions only when relevant.\n\n${skills
      .map((skill) => `- **${skill.name}**: ${skill.description}`)
      .join("\n")}\n`;
  }
}
