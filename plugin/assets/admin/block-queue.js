// SPDX-License-Identifier: GPL-2.0-or-later
(function (root) {
  "use strict";
  function classifyReceipt(payload) {
    if (payload && payload.retryable === false && (payload.status === "serialized" || payload.status === "failed")) return payload.status;
    return "pending";
  }
  async function requestWithBackoff(send, input, options) {
    const immutable = JSON.stringify(input), now = options && options.now || Date.now;
    const sleep = options && options.sleep || ((delay) => new Promise((resolve) => setTimeout(resolve, delay)));
    const deadline = now() + 60000; let last;
    for (let attempt = 0; attempt < 8 && now() <= deadline; attempt++) {
      try { last = await send(JSON.parse(immutable)); } catch { last = { status: 0, payload: { retryable: true } }; }
      const state = classifyReceipt(last.payload);
      if (state !== "pending") return { state, payload: last.payload, attempts: attempt + 1, paused: false };
      if (last.status >= 400 && last.status < 500 && last.payload && last.payload.retryable === false) return { state: "pending", payload: last.payload, attempts: attempt + 1, paused: true };
      if (attempt < 7) await sleep(Math.min(4000, 250 * Math.pow(2, attempt)));
    }
    return { state: "pending", paused: true, payload: last && last.payload, attempts: 8 };
  }
  function createReceiptJournal(send, options) {
    const unresolved = new Map();
    const retry = async (id) => { const receipt = await requestWithBackoff(send, JSON.parse(unresolved.get(id)), options); if (receipt.state !== "pending") unresolved.delete(id); return receipt; };
    return Object.freeze({
      submit: async (id, input) => { const bytes = JSON.stringify(input); if (unresolved.has(id) && unresolved.get(id) !== bytes) throw new Error("Unresolved result bytes cannot change."); if (!unresolved.has(id) && unresolved.size >= 256) throw new Error("Unresolved receipt limit reached."); unresolved.set(id, bytes); return retry(id); },
      resume: async () => { const results = []; for (const id of unresolved.keys()) { const receipt = await retry(id); results.push({ id, receipt }); if (receipt.paused) break; } return results; },
      pending: () => unresolved.size,
    });
  }
  function fitsType(kind, value) {
    if (kind === "rich-text") { const data = root.wp && root.wp.richText && root.wp.richText.RichTextData; return typeof value === "string" || (typeof data === "function" && value instanceof data); }
    if (kind === "array") return Array.isArray(value);
    if (kind === "object") return !!value && typeof value === "object" && !Array.isArray(value);
    if (kind === "integer") return Number.isInteger(value);
    if (kind === "null") return value === null;
    return typeof value === kind;
  }
  function serializeSpec(input, blocks) {
    let count = 0;
    function create(spec, depth) {
      if (!spec || typeof spec !== "object" || Array.isArray(spec) || ++count > 500 || depth > 32 || Object.keys(spec).some((key) => !["name", "attributes", "innerBlocks"].includes(key))) throw new Error("Invalid bounded block specification.");
      const type = blocks.getBlockType(spec.name); if (!type) throw new Error("The native editor has not registered this block.");
      const attributes = spec.attributes || {}, inner = spec.innerBlocks || [];
      if (!attributes || typeof attributes !== "object" || Array.isArray(attributes) || !Array.isArray(inner)) throw new Error("Invalid block attributes or children.");
      for (const key of Object.keys(attributes)) {
        if (["__proto__", "constructor", "prototype"].includes(key) || !Object.prototype.hasOwnProperty.call(type.attributes || {}, key)) throw new Error("Unknown native block attribute.");
        const definition = type.attributes[key], value = attributes[key], kinds = [].concat(definition.type || []);
        if (kinds.length && !kinds.some((kind) => fitsType(kind, value))) throw new Error("Invalid native attribute type.");
        if (Array.isArray(definition.enum) && !definition.enum.some((candidate) => JSON.stringify(candidate) === JSON.stringify(value))) throw new Error("Invalid native attribute option.");
      }
      return blocks.createBlock(spec.name, attributes, inner.map((child) => create(child, depth + 1)));
    }
    if (new TextEncoder().encode(JSON.stringify(input)).length > 131072) throw new Error("Block specification exceeds byte bounds.");
    return blocks.serialize([create(input, 0)]);
  }
  async function hash(text) { return Array.from(new Uint8Array(await root.crypto.subtle.digest("SHA-256", new TextEncoder().encode(text)))).map((byte) => byte.toString(16).padStart(2, "0")).join(""); }
  async function boot(config) {
    if (!config || !config.token || !root.document) return;
    const lease = root.crypto.randomUUID();
    if (config.native && !root.document.querySelector("[data-queue-status]")) { const panel = root.document.createElement("aside"); panel.className = "sw-queue-native-status"; const live = root.document.createElement("p"); live.setAttribute("data-queue-status", ""); live.setAttribute("role", "status"); live.setAttribute("aria-live", "polite"); const resume = root.document.createElement("button"); resume.type = "button"; resume.setAttribute("data-queue-resume", ""); resume.textContent = "Resume block preparation"; panel.append(live, resume); root.document.body.append(panel); }
    const journal = root.document.querySelector("[data-queue-journal]"), status = root.document.querySelector("[data-queue-status]"), counts = root.document.querySelector("[data-queue-counts]");
    let paused = false, running = false, stopped = false; const rows = new Map();
    const say = (text) => { if (status) status.textContent = text; };
    const stateClass = (state) => state === "failed" ? "sw-ui-badge sw-ui-badge--danger" : state === "serialized" || state === "persisted" ? "sw-ui-badge sw-ui-badge--ok" : state === "cancelled" ? "sw-ui-badge" : "sw-ui-badge sw-ui-badge--warn";
    const cell = (label, className) => { const td = root.document.createElement("td"); if (label) td.setAttribute("data-label", label); if (className) td.className = className; return td; };
    const show = (id, state, message) => {
      if (!journal) return;
      let item = rows.get(id);
      if (!item) {
        item = root.document.createElement("tr");
        const change = cell("", "sw-ui-table__primary-cell"), code = root.document.createElement("code"); code.className = "sw-ui-table__primary"; code.textContent = id; change.append(code);
        const stateCell = cell("State"), badge = root.document.createElement("span"); badge.setAttribute("data-queue-state", ""); stateCell.append(badge);
        const detail = cell("Details"), label = root.document.createElement("span"); label.setAttribute("data-queue-label", ""); detail.append(label);
        const actions = cell("", "sw-ui-table__actions"); actions.setAttribute("data-queue-actions", "");
        item.append(change, stateCell, detail, actions); journal.append(item); rows.set(id, item);
        const empty = root.document.querySelector("[data-queue-empty]"); if (empty) empty.hidden = true;
      }
      const badge = item.querySelector("[data-queue-state]"); badge.className = stateClass(state); badge.textContent = state.charAt(0).toUpperCase() + state.slice(1);
      item.querySelector("[data-queue-label]").textContent = message || "";
    };
    const send = async (route, payload) => {
      const response = await root.fetch(config.base + route, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", "X-WP-Nonce": config.nonce }, body: JSON.stringify(payload) });
      const value = await response.json(); return { status: response.status, payload: value && value.data && value.retryable === undefined ? { ...value, retryable: value.data.retryable } : value };
    };
    const receipts = createReceiptJournal((payload) => send("result", payload));
    const showCounts = (value) => { if (!counts || !value || typeof value !== "object") return; counts.replaceChildren(); for (const state of ["queued", "serialized", "persisted", "failed", "cancelled"]) { const number = value[state]; if (!Number.isInteger(number) || number < 0) continue; const stat = root.document.createElement("div"), term = root.document.createElement("span"), detail = root.document.createElement("span"); stat.className = "sw-ui-stat"; term.className = "sw-ui-stat__label"; detail.className = "sw-ui-stat__value sw-ui-num"; term.textContent = state.charAt(0).toUpperCase() + state.slice(1); detail.textContent = String(number); stat.append(term, detail); counts.append(stat); } };
    const publish = (id, state, message) => { show(id, state, message); if (root.parent !== root) root.parent.postMessage({ type: "stonewright-queue-progress", id, state, message: message || "" }, root.location.origin); };
    const publishReceipt = (id, receipt) => { publish(id, receipt.state, receipt.paused ? "Awaiting an explicit terminal receipt; resume to check the queue." : receipt.state === "failed" ? "Review the error and submit a corrected batch." : "Ready for guarded finalization"); if (receipt.paused) { paused = true; say("Processing paused without a verified terminal receipt."); } };
    const process = async () => {
      if (running || stopped || paused) return; running = true;
      try {
        for (const resumed of await receipts.resume()) { publishReceipt(resumed.id, resumed.receipt); if (paused) return; }
        const claim = await send("claim", { token: config.token, lease_id: lease });
        if (claim.status >= 400) { paused = true; say("Queue processing paused. Refresh the session or resume after reviewing the error."); return; }
        showCounts(claim.payload.counts);
        for (const item of claim.payload.items || []) {
          if (paused || stopped) break; publish(item.id, "pending", "Preparing native serialization");
          const resultId = root.crypto.randomUUID(); let result;
          try {
            const deadline = Date.now() + 60000; let html;
            while (html === undefined) { try { html = serializeSpec(item.block_spec, root.wp.blocks); } catch (error) { if (Date.now() >= deadline) throw error; await new Promise((resolve) => setTimeout(resolve, 500)); } }
            if (new TextEncoder().encode(html).length > config.maxBytes) throw new Error("Serialized result exceeds the queue byte limit.");
            result = { token: config.token, lease_id: lease, result_id: resultId, change_id: item.id, status: "serialized", html, html_hash: await hash(html) };
          } catch (error) { result = { token: config.token, lease_id: lease, result_id: resultId, change_id: item.id, status: "failed", error_code: "native_serialization_failed", message: String(error.message || "Native serialization failed.").slice(0, 400) }; }
          const receipt = await receipts.submit(item.id, result); publishReceipt(item.id, receipt);
          if (receipt.paused) break;
        }
      } catch { paused = true; say("Queue connection is unavailable. Resume when the session is ready."); } finally { running = false; }
      if (!paused && !stopped) setTimeout(process, 1000);
    };
    const heartbeat = async () => { if (stopped) return; try { const receipt = await send("heartbeat", { token: config.token, lease_id: lease }); if (receipt.status >= 400) { paused = true; say("Queue authorization needs attention."); } else { showCounts(receipt.payload.counts); say(paused ? "Processing paused. Review pending operations before resuming." : "Native queue session is connected."); } } catch { paused = true; say("Queue connection is unavailable."); } if (!stopped) setTimeout(heartbeat, 15000); };
    const resume = root.document.querySelector("[data-queue-resume]");
    root.addEventListener("pagehide", () => { stopped = true; });
    if (config.native) { if (resume) resume.addEventListener("click", () => { paused = false; void process(); }); root.addEventListener("message", (event) => { if (event.origin === root.location.origin && event.source === root.parent && event.data && event.data.type === "stonewright-queue-resume") { paused = false; void process(); } }); void process(); }
    else {
      const container = root.document.querySelector("[data-queue-frames]"); const frames = new Map();
      for (const target of config.targets || []) {
        show(target.change_id, target.status, "Native editor session");
        const row = rows.get(target.change_id);
        if (row && target.queue_url && !row.querySelector("[data-queue-cancel]")) {
          const queueUrl = new URL(target.queue_url, root.location.href), queueToken = queueUrl.origin === root.location.origin ? queueUrl.searchParams.get("stonewright_queue_token") : null;
          if (queueToken) {
            const cancel = root.document.createElement("button"); cancel.type = "button"; cancel.className = "sw-ui-btn sw-ui-btn--danger sw-ui-btn--sm"; cancel.setAttribute("data-queue-cancel", ""); cancel.textContent = "Preview cancellation"; cancel.setAttribute("aria-label", "Preview cancellation of change " + target.change_id); const actions = row.querySelector("[data-queue-actions]") || row; actions.append(cancel);
            let previewed = false, grant;
            cancel.addEventListener("click", async () => {
              cancel.disabled = true;
              try {
                const payload = { token: queueToken, change_ids: [target.change_id], dry_run: !previewed };
                if (previewed) { payload.confirm_cancel = true; if (grant && grant.value) payload.confirmation_token = grant.value; }
                const receipt = await send("cancel", payload);
                if (receipt.status >= 400) { say("Cancellation was not applied. Review authorization and the required confirmation token."); return; }
                if (!previewed && receipt.payload.dry_run === true && receipt.payload.verification_status === "planned") {
                  previewed = true; cancel.textContent = "Confirm cancellation"; cancel.setAttribute("aria-label", "Confirm cancellation of change " + target.change_id); say("Cancellation preview is ready for " + target.change_id + ". Confirm to remove this queued operation; saved content is unchanged.");
                  if (config.mode === "production-safe") { const label = root.document.createElement("label"); label.className = "sw-ui-field__label"; label.textContent = "Confirmation token for this cancellation "; grant = root.document.createElement("input"); grant.type = "password"; grant.className = "sw-ui-input"; grant.autocomplete = "off"; label.append(grant); actions.append(label); }
                } else if (previewed && receipt.payload.effect_verified === true && receipt.payload.verification_status === "verified") { show(target.change_id, "cancelled", "Queue cancellation verified"); if (grant) grant.value = ""; cancel.remove(); }
                else say("Cancellation outcome is unverified. Check the queue before retrying.");
              } catch { say("Cancellation connection is unavailable. Check the queue before retrying."); }
              finally { cancel.disabled = false; }
            });
          }
        }
        if (!container || !target.editor_frame_url || frames.has(target.editor_frame_url)) continue;
        const url = new URL(target.editor_frame_url, root.location.href); if (url.origin !== root.location.origin) continue;
        const frame = root.document.createElement("iframe"); frame.title = "Native block serialization session"; frame.src = url.href; container.append(frame); frames.set(url.href, frame);
      }
      root.addEventListener("message", (event) => {
        if (event.origin !== root.location.origin || ![...frames.values()].some((frame) => frame.contentWindow === event.source)) return;
        const message = event.data; if (!message || message.type !== "stonewright-queue-progress" || typeof message.id !== "string" || !["pending", "serialized", "failed"].includes(message.state) || !(config.targets || []).some((target) => target.change_id === message.id)) return;
        show(message.id, message.state, typeof message.message === "string" ? message.message.slice(0, 500) : "");
      });
      if (resume) resume.addEventListener("click", () => { for (const frame of frames.values()) frame.contentWindow.postMessage({ type: "stonewright-queue-resume" }, root.location.origin); });
    }
    void heartbeat();
  }
  root.StonewrightQueueClient = Object.freeze({ classifyReceipt, requestWithBackoff, createReceiptJournal, serializeSpec, boot });
  if (root.document) { const start = () => { void boot(root.stonewrightBlockQueue); }; if (root.document.readyState === "loading") root.document.addEventListener("DOMContentLoaded", start, { once: true }); else start(); }
})(globalThis);
