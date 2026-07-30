// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from novamira-visual/src/workspace-agent-guidance.ts
// Source SHA-256: 0ec7be4b5ded0cc29643be0650d76c944e18193468fc6a2a1fcd2332bed33c26

export function workspaceAgentGuidance(toolNames: string[]): string[] {
  const guidance = [
    "Use backend tools for WordPress data and settings; use nested editor tools for builder content.",
    "Inspect structure and exact schemas before writes; never invent settings.",
    "Prefer native builder widgets or blocks before CSS, JavaScript, or PHP.",
    "Use batch_call for related discovery and mutations; aliases like $hero reference earlier create results.",
    "Every mutation requires readback. Failed batches roll back before another plan is attempted.",
    "Buttons and CTAs require a URL or explicit action before write.",
  ];
  if (toolNames.includes("list_widgets")) {
    guidance.push("Elementor: use list_widgets and get_widget_schema; HTML widgets and custom code require explicit approval.");
  }
  if (toolNames.includes("list_block_types")) {
    guidance.push("Gutenberg: use registered block schemas, attributes, supports, and preset slugs; avoid core/html.");
  }
  return guidance;
}

export function suggestedFirstNestedCall(toolNames: string[]): Record<string, unknown> | null {
  if (!toolNames.includes("batch_call") || !toolNames.includes("get_page_structure")) return null;
  const listTool = toolNames.includes("list_widgets") ? "list_widgets" : toolNames.includes("list_block_types") ? "list_block_types" : null;
  if (!listTool) return null;
  return {
    toolName: "batch_call",
    args: {
      stop_on_error: false,
      max_text_chars: 600,
      calls: [
        { tool: "get_page_structure", args: { include_text: true } },
        { tool: listTool, args: {} },
      ],
    },
  };
}
