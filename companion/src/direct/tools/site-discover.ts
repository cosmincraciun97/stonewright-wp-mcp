import { assertToolEnabled } from "../writes.js";
import { assertSecureTransport } from "../wp-rest-client.js";
import type { DirectToolContext } from "./types.js";

export const PLUGIN_ONLY_CAPABILITIES = [
  {
    id: "php-execute",
    label: "PHP runtime execution",
    reason: "Requires the Stonewright plugin ability stonewright/php-execute.",
    upgrade:
      "Install and activate the Stonewright plugin, then use stonewright-php-execute.",
  },
  {
    id: "elementor-engine",
    label: "Elementor engines / schema / DesignSpec render",
    reason:
      "Typed Elementor V3 engines, schema tools and DesignSpec render require the plugin. Direct reads and writes raw _elementor_data through stonewright-elementor-data-*: over WP-CLI when the alias is the local install, otherwise through core REST when the site exposes the meta. Raw writes are not validated against Elementor schemas.",
    upgrade:
      "Install the Stonewright plugin for typed Elementor engines; use stonewright-elementor-status first to see which transport Direct will use.",
  },
  {
    id: "elementor-v4-atomic",
    label: "Elementor V4 (Atomic) features",
    reason:
      "V4 status, atomic element and local style tools require the plugin. Direct sees V4 documents only as raw _elementor_data.",
    upgrade:
      "Install the Stonewright plugin to use the elementor-v4-* abilities.",
  },
  {
    id: "design-spec-render",
    label: "DesignSpec validation and render pipelines",
    reason: "Validator and renderers live in the plugin runtime.",
    upgrade:
      "Install the Stonewright plugin to use design-native-plan, validate-spec, and render abilities.",
  },
  {
    id: "confirmation-tokens",
    label: "Production-safe confirmation tokens",
    reason: "ConfirmationToken issue/verify is plugin-only.",
    upgrade:
      "Install the Stonewright plugin for production-safe destructive gates.",
  },
  {
    id: "site-memory-skills",
    label: "Server-side site memory, skills, and learning (wp-admin)",
    reason:
      "Shared, site-hosted memory/skills and Admin UI require the Stonewright plugin.",
    upgrade:
      "Install the plugin for shared site memory/skills. Direct mode already provides per-machine local skills/memory via stonewright-skill-* and stonewright-learning-record.",
  },
  {
    id: "content-model-registration",
    label: "Registering NEW CPTs / taxonomies / ACF field groups",
    reason:
      "WordPress core REST has no registration endpoint; registration needs PHP running on the server every request. No REST-only client can do this. Direct mode DOES fully edit existing models: CPT content via stonewright-content-* (type param), taxonomy terms via stonewright-taxonomy-terms, ACF field values via stonewright-acf-fields-*.",
    upgrade:
      "Install the Stonewright plugin for cpt-register, taxonomy-register, and acf-field-group-save. Do not scaffold ad hoc plugins as a workaround.",
  },
  {
    id: "rescue",
    label: "Rescue snapshots and restore",
    reason:
      "Rescue snapshots, probes and rollback are plugin-only. Direct keeps pre-image and backup files but has no tool that restores them.",
    upgrade:
      "Install the Stonewright plugin for Rescue snapshots and rollback.",
  },
  {
    id: "change-sets",
    label: "Change sets",
    reason: "Change sets and their diffs are recorded by the plugin only.",
    upgrade: "Install the Stonewright plugin to group and review changes.",
  },
  {
    id: "section-reuse",
    label: "Section reuse",
    reason:
      "Finding and extracting existing sections for reuse is a plugin ability.",
    upgrade:
      "Install the Stonewright plugin to use section-reuse-find and section-reuse-extract.",
  },
  {
    id: "design-direction",
    label: "Design Direction",
    reason: "Design Direction briefs and records live in the plugin.",
    upgrade:
      "Install the Stonewright plugin to use the design-direction abilities.",
  },
  {
    id: "site-context",
    label: "Site Context",
    reason:
      "Site Context is stored and served by the plugin; Direct keeps only per-machine local memory.",
    upgrade: "Install the Stonewright plugin to use site Context.",
  },
  {
    id: "audit-log",
    label: "Server-side audit log",
    reason:
      "Plugin audit log is separate from the companion Direct JSONL write log.",
    upgrade:
      "Install the Stonewright plugin for the admin Audit Log UI and ability-level audit.",
  },
] as const;

type WpIndex = {
  name?: string | undefined;
  description?: string | undefined;
  url?: string | undefined;
  home?: string | undefined;
  gmt_offset?: number | undefined;
  timezone_string?: string | undefined;
  namespaces?: string[] | undefined;
  authentication?: Record<string, unknown> | undefined;
  routes?: Record<string, unknown> | undefined;
};

type WpType = {
  slug?: string | undefined;
  name?: string | undefined;
  rest_base?: string | undefined;
  hierarchical?: boolean | undefined;
};

type WpTaxonomy = {
  slug?: string | undefined;
  name?: string | undefined;
  rest_base?: string | undefined;
  hierarchical?: boolean | undefined;
};

function detectFromNamespaces(namespaces: string[]) {
  const set = new Set(namespaces.map((n) => n.toLowerCase()));
  const has = (prefix: string) =>
    [...set].some(
      (n) => n === prefix || n.startsWith(`${prefix}/`) || n.startsWith(prefix),
    );
  return {
    elementor: has("elementor"),
    woocommerce: has("wc") || has("wc/v3") || has("wc-admin"),
    acf: has("acf") || has("acf/v3"),
    stonewright_plugin:
      has("mcp/stonewright") || has("stonewright") || has("stonewright/v1"),
    yoast: has("yoast") || has("yoast/v1"),
    rankmath: has("rankmath"),
  };
}

export async function siteDiscover(ctx: DirectToolContext) {
  assertToolEnabled(ctx.site, "stonewright-site-discover");

  let index: WpIndex;
  try {
    index = await ctx.client.get<WpIndex>("/");
  } catch {
    // The raw fallback sends the same credential, so it keeps the same transport rule.
    assertSecureTransport(ctx.site, ctx.env);
    // Some hosts reject bare `/`; fall back via absolute rest root fetch.
    const fetchImpl = ctx.fetchImpl ?? fetch;
    const response = await fetchImpl(ctx.client.restBase.replace(/\/+$/, ""), {
      headers: {
        accept: "application/json",
        authorization: `Basic ${Buffer.from(`${ctx.site.username}:${ctx.site.appPassword}`).toString("base64")}`,
      },
    });
    if (!response.ok) {
      throw new Error(`Failed to read REST index: HTTP ${response.status}`);
    }
    index = (await response.json()) as WpIndex;
  }

  const namespaces = Array.isArray(index.namespaces) ? index.namespaces : [];
  const detected = detectFromNamespaces(namespaces);

  let types: Array<{
    slug: string;
    name: string;
    rest_base: string;
    hierarchical: boolean;
  }> = [];
  let taxonomies: Array<{
    slug: string;
    name: string;
    rest_base: string;
    hierarchical: boolean;
  }> = [];

  try {
    const typeMap =
      await ctx.client.get<Record<string, WpType>>("/wp/v2/types");
    types = Object.values(typeMap ?? {})
      .map((row) => ({
        slug: row.slug ?? "",
        name: row.name ?? "",
        rest_base: row.rest_base ?? "",
        hierarchical: row.hierarchical === true,
      }))
      .filter((row) => row.slug);
  } catch {
    types = [];
  }

  try {
    const taxMap =
      await ctx.client.get<Record<string, WpTaxonomy>>("/wp/v2/taxonomies");
    taxonomies = Object.values(taxMap ?? {})
      .map((row) => ({
        slug: row.slug ?? "",
        name: row.name ?? "",
        rest_base: row.rest_base ?? "",
        hierarchical: row.hierarchical === true,
      }))
      .filter((row) => row.slug);
  } catch {
    taxonomies = [];
  }

  const unavailable = PLUGIN_ONLY_CAPABILITIES.map((cap) => {
    if (cap.id === "elementor-engine" && detected.elementor) {
      return {
        ...cap,
        site_signal:
          "Elementor REST namespace detected on this site; install Stonewright plugin for typed, validated Elementor edits.",
      };
    }
    return { ...cap };
  });

  return {
    mode: "direct" as const,
    site: {
      alias: ctx.site.alias,
      url: ctx.site.url,
      name: index.name ?? "",
      description: index.description ?? "",
      home: index.home ?? index.url ?? ctx.site.url,
    },
    namespaces,
    post_types: types,
    taxonomies,
    detected_plugins: detected,
    available_without_plugin: [
      "content CRUD for ANY registered post type incl. CPTs (stonewright-content-* with type; create via stonewright-content-create)",
      "taxonomy terms for ANY registered taxonomy (stonewright-taxonomy-terms)",
      'ACF field values read/update on posts (stonewright-acf-fields-*; needs ACF "Show in REST")',
      "media upload/list/update",
      "menus + menu items",
      "FSE templates + template parts",
      "global styles (theme.json)",
      "settings",
      "comments moderation",
      "users list/get/me + application passwords",
      "plugins list/activate/deactivate/install",
      "themes list",
      "widgets + sidebars",
      "site health checks",
      "WooCommerce read-only (orders/products/reports when active)",
      "SEO head read",
      "search",
      "block patterns",
      "gutenberg compose (local markup helper)",
      "local skills/memory/task-start (per-machine ~/.stonewright)",
      "WP-CLI tools (local companion)",
    ],
    unavailable_without_plugin: unavailable,
    first_tool: "stonewright-task-start",
    upgrade_hint: detected.stonewright_plugin
      ? "Stonewright plugin namespaces detected. Prefer STONEWRIGHT_MODE=plugin or auto for full ability proxy."
      : "Direct local memory and user skills are active. Install the Stonewright plugin only for its native mutation engine, php-execute, and production-safe confirmation tokens.",
  };
}
