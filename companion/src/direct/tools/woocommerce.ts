import { assertToolEnabled } from "../writes.js";
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

export async function wcSalesReport(
  ctx: DirectToolContext,
  input: {
    period?: string | undefined;
    date_min?: string | undefined;
    date_max?: string | undefined;
  } = {},
) {
  assertToolEnabled(ctx.site, "stonewright-wc-sales-report");
  try {
    const report = await ctx.client.get<unknown>("/wc/v3/reports/sales", {
      query: {
        period: input.period,
        date_min: input.date_min,
        date_max: input.date_max,
      },
    });
    return { supported: true as const, report };
  } catch (err) {
    return unsupported(err);
  }
}
