// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The confirmation panel view model.
 *
 * Nothing is applied from this panel. It states what would change, names what
 * cannot be changed at all, and refuses to offer a confirm control when either
 * list makes the answer obvious.
 */

export interface ProposedOperation {
  tool: string;
  target: string;
  summary: string;
  before?: string;
  after?: string;
  breakpoint?: string;
  /**
   * Exact arguments for the editor tool. The other fields describe the change
   * to a person; these are what the adapter is actually called with, so a tool
   * that needs a client id or an idempotency key can be driven from here
   * without the panel inventing a schema of its own.
   */
  args?: Record<string, unknown>;
}

export interface ConfirmationRow extends ProposedOperation {
  before: string;
  after: string;
  breakpoint: string;
}

export interface ConfirmationModel {
  title: string;
  directionLabel: string;
  operations: ConfirmationRow[];
  blocked: string[];
  canConfirm: boolean;
  warning: string | null;
}

export interface ConfirmationInput {
  operations: readonly ProposedOperation[];
  directionLabel: string;
  blocked?: readonly string[];
}

export function describeConfirmation(input: ConfirmationInput): ConfirmationModel {
  const blocked = [...(input.blocked ?? [])];
  const operations: ConfirmationRow[] = input.operations.map((operation) => ({
    ...operation,
    before: operation.before ?? "—",
    after: operation.after ?? "—",
    breakpoint: operation.breakpoint ?? "all",
  }));

  const canConfirm = operations.length > 0 && blocked.length === 0;

  return {
    title: `Apply ${operations.length} change(s) to this page?`,
    directionLabel: input.directionLabel,
    operations,
    blocked,
    canConfirm,
    warning: warningFor(operations.length, blocked.length),
  };
}

export interface ConfirmationHandlers {
  onAllow: () => void;
  onDeny: () => void;
}

export function renderConfirmationPanel(
  doc: Document,
  model: ConfirmationModel,
  handlers: ConfirmationHandlers,
): HTMLElement {
  const panel = doc.createElement("section");
  panel.className = "sw-visual-confirm";
  panel.setAttribute("role", "group");
  panel.setAttribute("aria-label", model.title);

  const heading = doc.createElement("h3");
  heading.className = "sw-visual-confirm__heading";
  heading.textContent = model.title;

  const direction = doc.createElement("p");
  direction.className = "sw-visual-confirm__direction";
  direction.textContent = `Direction: ${model.directionLabel}`;

  panel.append(heading, direction);

  if (model.warning !== null) {
    const warning = doc.createElement("p");
    warning.className = "sw-visual-confirm__warning";
    warning.textContent = model.warning;
    panel.append(warning);
  }

  const list = doc.createElement("ul");
  list.className = "sw-visual-confirm__list";
  for (const operation of model.operations) {
    const item = doc.createElement("li");
    item.className = "sw-visual-confirm__row";

    const target = doc.createElement("span");
    target.className = "sw-visual-confirm__target";
    target.textContent = `${operation.target} · ${operation.breakpoint}`;

    const change = doc.createElement("span");
    change.className = "sw-visual-confirm__change";
    change.textContent = `${operation.before} → ${operation.after}`;

    const summary = doc.createElement("span");
    summary.className = "sw-visual-confirm__summary";
    summary.textContent = operation.summary;

    item.append(target, change, summary);
    list.append(item);
  }
  panel.append(list);

  if (model.blocked.length > 0) {
    const blockedList = doc.createElement("ul");
    blockedList.className = "sw-visual-confirm__blocked";
    for (const reason of model.blocked) {
      const item = doc.createElement("li");
      item.textContent = reason;
      blockedList.append(item);
    }
    panel.append(blockedList);
  }

  const actions = doc.createElement("div");
  actions.className = "sw-visual-confirm__actions";

  const allow = doc.createElement("button");
  allow.type = "button";
  allow.className = "sw-button sw-button--primary";
  allow.textContent = "Apply changes";
  allow.disabled = !model.canConfirm;
  allow.addEventListener("click", handlers.onAllow);

  const deny = doc.createElement("button");
  deny.type = "button";
  deny.className = "sw-button";
  deny.textContent = "Cancel";
  deny.addEventListener("click", handlers.onDeny);

  actions.append(allow, deny);
  panel.append(actions);

  return panel;
}

function warningFor(operationCount: number, blockedCount: number): string | null {
  if (operationCount === 0) {
    return "There is nothing to apply.";
  }
  if (blockedCount > 0) {
    return `${blockedCount} operation(s) cannot be written by the live editor and must be resolved first.`;
  }
  return null;
}
