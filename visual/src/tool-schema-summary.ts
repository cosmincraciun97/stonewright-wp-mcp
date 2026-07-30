// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from novamira-visual/src/workspace-tool-schema-summary.ts
// Source SHA-256: e879f2f80db51a59af3c288334cabcb6c74feb2c08873b209704c3432b10c549

export function summarizeToolInput(parameters: unknown, depth = 0): unknown {
  const schema = isRecord(parameters) ? parameters : null;
  if (!schema) return {};
  if (!schema.properties && schema.type && schema.type !== "object") {
    return summarizeProperty(schema, depth);
  }

  const required = Array.isArray(schema.required)
    ? schema.required.filter((key): key is string => typeof key === "string")
    : [];
  const properties = isRecord(schema.properties) ? schema.properties : {};
  return {
    required,
    properties: Object.fromEntries(
      Object.entries(properties).map(([key, property]) => [key, summarizeProperty(isRecord(property) ? property : {}, depth)]),
    ),
  };
}

function summarizeProperty(property: Record<string, unknown>, depth: number): Record<string, unknown> {
  const summary: Record<string, unknown> = {
    type: property.type,
    description: typeof property.description === "string" ? property.description.slice(0, 180) : undefined,
  };
  if (depth < 2 && property.items) summary.items = summarizeToolInput(property.items, depth + 1);
  if (depth < 2 && property.properties) {
    const nested = summarizeToolInput(property, depth + 1) as Record<string, unknown>;
    summary.required = nested.required;
    summary.properties = nested.properties;
  }
  return Object.fromEntries(Object.entries(summary).filter(([, value]) => value !== undefined));
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value && typeof value === "object" && !Array.isArray(value));
}
