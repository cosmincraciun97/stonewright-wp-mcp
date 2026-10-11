import { readFileSync, readdirSync, existsSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import type { WpRestClient } from "../wp-rest-client.js";
import { assertWriteAllowed, resolveDirectWriteMode } from "../writes.js";
import { refuseCustomCode, scanMarkupFields } from "../custom-code-guard.js";
import { storePreImage } from "../preimage.js";
import { attachVerification, verifyWrite } from "../verify.js";
import { probeAfterWrite, type ProbeOptions } from "../probe.js";
import type { DirectToolContext } from "./types.js";
import {
  serializeDesignSpec,
  type DesignSpec,
} from "../gutenberg-serializer.js";

export type BlueprintSummary = {
  id: string;
  name: string;
  description: string;
  industry: string;
  section_ids: string[];
};

function blueprintsDir(): string {
  const here = dirname(fileURLToPath(import.meta.url));
  // dist/direct/tools → ../../blueprints or repo companion/blueprints
  const candidates = [
    join(here, "../../../blueprints"),
    join(here, "../../../../companion/blueprints"),
    join(process.cwd(), "blueprints"),
    join(process.cwd(), "companion/blueprints"),
  ];
  for (const c of candidates) {
    if (existsSync(c)) return c;
  }
  return candidates[0];
}

function loadAll(): Array<Record<string, unknown>> {
  const dir = blueprintsDir();
  if (!existsSync(dir)) return [];
  return readdirSync(dir)
    .filter((f) => f.endsWith(".json"))
    .map(
      (f) =>
        JSON.parse(readFileSync(join(dir, f), "utf8")) as Record<
          string,
          unknown
        >,
    );
}

export function listBlueprints(): BlueprintSummary[] {
  return loadAll().map((bp) => ({
    id: String(bp.id ?? ""),
    name: String(bp.name ?? ""),
    description: String(bp.description ?? ""),
    industry: String(bp.industry ?? ""),
    section_ids: Array.isArray(bp.section_ids)
      ? bp.section_ids.map(String)
      : [],
  }));
}

export function getBlueprint(id: string): Record<string, unknown> | null {
  const key = id.trim().toLowerCase();
  return (
    loadAll().find((bp) => String(bp.id ?? "").toLowerCase() === key) ?? null
  );
}

export async function applyBlueprint(
  client: WpRestClient,
  args: {
    id: string;
    title?: string;
    status?: "draft" | "publish";
    post_id?: number;
    confirm?: boolean;
    engine?: string;
    site?: string;
  } & ProbeOptions,
  env: NodeJS.ProcessEnv,
  /** The resolved site context; without it the page is not probed and nothing can be rolled back. */
  runtime?: DirectToolContext,
): Promise<Record<string, unknown>> {
  if (args.engine && args.engine !== "gutenberg" && args.engine !== "auto") {
    return {
      ok: false,
      error: "elementor_requires_plugin",
      message:
        "Elementor engine requires the Stonewright plugin. Direct mode applies blueprints as Gutenberg drafts only.",
    };
  }

  const bp = getBlueprint(args.id);
  if (!bp) {
    return {
      ok: false,
      error: "not_found",
      message: `Blueprint ${args.id} not found`,
    };
  }

  const spec = (bp.spec ?? {}) as DesignSpec;
  const content = serializeDesignSpec(spec);
  const title =
    args.title ||
    String(
      (spec.page as { title?: string } | undefined)?.title ??
        bp.name ??
        "Blueprint page",
    );

  const writeMode = resolveDirectWriteMode(env, client.restBase);
  const site = client.target;

  if (args.post_id && args.post_id > 0) {
    assertWriteAllowed({
      mode: writeMode,
      destructive: true,
      confirm: args.confirm,
      tool: "stonewright-blueprint-apply",
      site,
    });
    const refusal = refuseCustomCode(
      site,
      "stonewright-blueprint-apply",
      `pages/${args.post_id}`,
      scanMarkupFields({ title, content }),
      env,
    );
    if (refusal) return refusal;
    const preImage = await storePreImage({
      client,
      site,
      env,
      tool: "stonewright-blueprint-apply",
      restPath: `/wp/v2/pages/${args.post_id}`,
      resourceId: `pages-${args.post_id}`,
    });
    const pageBody = { title, content, status: args.status ?? "draft" };
    const updated = await client.post<{ status?: string; link?: string }>(
      `/wp/v2/pages/${args.post_id}`,
      { body: pageBody },
    );
    const verification = await verifyWrite({
      client,
      site,
      env,
      tool: "stonewright-blueprint-apply",
      resource: `pages/${args.post_id}`,
      resourceType: "pages",
      readPath: `/wp/v2/pages/${args.post_id}`,
      sent: pageBody,
      beforeSha256: preImage.before_sha256,
      backupId: preImage.backup_id,
    });
    const result = attachVerification(
      {
        ok: true,
        engine: "gutenberg",
        mode: "direct",
        post_id: args.post_id,
        page_id: args.post_id,
        blueprint_id: bp.id,
        updated,
        ...preImage,
        note: "Direct mode Gutenberg apply (Elementor requires plugin).",
      },
      verification,
      { backupId: preImage.backup_id },
    );
    return runtime
      ? probeAfterWrite(
          runtime,
          {
            tool: "stonewright-blueprint-apply",
            resource: `pages/${args.post_id}`,
            input: args,
            target: { kind: "page", status: updated?.status, link: updated?.link },
            sentText: content,
            backupId: preImage.backup_id,
          },
          result,
        )
      : result;
  }

  assertWriteAllowed({
    mode: writeMode,
    destructive: false,
    confirm: args.confirm,
    tool: "stonewright-blueprint-apply",
    site,
  });
  const createRefusal = refuseCustomCode(
    site,
    "stonewright-blueprint-apply",
    "pages",
    scanMarkupFields({ title, content }),
    env,
  );
  if (createRefusal) return createRefusal;
  const createBody = { title, content, status: args.status ?? "draft" };
  const created = await client.post<{ id?: number; status?: string; link?: string }>(
    "/wp/v2/pages",
    { body: createBody },
  );
  const createdId = created.id ?? 0;
  const verification = await verifyWrite({
    client,
    site,
    env,
    tool: "stonewright-blueprint-apply",
    resource: `pages/${createdId}`,
    resourceType: "pages",
    readPath: `/wp/v2/pages/${createdId}`,
    sent: createBody,
  });
  const result = attachVerification(
    {
      ok: true,
      engine: "gutenberg",
      mode: "direct",
      post_id: createdId,
      page_id: createdId,
      blueprint_id: bp.id,
      created: true,
      note: "Direct mode Gutenberg apply (Elementor requires plugin).",
    },
    verification,
  );
  return runtime
    ? probeAfterWrite(
        runtime,
        {
          tool: "stonewright-blueprint-apply",
          resource: `pages/${createdId}`,
          input: args,
          target: { kind: "page", status: created.status, link: created.link },
          sentText: content,
        },
        result,
      )
    : result;
}
