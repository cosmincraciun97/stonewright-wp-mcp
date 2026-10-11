import { assertToolEnabled, DirectSafetyBlockedError } from "../writes.js";
import { WpRestError } from "../wp-rest-client.js";
import type { DirectToolContext } from "./types.js";

type WcProduct = {
  id: number;
  name?: string | undefined;
  sku?: string | undefined;
  price?: string | undefined;
  status?: string | undefined;
  stock_status?: string | undefined;
};

type WcOrder = {
  id: number;
  status?: string | undefined;
  total?: string | undefined;
  currency?: string | undefined;
  date_created?: string | undefined;
  customer_id?: number | undefined;
};

function unsupported(err: unknown) {
  if (
    err instanceof WpRestError &&
    (err.status === 404 || err.code.includes("rest_no_route"))
  ) {
    return {
      supported: false as const,
      hint: "WooCommerce is not active on this site.",
    };
  }
  throw err;
}

export async function wcProducts(
  ctx: DirectToolContext,
  input: {
    search?: string | undefined;
    status?: string | undefined;
    per_page?: number | undefined;
    page?: number | undefined;
  } = {},
) {
  assertToolEnabled(ctx.site, "stonewright-wc-products");
  const perPage = Math.min(Math.max(input.per_page ?? 20, 1), 50);
  const page = Math.max(input.page ?? 1, 1);
  try {
    const items = await ctx.client.get<WcProduct[]>("/wc/v3/products", {
      query: {
        search: input.search,
        status: input.status,
        per_page: perPage,
        page,
      },
    });
    const list = Array.isArray(items) ? items : [];
    return {
      supported: true as const,
      items: list.map((p) => ({
        id: p.id,
        name: p.name ?? "",
        sku: p.sku ?? "",
        price: p.price ?? "",
        status: p.status ?? "",
        stock_status: p.stock_status ?? "",
      })),
      total: list.length,
      page,
      per_page: perPage,
    };
  } catch (err) {
    return unsupported(err);
  }
}

export async function wcOrders(
  ctx: DirectToolContext,
  input: {
    status?: string | undefined;
    after?: string | undefined;
    before?: string | undefined;
    per_page?: number | undefined;
    page?: number | undefined;
  } = {},
) {
  assertToolEnabled(ctx.site, "stonewright-wc-orders");
  const perPage = Math.min(Math.max(input.per_page ?? 20, 1), 50);
  const page = Math.max(input.page ?? 1, 1);
  try {
    const items = await ctx.client.get<WcOrder[]>("/wc/v3/orders", {
      query: {
        status: input.status,
        after: input.after,
        before: input.before,
        per_page: perPage,
        page,
      },
    });
    const list = Array.isArray(items) ? items : [];
    return {
      supported: true as const,
      items: list.map((o) => ({
        id: o.id,
        status: o.status ?? "",
        total: o.total ?? "",
        currency: o.currency ?? "",
        date_created: o.date_created ?? "",
        customer_id: o.customer_id ?? 0,
      })),
      total: list.length,
      page,
      per_page: perPage,
    };
  } catch (err) {
    return unsupported(err);
  }
}

/** The period values of the plugin. Any other value is a WooCommerce period (week, month, last_month, year). */
const PLUGIN_PERIODS = new Set(["last_7_days", "last_30_days", "year_to_date", "custom"]);
const SALES_TOOL = "stonewright-wc-sales-report";

function validDay(value: string | undefined): value is string {
  if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const parsed = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value;
}

/**
 * Turns the plugin's period values into the date range WooCommerce takes (date_min, date_max, UTC days,
 * today included, as the plugin counts). Returns null for a period that is not one of the plugin's, which
 * Direct passes to WooCommerce unchanged.
 */
export function salesReportRange(
  input: { period?: string | undefined; date_min?: string | undefined; date_max?: string | undefined },
  now: Date = new Date(),
  site = "_global",
): { date_min: string; date_max: string } | null {
  const period = input.period;
  if (!period || !PLUGIN_PERIODS.has(period)) return null;
  const refuse = (message: string) =>
    new DirectSafetyBlockedError("invalid_date_range", message, SALES_TOOL, site);
  if (period === "custom") {
    if (!validDay(input.date_min) || !validDay(input.date_max)) {
      throw refuse("period=custom needs date_from and date_to (or date_min and date_max) as real YYYY-MM-DD days.");
    }
    if (input.date_min > input.date_max) {
      throw refuse("The start of the range is after its end.");
    }
    return { date_min: input.date_min, date_max: input.date_max };
  }
  if (input.date_min !== undefined || input.date_max !== undefined) {
    throw refuse(`period=${period} sets the range itself; leave out the dates or use period=custom.`);
  }
  const today = now.toISOString().slice(0, 10);
  const end = new Date(`${today}T00:00:00Z`);
  const start = new Date(end);
  if (period === "last_7_days") start.setUTCDate(start.getUTCDate() - 6);
  else if (period === "last_30_days") start.setUTCDate(start.getUTCDate() - 29);
  else start.setUTCMonth(0, 1);
  return { date_min: start.toISOString().slice(0, 10), date_max: today };
}

export async function wcSalesReport(
  ctx: DirectToolContext,
  input: {
    period?: string | undefined;
    date_min?: string | undefined;
    date_max?: string | undefined;
  } = {},
) {
  assertToolEnabled(ctx.site, SALES_TOOL);
  const range = salesReportRange(input, new Date(), ctx.site.alias);
  try {
    const report = await ctx.client.get<unknown>("/wc/v3/reports/sales", {
      query: range
        ? { date_min: range.date_min, date_max: range.date_max }
        : {
            period: input.period,
            date_min: input.date_min,
            date_max: input.date_max,
          },
    });
    return {
      supported: true as const,
      report,
      ...(range ? { period: input.period, ...range } : {}),
    };
  } catch (err) {
    return unsupported(err);
  }
}
