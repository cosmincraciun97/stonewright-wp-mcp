// SPDX-License-Identifier: AGPL-3.0-or-later

import type { WorkspaceRequest } from "./types.js";
import { WorkspaceDispatcher } from "./workspace-dispatcher.js";

export * from "./types.js";
export * from "./page-tool-registry.js";
export * from "./tool-schema-summary.js";
export * from "./workspace-agent-guidance.js";
export * from "./workspace-backend-tools.js";
export * from "./workspace-confirmations.js";
export * from "./workspace-dispatcher.js";

export const STONEWRIGHT_WORKSPACE_TOOL = {
  name: "stonewright-workspace-request",
  description: "Single Stonewright Visual gateway. Discovers and calls nested WordPress editor tools without exposing them as top-level MCP tools.",
  inputSchema: {
    type: "object",
    additionalProperties: false,
    required: ["method"],
    properties: {
      method: {
        type: "string",
        enum: [
          "workspace_status", "workspace_list_pages", "workspace_open_page", "workspace_close_page", "workspace_focus_page", "workspace_reload_page",
          "workspace_discover_backend_tools", "workspace_call_backend_tool", "workspace_list_page_tools", "workspace_call_page_tool",
          "workspace_pending_confirmations", "workspace_decide_confirmation", "workspace_get_action_status", "workspace_wait_action",
        ],
      },
      params: { type: "object" },
    },
  },
} as const;

export function createWorkspaceRequestHandler(dispatcher: WorkspaceDispatcher): (request: WorkspaceRequest) => Promise<unknown> {
  return async (request) => {
    if (!request || typeof request.method !== "string" || request.method.trim() === "") throw new Error("stonewright-workspace-request requires method.");
    return dispatcher.dispatch(request.method, request.params ?? {});
  };
}
