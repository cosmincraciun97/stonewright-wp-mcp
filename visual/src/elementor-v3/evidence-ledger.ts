// SPDX-License-Identifier: AGPL-3.0-or-later

import type { SettingEvidence, SettingsEvidenceInput } from "./types.js";

export interface EvidenceLedgerEntry {
  operation_id: string;
  element_id?: string;
  widget_type: string;
  recorded_at: string;
  settings: SettingEvidence[];
}

export class ElementorV3EvidenceLedger {
  private readonly entries: EvidenceLedgerEntry[] = [];

  validate(input: {
    operationId: string;
    elementId?: string;
    widgetType: string;
    settingKeys: string[];
    schemaHash: string;
    evidence: SettingsEvidenceInput;
  }): EvidenceLedgerEntry {
    const normalized: SettingEvidence[] = [];
    for (const settingKey of input.settingKeys.filter((key) => !["__dynamic__", "__globals__"].includes(key))) {
      const row = input.evidence[settingKey];
      if (!row) throw new Error(`Missing evidence for setting: ${settingKey}`);
      const controlKey = row.control_key || baseResponsiveKey(settingKey);
      if (controlKey !== baseResponsiveKey(settingKey)) {
        throw new Error(`Evidence control_key mismatch for ${settingKey}: ${controlKey}`);
      }
      if (row.schema_hash !== input.schemaHash) {
        throw new Error(`Evidence schema_hash mismatch for ${settingKey}; refresh the live schema.`);
      }
      if (typeof row.source !== "string" || row.source.trim() === "") throw new Error(`Evidence source is required for ${settingKey}.`);
      if (typeof row.confidence !== "number" || row.confidence < 0 || row.confidence > 1) {
        throw new Error(`Evidence confidence for ${settingKey} must be between 0 and 1.`);
      }
      if (typeof row.responsive_scope !== "string" || row.responsive_scope.trim() === "") {
        throw new Error(`Evidence responsive_scope is required for ${settingKey}.`);
      }
      if (typeof row.requires_confirmation !== "boolean") {
        throw new Error(`Evidence requires_confirmation must be boolean for ${settingKey}.`);
      }
      normalized.push({ ...row, control_key: controlKey } as SettingEvidence);
    }
    const entry: EvidenceLedgerEntry = {
      operation_id: input.operationId,
      element_id: input.elementId,
      widget_type: input.widgetType,
      recorded_at: new Date().toISOString(),
      settings: normalized,
    };
    return entry;
  }

  record(entry: EvidenceLedgerEntry): void {
    this.entries.push(structuredClone(entry));
  }

  list(): EvidenceLedgerEntry[] {
    return structuredClone(this.entries);
  }

  size(): number { return this.entries.length; }

  truncate(size: number): void {
    this.entries.splice(Math.max(0, size));
  }
}

export function baseResponsiveKey(key: string): string {
  return key.replace(/_(widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile)$/, "");
}
