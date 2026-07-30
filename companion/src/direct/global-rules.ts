import { createHash } from "node:crypto";
import { existsSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * Direct mode ships its own copy of the plugin's rule registry, synced by
 * `npm run sync:rules`. The digest algorithm mirrors `GlobalRules::digest_of()`
 * so a Direct client and a plugin client can compare digests directly.
 */

export const RULE_SEVERITIES = ["hard", "strong", "advisory"] as const;
export const RULE_SCOPES = ["all", "elementor", "design", "code"] as const;

export type RuleSeverity = (typeof RULE_SEVERITIES)[number];
export type RuleScope = (typeof RULE_SCOPES)[number];

export type GlobalRule = {
  id: string;
  severity: RuleSeverity;
  scope: RuleScope;
  rule: string;
  why: string;
  enforcement: { kind: "runtime" | "instruction"; guard: string };
};

export type RulesGetInput = {
  severity?: string;
  scope?: string;
  knownDigest?: string;
};

export type RulesGetResult = {
  ok: true;
  digest: string;
  unchanged: boolean;
  count: number;
  filters: { severity: RuleSeverity | null; scope: RuleScope | null };
  rules: GlobalRule[];
};

const RECORD_KEYS = [
  "id",
  "severity",
  "scope",
  "rule",
  "why",
  "enforcement",
] as const;
const ENFORCEMENT_KEYS = ["kind", "guard"] as const;

function objectRecord(value: unknown): Record<string, unknown> | null {
  return typeof value === "object" && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null;
}

function requiredString(
  record: Record<string, unknown>,
  key: string,
  label: string,
): string {
  const value = record[key];
  if (typeof value !== "string" || value.trim() === "") {
    throw new Error(`${label} must declare a non-empty "${key}" string.`);
  }
  return value.trim();
}

function exactKeys(
  record: Record<string, unknown>,
  expected: readonly string[],
  label: string,
): void {
  const actual = Object.keys(record);
  const missing = expected.find((key) => !Object.hasOwn(record, key));
  if (missing !== undefined) {
    throw new Error(`${label} is missing the required "${missing}" field.`);
  }
  const extra = actual.find((key) => !expected.includes(key));
  if (extra !== undefined) {
    throw new Error(`${label} declares unknown field "${extra}".`);
  }
}

/**
 * Validate and canonicalize the packaged registry before it becomes executable
 * instruction. This mirrors the plugin loader rather than trusting a type cast.
 */
export function parseGlobalRules(
  value: unknown,
  source = "global rule registry",
): GlobalRule[] {
  if (!Array.isArray(value)) {
    throw new Error(`The ${source} must be a list of rule records.`);
  }

  const seen = new Set<string>();
  return value.map((candidate, index): GlobalRule => {
    const record = objectRecord(candidate);
    const label = `Rule record ${index} in ${source}`;
    if (record === null) {
      throw new Error(`${label} must be an object.`);
    }
    exactKeys(record, RECORD_KEYS, label);

    const id = requiredString(record, "id", label);
    if (!/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/.test(id)) {
      throw new Error(`${label} has an id that is not a lowercase slug.`);
    }
    if (seen.has(id)) {
      throw new Error(`The ${source} declares duplicate rule id "${id}".`);
    }
    seen.add(id);

    const severity = requiredString(record, "severity", label);
    if (!RULE_SEVERITIES.includes(severity as RuleSeverity)) {
      throw new Error(`Rule "${id}" declares unknown severity "${severity}".`);
    }

    const scope = requiredString(record, "scope", label);
    if (!RULE_SCOPES.includes(scope as RuleScope)) {
      throw new Error(`Rule "${id}" declares unknown scope "${scope}".`);
    }

    const rule = requiredString(record, "rule", label);
    const why = requiredString(record, "why", label);
    const enforcement = objectRecord(record.enforcement);
    if (enforcement === null) {
      throw new Error(`Rule "${id}" must declare an enforcement object.`);
    }
    exactKeys(enforcement, ENFORCEMENT_KEYS, `Rule "${id}" enforcement`);

    const kind = requiredString(enforcement, "kind", `Rule "${id}" enforcement`);
    if (kind !== "runtime" && kind !== "instruction") {
      throw new Error(`Rule "${id}" declares unknown enforcement kind "${kind}".`);
    }
    const guardValue = enforcement.guard;
    if (typeof guardValue !== "string") {
      throw new Error(`Rule "${id}" must declare an enforcement guard string.`);
    }
    const guard = guardValue.trim();
    if (kind === "runtime" && !/^[a-z][a-z0-9_]*$/.test(guard)) {
      throw new Error(`Rule "${id}" is runtime-enforced but names no concrete guard.`);
    }
    if (kind === "instruction" && guard !== "") {
      throw new Error(`Rule "${id}" claims a guard without runtime enforcement.`);
    }
    if (severity === "hard" && kind !== "runtime") {
      throw new Error(`Rule "${id}" is hard but declares no runtime guard.`);
    }

    return {
      id,
      severity: severity as RuleSeverity,
      scope: scope as RuleScope,
      rule,
      why,
      enforcement: { kind, guard },
    };
  });
}

function registryPath(): string {
  const here = dirname(fileURLToPath(import.meta.url));
  // dist/direct → ../../data, or companion/data when running from source.
  const candidates = [
    join(here, "../../data/global-rules.json"),
    join(here, "../../../data/global-rules.json"),
    join(here, "../../../companion/data/global-rules.json"),
    join(process.cwd(), "data/global-rules.json"),
    join(process.cwd(), "companion/data/global-rules.json"),
  ];
  for (const candidate of candidates) {
    if (existsSync(candidate)) return candidate;
  }
  return candidates[0];
}

let cache: GlobalRule[] | null = null;

export function globalRules(): GlobalRule[] {
  if (cache === null) {
    const path = registryPath();
    if (!existsSync(path)) {
      throw new Error(
        `The global rule registry is missing or unreadable at ${path}.`,
      );
    }
    const parsed: unknown = JSON.parse(readFileSync(path, "utf8"));
    cache = parseGlobalRules(parsed, `global rule registry at ${path}`);
  }
  return cache;
}

export function resetGlobalRulesCache(): void {
  cache = null;
}

/** Mirror of `GlobalRules::digest_of()`. */
export function digestOf(rules: GlobalRule[]): string {
  return createHash("sha1").update(JSON.stringify(rules)).digest("hex");
}

export function globalRulesDigest(): string {
  return digestOf(globalRules());
}

export function rulesGet(input: RulesGetInput): RulesGetResult {
  const severity = (input.severity ?? "").trim();
  const scope = (input.scope ?? "").trim();

  if (severity !== "" && !RULE_SEVERITIES.includes(severity as RuleSeverity)) {
    throw new Error(
      `Unknown rule severity "${severity}". Allowed severities: ${RULE_SEVERITIES.join(", ")}.`,
    );
  }
  if (scope !== "" && !RULE_SCOPES.includes(scope as RuleScope)) {
    throw new Error(
      `Unknown rule scope "${scope}". Allowed scopes: ${RULE_SCOPES.join(", ")}.`,
    );
  }

  let rules = globalRules();
  if (severity !== "") {
    rules = rules.filter((rule) => rule.severity === severity);
  }
  // Global rules apply everywhere, so a scoped request keeps them.
  if (scope !== "" && scope !== "all") {
    rules = rules.filter(
      (rule) => rule.scope === scope || rule.scope === "all",
    );
  }

  const digest = digestOf(rules);
  const known = (input.knownDigest ?? "").trim();
  const unchanged = known !== "" && known === digest;

  return {
    ok: true,
    digest,
    unchanged,
    count: rules.length,
    filters: {
      severity: severity === "" ? null : (severity as RuleSeverity),
      scope: scope === "" ? null : (scope as RuleScope),
    },
    rules: unchanged ? [] : rules,
  };
}
