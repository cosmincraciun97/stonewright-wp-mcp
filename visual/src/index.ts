// SPDX-License-Identifier: AGPL-3.0-or-later

import type { WorkspaceRequest } from "./types.js";
import { WorkspaceRouter } from "./session/router.js";

export * from "./types.js";
export * from "./session/protocol.js";
export * from "./session/router.js";
export * from "./session/action-ledger.js";
export * from "./session/host-port.js";
export * from "./session/backend-policy.js";
export * from "./session/guidance-data.js";
export * from "./editor-tools/declared-tool-set.js";
export * from "./editor-tools/schema-guard.js";
export * from "./elementor-v3/types.js";
export * from "./elementor-v3/hash.js";
export * from "./elementor-v3/evidence-ledger.js";
export * from "./elementor-v3/settings-validator.js";
export * from "./elementor-v3/window-runtime.js";
export * from "./elementor-v3/editor-adapter.js";
export * from "./elementor-v4/types.js";
export * from "./elementor-v4/schema-validator.js";
export * from "./elementor-v4/window-runtime.js";
export * from "./elementor-v4/editor-adapter.js";
export * from "./native-blocks/native-port.js";
export * from "./native-blocks/store-session.js";
export * from "./native-blocks/block-tools.js";
export * from "./workspace-ui/state.js";
export * from "./workspace-ui/adapter-status.js";
export * from "./workspace-ui/evidence-panel.js";
export * from "./workspace-ui/confirmation-panel.js";
export * from "./workspace-ui/workspace.js";

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

export function createWorkspaceRequestHandler(dispatcher: WorkspaceRouter): (request: WorkspaceRequest) => Promise<unknown> {
  return async (request) => {
    if (!request || typeof request.method !== "string" || request.method.trim() === "") throw new Error("stonewright-workspace-request requires method.");
    return dispatcher.dispatch(request.method, request.params ?? {});
  };
}
