// SPDX-License-Identifier: AGPL-3.0-or-later
import type { WorkspacePageDescriptor, NestedToolResult } from "../types.js";
import type { DeclaredToolSet } from "../editor-tools/declared-tool-set.js";
export interface QualityFinding { rule: string; evidenceType: string; checked: boolean; status: "pass" | "fail" | "not_checked"; }
export interface SessionBinding { id: string; generation: number; tools: DeclaredToolSet; treeHash(): Promise<string>; schemaHash(): Promise<string>; directionHash(): Promise<string>; closed(): boolean; requiredEvidence?: string[]; verify?(): Promise<QualityFinding[]>; }
export interface WorkspaceHostPort {
  listPages(): Promise<WorkspacePageDescriptor[]>;
  openPage(input: { url: string; editorKind?: string }): Promise<WorkspacePageDescriptor>;
  closePage(id: string): Promise<unknown>;
  focusPage(id: string): Promise<unknown>;
  reloadPage(id: string): Promise<unknown>;
  resolveEditor(id: string): Promise<SessionBinding>;
}
export type PageToolOutcome = NestedToolResult;
