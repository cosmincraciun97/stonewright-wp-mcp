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
    const parsed = JSON.parse(readFileSync(path, "utf8")) as unknown;
    if (!Array.isArray(parsed)) {
      throw new Error(`The global rule registry at ${path} is not a list.`);
    }
    cache = parsed as GlobalRule[];
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
