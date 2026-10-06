// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Structural audit of one declared nested-tool schema.
 *
 * A strict declaration is a closed object at the top level, states what every
 * declared property accepts (a type, a constant, an enumeration, or a
 * composition), and only requires properties it declares. An object node that
 * declares no properties is an open map; open maps are reported by path rather
 * than flagged, so a contract test can name exactly which ones an adapter may
 * accept because it checks their contents against the live editor schema.
 */
export interface SchemaAudit {
  problems: string[];
  openMaps: string[];
}

type Row = Record<string, unknown>;

const COMPOSITIONS = ["anyOf", "oneOf", "allOf"] as const;

function isRow(value: unknown): value is Row {
  return !!value && typeof value === "object" && !Array.isArray(value);
}

export function auditDeclaredSchema(parameters: unknown): SchemaAudit {
  const problems: string[] = [];
  const openMaps: string[] = [];

  if (!isRow(parameters)) {
    return { problems: ["no parameter schema is declared"], openMaps };
  }
  if (parameters.type !== "object") problems.push("the top level is not an object schema");
  if (parameters.additionalProperties !== false) problems.push("the top level accepts undeclared arguments");
  if (!isRow(parameters.properties)) problems.push("the top level declares no properties object");

  const checkRequired = (node: Row, path: string): void => {
    const declared = isRow(node.properties) ? node.properties : {};
    for (const key of Array.isArray(node.required) ? node.required : []) {
      if (typeof key !== "string" || !Object.hasOwn(declared, key)) problems.push(`${path}requires undeclared ${String(key)}`);
    }
  };

  const visit = (node: unknown, path: string): void => {
    if (!isRow(node)) {
      problems.push(`${path} is not a schema object`);
      return;
    }
    const composed = COMPOSITIONS.filter((key) => Array.isArray(node[key]));
    if (node.type === undefined && node.const === undefined && !Array.isArray(node.enum) && composed.length === 0) {
      problems.push(`${path} does not state what it accepts`);
    }
    const types = Array.isArray(node.type) ? node.type : [node.type];
    if (types.includes("object")) {
      if (isRow(node.properties)) {
        if (node.additionalProperties !== false) problems.push(`${path} accepts undeclared fields`);
        checkRequired(node, `${path} `);
        for (const [key, child] of Object.entries(node.properties)) visit(child, `${path}.${key}`);
      } else if (node.additionalProperties !== false) {
        openMaps.push(path);
        if (isRow(node.additionalProperties)) visit(node.additionalProperties, `${path}{}`);
      }
    }
    if (types.includes("array")) {
      if (node.items === undefined) problems.push(`${path} does not state what its items accept`);
      else visit(node.items, `${path}[]`);
    }
    for (const key of composed) (node[key] as unknown[]).forEach((child, index) => visit(child, `${path}<${key}:${index}>`));
  };

  checkRequired(parameters, "");
  for (const [key, child] of Object.entries(isRow(parameters.properties) ? parameters.properties : {})) visit(child, key);
  return { problems, openMaps };
}
