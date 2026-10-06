// SPDX-License-Identifier: GPL-2.0-or-later
import { ActionLedger } from "../src/session/action-ledger.js";
import type { DeclaredToolSet } from "../src/editor-tools/declared-tool-set.js";
export async function authorizedWrite(set: DeclaredToolSet, name: string, args: Record<string, unknown>): Promise<unknown> {
  let failure: unknown;
  const ledger = new ActionLedger({ decisionAuthority: { recordedDecision: async () => true } });
  const action = ledger.propose({ action: `page_tool:${name}`, title: name, session: "synthetic-session", args, fresh: async () => true, execute: async (approved, permit) => { try { return await set.apply(name, approved, permit); } catch (cause) { failure = cause; throw cause; } } });
  const result = await ledger.decide(action.actionId, "allow_once");
  if (failure) throw failure;
  return result.result;
}
