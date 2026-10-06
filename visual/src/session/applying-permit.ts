// SPDX-License-Identifier: AGPL-3.0-or-later
import { WorkspaceFailure } from "./protocol.js";
const live = new WeakSet<object>();
export interface ApplyingPermit { readonly actionId: string; }
/** Internal authority handoff. This module is not a package export. */
export function issueApplyingPermit(actionId: string): ApplyingPermit { const permit = Object.freeze({ actionId }); live.add(permit); return permit; }
export function consumeApplyingPermit(permit: ApplyingPermit): void { if (!permit || !live.delete(permit)) throw new WorkspaceFailure("workspace_applying_required", "A live one-use applying permit is required."); }
