// SPDX-License-Identifier: AGPL-3.0-or-later
import type { NestedEditorTool } from "../types.js";
import { WorkspaceFailure } from "./protocol.js";
export interface SkillReference { id: string; name: string; description: string; source: string; revision: number; active: boolean; exposed: boolean; }
export interface SkillBody extends SkillReference { body: string; truncated: boolean; trustFindings: object[]; lintFindings: object[]; }
export interface WorkspaceSkillSource { list(): Promise<SkillReference[]>; load(id: string): Promise<SkillBody>; }
export class WorkspaceGuidance {
  constructor(private readonly options: { source: WorkspaceSkillSource }) {}
  async references(): Promise<SkillReference[]> { const seen = new Set<string>(); return (await this.options.source.list()).filter((row) => { if (!row.id || seen.has(row.id)) throw new WorkspaceFailure("workspace_skill_invalid", "Skill references must have unique source-qualified identities."); seen.add(row.id); return row.active && row.exposed; }).map(({ id, name, description, source, revision, active, exposed }) => ({ id, name, description, source, revision, active, exposed })); }
  async body(id: string): Promise<SkillBody> {
    if (!(await this.references()).some((row) => row.id === id)) throw new WorkspaceFailure("workspace_skill_unavailable", "Skill is unavailable in this host context.");
    const body = await this.options.source.load(id); if (body.id !== id || typeof body.body !== "string") throw new WorkspaceFailure("workspace_skill_invalid", "Skill source returned an invalid body.");
    const bytes = new TextEncoder().encode(body.body); const truncated = bytes.length > 131072; return { ...body, body: new TextDecoder().decode(bytes.slice(0, 131072)), truncated: body.truncated || truncated };
  }
  useSkillTool(): NestedEditorTool { return { name: "use_skill", mutates: false, parameters: { type: "object", additionalProperties: false, properties: { skill_id: { type: "string", minLength: 1, maxLength: 256 } }, required: ["skill_id"] }, execute: async (args) => { const skill = await this.body(String(args.skill_id)); return { content: [{ type: "text", text: skill.body }], details: { skill_id: skill.id, source: skill.source, revision: skill.revision, truncated: skill.truncated, trust_findings: skill.trustFindings, lint_findings: skill.lintFindings } }; } }; }
}
