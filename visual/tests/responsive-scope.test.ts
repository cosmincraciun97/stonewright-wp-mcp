// SPDX-License-Identifier: AGPL-3.0-or-later
import { describe, expect, it } from "vitest";
import {
  assertResponsiveScope,
  hashNonTargetBreakpoints,
  settingBreakpoint,
  validateElementorV3Settings,
} from "../src/elementor-v3/settings-validator.js";
import type { ElementorV3WidgetSchema } from "../src/elementor-v3/types.js";

const schema: ElementorV3WidgetSchema = {
  widget_type: "heading",
  schema_hash: "abc",
  controls: {
    title: { type: "text", responsive: true },
    html: { type: "textarea", responsive: false },
  },
};

describe("responsive scope", () => {
  it("maps breakpoint suffixes", () => {
    expect(settingBreakpoint("title_mobile")).toBe("mobile");
    expect(settingBreakpoint("title_laptop")).toBe("laptop");
    expect(settingBreakpoint("title")).toBe("desktop");
  });

  it("allows mobile-only keys", () => {
    expect(() =>
      assertResponsiveScope({ title_mobile: "m" }, ["mobile"], schema),
    ).not.toThrow();
  });

  it("rejects base and tablet keys on mobile-only tasks", () => {
    expect(() =>
      assertResponsiveScope({ title: "d", title_mobile: "m" }, ["mobile"], schema),
    ).toThrow(/responsive_scope_violation/);
    expect(() =>
      assertResponsiveScope({ title_tablet: "t" }, ["mobile"], schema),
    ).toThrow(/responsive_scope_violation/);
  });

  it("returns unsupported_responsive_control for non-responsive controls", () => {
    expect(() =>
      assertResponsiveScope({ html_mobile: "x" }, ["mobile"], schema),
    ).toThrow(/unsupported_responsive_control/);
  });

  it("keeps non-target hashes stable when only mobile changes", () => {
    const base = {
      title: "D",
      title_tablet: "T",
      title_mobile: "M1",
    };
    const h1 = hashNonTargetBreakpoints(base, ["mobile"]);
    const h2 = hashNonTargetBreakpoints({ ...base, title_mobile: "M2" }, ["mobile"]);
    expect(h1).toBe(h2);
    const h3 = hashNonTargetBreakpoints({ ...base, title_tablet: "T2" }, ["mobile"]);
    expect(h3).not.toBe(h1);
  });

  it("validateElementorV3Settings honors allowedBreakpoints", () => {
    expect(() =>
      validateElementorV3Settings(schema, { title_mobile: "ok" }, { title_mobile: "ok" }, {
        allowedBreakpoints: ["mobile"],
      }),
    ).not.toThrow();
    expect(() =>
      validateElementorV3Settings(schema, { title: "desk" }, { title: "desk" }, {
        allowedBreakpoints: ["mobile"],
      }),
    ).toThrow(/responsive_scope_violation/);
  });
});
