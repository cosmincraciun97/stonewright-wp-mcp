// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The evidence panel view model.
 *
 * A rule with no evidence behind it is not a pass. It is reported as unchecked
 * and it keeps the run from claiming verification, which is the same contract
 * `stonewright/design-quality-check` uses on the server.
 */

export type EvidenceStatus = "pass" | "fail" | "warn" | "not_checked";

export interface EvidenceEntry {
  label: string;
  status: EvidenceStatus;
  rule?: string;
  viewport?: string;
  measured?: string;
  source?: string;
}

export interface EvidenceSummary {
  total: number;
  pass: number;
  fail: number;
  warn: number;
  notChecked: number;
  verified: boolean;
}

export interface EvidenceRow extends EvidenceEntry {
  measured: string;
  viewport: string;
}

export interface EvidencePanelModel {
  summary: EvidenceSummary;
  rows: EvidenceRow[];
  notice: string | null;
}

export function summarizeEvidence(entries: readonly EvidenceEntry[]): EvidenceSummary {
  const count = (status: EvidenceStatus): number => entries.filter((entry) => entry.status === status).length;

  const summary: EvidenceSummary = {
    total: entries.length,
    pass: count("pass"),
    fail: count("fail"),
    warn: count("warn"),
    notChecked: count("not_checked"),
    verified: false,
  };

  summary.verified = summary.total > 0 && summary.fail === 0 && summary.notChecked === 0;
  return summary;
}

export function describeEvidencePanel(entries: readonly EvidenceEntry[]): EvidencePanelModel {
  const summary = summarizeEvidence(entries);

  return {
    summary,
    rows: entries.map((entry) => ({
      ...entry,
      measured: entry.measured ?? "—",
      viewport: entry.viewport ?? "—",
    })),
    notice: noticeFor(summary),
  };
}

export function renderEvidencePanel(doc: Document, model: EvidencePanelModel): HTMLElement {
  const section = doc.createElement("section");
  section.className = "sw-visual-evidence";
  section.setAttribute("data-sw-evidence", model.summary.verified ? "verified" : "unverified");

  const heading = doc.createElement("h3");
  heading.className = "sw-visual-evidence__heading";
  heading.textContent = `Evidence — ${model.summary.pass}/${model.summary.total} passed`;
  section.append(heading);

  if (model.notice !== null) {
    const notice = doc.createElement("p");
    notice.className = "sw-visual-evidence__notice";
    notice.textContent = model.notice;
    section.append(notice);
  }

  const list = doc.createElement("ul");
  list.className = "sw-visual-evidence__list";
  for (const row of model.rows) {
    const item = doc.createElement("li");
    item.className = `sw-visual-evidence__row sw-visual-evidence__row--${row.status}`;
    item.setAttribute("data-sw-evidence-status", row.status);

    const label = doc.createElement("span");
    label.className = "sw-visual-evidence__label";
    label.textContent = row.label;

    const measured = doc.createElement("span");
    measured.className = "sw-visual-evidence__measured";
    measured.textContent = `${row.viewport} · ${row.measured}`;

    item.append(label, measured);
    list.append(item);
  }
  section.append(list);

  return section;
}

function noticeFor(summary: EvidenceSummary): string | null {
  if (summary.total === 0) {
    return "Nothing was checked, so nothing passed.";
  }
  if (summary.notChecked > 0) {
    return `${summary.notChecked} rule(s) had no evidence to run against and stay unverified.`;
  }
  if (summary.fail > 0) {
    return `${summary.fail} rule(s) failed against the captured render.`;
  }
  return null;
}
