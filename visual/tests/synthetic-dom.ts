// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * A minimal in-memory document for rendering tests that run without a browser.
 *
 * It models only what the workspace panels use: element creation, attributes,
 * classes, text, child lists, click listeners, and lookups by tag, class, or
 * attribute. Assigning markup throws, so a panel that ever turned a string
 * into HTML instead of text fails the test that renders it.
 */

type Listener = (event: { type: string; target: SyntheticNode }) => void;

export class SyntheticNode {
  readonly childNodes: SyntheticNode[] = [];
  parent: SyntheticNode | null = null;
  type = "";
  disabled = false;
  private ownText = "";
  private readonly attributes = new Map<string, string>();
  private readonly listeners = new Map<string, Listener[]>();

  constructor(readonly ownerDocument: SyntheticDocument, readonly localName: string) {}

  get className(): string { return this.getAttribute("class") ?? ""; }
  set className(value: string) { this.setAttribute("class", value); }

  get classList(): { add: (...names: string[]) => void; remove: (...names: string[]) => void; contains: (name: string) => boolean } {
    const names = (): string[] => this.className.split(/\s+/).filter(Boolean);
    return {
      add: (...added) => { this.className = [...new Set([...names(), ...added])].join(" "); },
      remove: (...removed) => { this.className = names().filter((name) => !removed.includes(name)).join(" "); },
      contains: (name) => names().includes(name),
    };
  }

  setAttribute(name: string, value: string): void { this.attributes.set(name, String(value)); }
  getAttribute(name: string): string | null { return this.attributes.get(name) ?? null; }
  hasAttribute(name: string): boolean { return this.attributes.has(name); }

  get textContent(): string {
    return this.localName === "#text" ? this.ownText : this.childNodes.map((child) => child.textContent).join("");
  }

  set textContent(value: string) {
    for (const child of this.childNodes) child.parent = null;
    this.childNodes.length = 0;
    if (this.localName === "#text") this.ownText = value;
    else if (value) this.append(value);
  }

  set innerHTML(_value: string) { throw new Error("Rendering must assign text, never markup."); }

  get children(): SyntheticNode[] { return this.childNodes.filter((child) => child.localName !== "#text"); }

  append(...nodes: Array<SyntheticNode | string>): void {
    for (const node of nodes) {
      const child = typeof node === "string" ? this.ownerDocument.createTextNode(node) : node;
      child.parent?.removeChild(child);
      child.parent = this;
      this.childNodes.push(child);
    }
  }

  removeChild(child: SyntheticNode): void {
    const index = this.childNodes.indexOf(child);
    if (index >= 0) this.childNodes.splice(index, 1);
    child.parent = null;
  }

  addEventListener(type: string, listener: Listener): void {
    this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener]);
  }

  /** Dispatches a click the way a browser does: a disabled control ignores it. */
  click(): void {
    if (this.disabled) return;
    this.dispatch("click");
  }

  /** Fires listeners the way a script-dispatched event does, which a disabled control does not block. */
  dispatch(type: string): void {
    for (const listener of this.listeners.get(type) ?? []) listener({ type, target: this });
  }

  querySelectorAll(selector: string): SyntheticNode[] {
    const matches = compileSelector(selector);
    const found: SyntheticNode[] = [];
    const walk = (node: SyntheticNode): void => {
      for (const child of node.children) {
        if (matches(child)) found.push(child);
        walk(child);
      }
    };
    walk(this);
    return found;
  }

  querySelector(selector: string): SyntheticNode | null {
    return this.querySelectorAll(selector)[0] ?? null;
  }
}

export class SyntheticDocument {
  readyState: "loading" | "interactive" | "complete" = "complete";
  readonly body: SyntheticNode;
  private readonly listeners = new Map<string, Array<() => void>>();

  constructor() { this.body = this.createElement("body"); }

  createElement(tag: string): SyntheticNode { return new SyntheticNode(this, tag.toLowerCase()); }

  createTextNode(text: string): SyntheticNode {
    const node = new SyntheticNode(this, "#text");
    node.textContent = text;
    return node;
  }

  querySelector(selector: string): SyntheticNode | null { return this.body.querySelector(selector); }

  querySelectorAll(selector: string): SyntheticNode[] { return this.body.querySelectorAll(selector); }

  addEventListener(type: string, listener: () => void): void {
    this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener]);
  }

  /** Fires a document event once, as a page does when it finishes loading. */
  fire(type: string): void {
    const listeners = this.listeners.get(type) ?? [];
    this.listeners.delete(type);
    for (const listener of listeners) listener();
  }
}

/** Supports one compound selector: an optional tag, then any classes and attribute tests. */
function compileSelector(selector: string): (node: SyntheticNode) => boolean {
  const match = /^([a-z][a-z0-9-]*)?((?:\.[\w-]+|\[[\w-]+(?:="[^"]*")?\])*)$/i.exec(selector.trim());
  if (!match || (!match[1] && !match[2])) throw new Error(`Unsupported selector in rendering tests: ${selector}`);
  const tag = match[1]?.toLowerCase();
  const parts = [...match[2].matchAll(/\.([\w-]+)|\[([\w-]+)(?:="([^"]*)")?\]/g)];
  return (node) => (!tag || node.localName === tag) && parts.every(([, className, attribute, value]) => {
    if (className !== undefined) return node.classList.contains(className);
    return value === undefined ? node.hasAttribute(attribute) : node.getAttribute(attribute) === value;
  });
}

/** The synthetic document as the DOM type the workspace modules accept. */
export function asDocument(doc: SyntheticDocument): Document {
  return doc as unknown as Document;
}

/** A synthetic element as the DOM type the workspace modules accept. */
export function asElement(node: SyntheticNode): HTMLElement {
  return node as unknown as HTMLElement;
}

/** The synthetic node behind an element a workspace module returned. */
export function synthetic(element: Element | HTMLElement): SyntheticNode {
  const node: unknown = element;
  if (!(node instanceof SyntheticNode)) throw new Error("Expected an element created by the synthetic document.");
  return node;
}

/** The first matching element below a root, failing the test when it is absent. */
export function find(root: SyntheticNode, selector: string): SyntheticNode {
  const node = root.querySelector(selector);
  if (node === null) throw new Error(`Nothing matches ${selector}.`);
  return node;
}

/** The button below a root whose visible label is exactly the given text. */
export function button(root: SyntheticNode, label: string): SyntheticNode {
  const match = root.querySelectorAll("button").find((candidate) => candidate.textContent === label);
  if (!match) throw new Error(`No button is labelled ${label}.`);
  return match;
}
