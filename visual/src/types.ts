// SPDX-License-Identifier: AGPL-3.0-or-later

export interface ToolTextContent {
  type: "text";
  text: string;
}

export interface NestedToolResult {
  content: ToolTextContent[];
  details?: Record<string, unknown>;
}

export interface NestedEditorTool {
  name: string;
  label?: string;
  description?: string;
  parameters?: unknown;
  mutates?: boolean;
  execute: (args: Record<string, unknown>) => Promise<NestedToolResult>;
  readback?: (args: Record<string, unknown>, result: NestedToolResult) => Promise<unknown>;
  rollback?: (args: Record<string, unknown>, result: NestedToolResult) => Promise<void>;
}

export interface BatchTransaction {
  commit: () => Promise<void>;
  rollback: () => Promise<void>;
}

export interface BatchTransactionFactory {
  begin: () => Promise<BatchTransaction>;
}

export interface BackendToolDefinition {
  name: string;
  label?: string;
  description?: string;
  category?: string;
  inputSchema?: unknown;
  safe: boolean;
  mutates: boolean;
  dangerous: boolean;
  requiresConfirmation: boolean;
}

export interface BackendTransport {
  discover: (params: Record<string, unknown>) => Promise<unknown>;
  call: (name: string, args: Record<string, unknown>) => Promise<unknown>;
}

export type ConfirmationAction = `backend_tool:${string}` | `page_tool:${string}` | `page_interaction:${string}`;
export type ConfirmationDecision = "allow_once" | "allow_session" | "allow_all_tools_session" | "deny";
export type ActionStatus = "waiting_for_confirmation" | "running" | "succeeded" | "failed" | "denied";

export interface ConfirmationRequest {
  title: string;
  description: string;
  reason: string;
  details: Array<{ label: string; value: string; format?: "code" }>;
}

export interface WorkspaceActionView {
  actionId: string;
  action: ConfirmationAction;
  title: string;
  status: ActionStatus;
  terminal: boolean;
  requiresUserConfirmation: boolean;
  result: unknown;
  error: string | null;
}

export interface WorkspaceRequest {
  method: string;
  params?: unknown;
}

export interface WorkspacePageDescriptor {
  id: string;
  title?: string;
  url?: string;
  editor?: string;
  toolNames: string[];
}
