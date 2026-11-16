// Copied from packages/widgets/src/placement-runner.mjs by apps/shopware/scripts/build-storefront.mjs; do not edit by hand.
// Drives the placement check in an admin page (decision 0024): loads the shop's pages into
// frames, inspects each at desktop and phone width, and shows the chosen page as a live preview
// with the withdrawal link outlined. The platform supplies `load(frame, page)`: WooCommerce sets
// the frame's address (same origin), Shopify writes the page the app server read into a sandboxed
// frame. Rendering of the results is left to the platform (`onUpdate`).
import {
  clearMarks,
  inspectPlacement,
  markPlacement,
  placementChecks,
  placementMessages,
  PLACEMENT_TEXT,
} from "./placement.mjs";

export const WIDTHS = { desktop: { width: 1280, height: 800 }, phone: { width: 390, height: 844 } };

const settle = (win, ms) => new Promise((r) => win.setTimeout(r, ms));

/** Resolves true on the frame's next load event, false after the timeout. */
function loaded(frame, timeoutMs) {
  return new Promise((resolve) => {
    const done = (ok) => {
      frame.removeEventListener("load", onLoad);
      clearTimeout(timer);
      resolve(ok);
    };
    const onLoad = () => done(true);
    const timer = setTimeout(() => done(false), timeoutMs);
    frame.addEventListener("load", onLoad);
  });
}

function frameDocument(frame) {
  try {
    const doc = frame.contentDocument;
    // The empty page a frame holds before anything loads, or after the site refused it.
    if (!doc || !doc.body || (doc.location.href === "about:blank" && !doc.body.firstChild))
      return null;
    return doc;
  } catch {
    return null; // another origin: the site refused the frame or redirected away
  }
}

function sizeFrame(frame, width) {
  const { width: w, height: h } = WIDTHS[width];
  frame.style.width = `${w}px`;
  frame.style.height = `${h}px`;
}

/** Status of a page from its checks: fail beats warn beats pass. */
export function pageStatus(checks) {
  if (checks.some((c) => c.status === "fail")) return "fail";
  if (checks.some((c) => c.status === "warn")) return "warn";
  return "pass";
}

/**
 * @param {object} o
 * @param {HTMLElement} o.stage element that holds the visible preview frame
 * @param {{ key: string, label: string, url?: string, expectLink?: boolean }[]} o.pages
 * @param {(frame: HTMLIFrameElement, page: any) => (void | Promise<void>)} o.load
 * @param {string[]} o.labels reviewed withdrawal button labels
 * @param {string[]} o.paths withdrawal page paths
 * @param {(state: any) => void} o.onUpdate
 * @param {object} [o.text] check text (PLACEMENT_TEXT keys)
 * @param {string} [o.sandbox] sandbox attribute for the frames (Shopify: "allow-same-origin")
 * @param {string} [o.title] accessible name of the preview frame
 * @param {(href: string) => void} [o.onNavigate] if given, link clicks in the preview call it
 *   instead of navigating the frame (Shopify: the sandboxed copy cannot follow links itself)
 */
export function createRunner({
  stage,
  pages,
  load,
  labels = [],
  paths = [],
  onUpdate,
  onNavigate = null,
  text = PLACEMENT_TEXT,
  sandbox = null,
  title = "Preview of your shop",
  loadTimeoutMs = 20000,
  settleMs = 600,
}) {
  const doc = stage.ownerDocument;
  const win = doc.defaultView;
  const state = {
    width: "desktop",
    current: pages[0]?.key || "",
    sweeping: false,
    pages: pages.map((p) => ({ ...p, status: "waiting", desktop: null, phone: null })),
    preview: { status: "loading", messages: [], path: "" },
  };
  const emit = () =>
    onUpdate({
      ...state,
      pages: state.pages.map((p) => ({ ...p })),
      preview: { ...state.preview },
    });

  const make = (offscreen) => {
    const f = doc.createElement("iframe");
    if (sandbox !== null) f.setAttribute("sandbox", sandbox);
    f.setAttribute("referrerpolicy", "same-origin");
    if (offscreen) {
      f.setAttribute("aria-hidden", "true");
      f.setAttribute("tabindex", "-1");
      f.title = "";
      f.style.cssText =
        "position:absolute;left:-20000px;top:0;border:0;visibility:visible;pointer-events:none";
      doc.body.appendChild(f);
    } else {
      f.title = title;
      f.className = "shopproof-placement__frame";
      f.style.border = "0";
      stage.appendChild(f);
    }
    return f;
  };
  const preview = make(false);
  const sweeper = make(true);
  let destroyed = false;
  let previewToken = 0;
  let currentPage = null;

  const run = (d, page) => {
    const result = inspectPlacement(d, { paths, labels });
    const checks = placementChecks(result, { expectLink: page?.expectLink !== false });
    return {
      result,
      checks,
      messages: placementMessages(checks, text),
      status: pageStatus(checks),
    };
  };

  async function inspectPreview() {
    const d = frameDocument(preview);
    if (!d) {
      state.preview = { status: "blocked", messages: [], path: "" };
      emit();
      return;
    }
    await settle(win, settleMs);
    if (destroyed) return;
    clearMarks(d);
    const out = run(d, currentPage);
    markPlacement(d, out.result);
    if (onNavigate && !d.shopproofClicks) {
      d.shopproofClicks = true;
      d.addEventListener("click", (event) => {
        const a =
          event.target instanceof d.defaultView.Element ? event.target.closest("a[href]") : null;
        event.preventDefault();
        if (a) onNavigate(a.href);
      });
    }
    const first = out.result.links.find((l) => (l.ours || l.toPage) && l.visible);
    first?.element.scrollIntoView({ block: "center" });
    state.preview = { status: out.status, messages: out.messages, path: d.location.pathname || "" };
    emit();
  }

  // Every load of the preview is checked again, so the merchant can click around the shop in it.
  preview.addEventListener("load", () => {
    if (!destroyed) inspectPreview();
  });

  /** Shows a page from the list (by key), or any page object the platform builds (link clicks). */
  async function show(keyOrPage) {
    const page =
      typeof keyOrPage === "string" ? state.pages.find((p) => p.key === keyOrPage) : keyOrPage;
    if (!page) return;
    state.current = page.key;
    currentPage = page;
    state.preview = { status: "loading", messages: [], path: "" };
    emit();
    previewToken += 1;
    sizeFrame(preview, state.width);
    try {
      await load(preview, page);
    } catch (err) {
      state.preview = {
        status: "error",
        messages: [],
        path: "",
        error: err && err.message ? err.message : "",
      };
      emit();
    }
  }

  async function setWidth(width) {
    if (!WIDTHS[width]) return;
    state.width = width;
    sizeFrame(preview, width);
    const token = previewToken;
    await settle(win, 50);
    if (token === previewToken) await inspectPreview();
  }

  /** Checks every page at both widths in the hidden frame. */
  async function sweep() {
    state.sweeping = true;
    for (const p of state.pages) p.status = "waiting";
    emit();
    for (const p of state.pages) {
      if (destroyed) return;
      p.status = "checking";
      emit();
      sizeFrame(sweeper, "desktop");
      const done = loaded(sweeper, loadTimeoutMs);
      try {
        await load(sweeper, p);
      } catch {
        p.status = "error";
        emit();
        continue;
      }
      // A page that never finished loading would leave the previous page in the frame.
      const d = (await done) ? frameDocument(sweeper) : null;
      if (!d) {
        p.status = "blocked";
        emit();
        continue;
      }
      await settle(win, settleMs);
      p.desktop = summarise(run(d, p));
      sizeFrame(sweeper, "phone");
      await settle(win, 150);
      p.phone = summarise(run(d, p));
      p.status = worst(p.desktop.status, p.phone.status);
      emit();
    }
    state.sweeping = false;
    emit();
  }

  function destroy() {
    destroyed = true;
    preview.remove();
    sweeper.remove();
  }

  sizeFrame(preview, state.width);
  return { show, setWidth, sweep, destroy, frame: preview, state };
}

const RANK = { pass: 0, warn: 1, fail: 2 };
function worst(a, b) {
  return RANK[a] >= RANK[b] ? a : b;
}

/** What the overview needs from a run: no DOM references, so it can go into state. */
function summarise(out) {
  const found = out.checks.find((c) => c.id === "found");
  return { status: out.status, areas: found?.areas || [], messages: out.messages };
}
