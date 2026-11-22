// Copied from packages/widgets/src/placement.mjs by apps/shopware/scripts/build-storefront.mjs; do not edit by hand.
// Placement check (decision 0024): looks at a rendered shop page and reports where the
// withdrawal link sits, whether a shopper can see and reach it, and whether anything floats over
// the theme's content (rival withdrawal apps use corner buttons that cover prices).
//
// Runs in the merchant's admin, never on the storefront: the admin page loads the shop's page in
// a frame and passes its document here. Real layout comes from the browser, so the answers match
// what a shopper sees at that width. `inspectPlacement` only reads; `markPlacement` adds outlines
// inside the preview frame. Text for the merchant lives in each platform's admin; this module
// returns codes and numbers only.
// It imports nothing, so the Shopify admin can bundle it as it is; callers pass the reviewed
// national labels (from packages/rules/data/strings) in.

/** Words other apps use on their withdrawal buttons, as a fallback to the reviewed labels. */
const WITHDRAW_WORDS =
  /\b(widerruf|widerrufen|withdraw|withdrawal|renoncer|rétractation|retractation|recedere|recesso|herroep|herroepen|ontbinden)\b/i;

/** Contrast WCAG 2.2 AA asks for normal text (1.4.3). */
export const MIN_CONTRAST = 4.5;

const MAX_ELEMENTS = 4000;

export function norm(text) {
  return String(text || "")
    .replace(/\s+/g, " ")
    .trim()
    .toLowerCase();
}

/** True for an element that is, or sits inside, one of ours. */
function isOurs(el) {
  return !!el.closest(
    ".shopproof-withdraw-link, shopproof-withdraw-link, shopproof-withdrawal-form, .shopproof-withdrawal-form, .shopproof-withdrawal",
  );
}

/** Boxes only the signed-in merchant sees: the WordPress toolbar, WooCommerce's coming soon bar. */
const ADMIN_ONLY = "#wpadminbar, #coming-soon-footer-banner";

/** Where on the page a link sits: account, footer, header (menus), main or other. */
export function areaOf(el) {
  if (
    el.closest(
      ".shopproof-account-link, .woocommerce-MyAccount-content, .woocommerce-order-details, .woocommerce-order",
    )
  )
    return "account";
  if (el.closest(".shopproof-footer-link")) return "footer";
  // The footer wins over menus inside it (footer menus are a nav element in most themes).
  const marks = [];
  for (let n = el.parentElement; n; n = n.parentElement) {
    const tag = n.tagName.toLowerCase();
    const role = (n.getAttribute("role") || "").toLowerCase();
    const marker =
      `${n.id || ""} ${typeof n.className === "string" ? n.className : ""}`.toLowerCase();
    if (tag === "footer" || role === "contentinfo" || /(^|[\s_-])footer/.test(marker))
      return "footer";
    if (
      tag === "header" ||
      tag === "nav" ||
      role === "banner" ||
      role === "navigation" ||
      /(^|[\s_-])(header|menu|navigation)([\s_-]|$)/.test(marker)
    )
      marks.push("header");
    else if (tag === "main" || role === "main") marks.push("main");
  }
  return marks[0] || "other";
}

function matchesHref(a, paths) {
  const href = a.getAttribute("href");
  if (!href || !paths.length) return false;
  try {
    const url = new URL(href, a.ownerDocument.baseURI);
    const path = url.pathname.replace(/\/+$/, "") || "/";
    return paths.some((p) => path === p || path.endsWith(p));
  } catch {
    return false;
  }
}

// Links to accessibility information (an accessibility statement), by their words or address,
// the same words the shop scanner uses (packages/scanner/src/checks.mjs, WORDS.accessibility).
const A11Y_WORDS = /barrierefrei|accessibilit|accessibilité|accessibilità|toegankelijk/;
const A11Y_PATH = /barrierefrei|accessib|toegankelijk/;

function isA11yLink(a) {
  if (A11Y_WORDS.test(nameOf(a))) return true;
  try {
    return A11Y_PATH.test(
      new URL(a.getAttribute("href"), a.ownerDocument.baseURI).pathname.toLowerCase(),
    );
  } catch {
    return false;
  }
}

/** Visible text of a link or button, with its accessible name as a fallback. */
function nameOf(el) {
  return norm(el.textContent || el.getAttribute("aria-label") || el.getAttribute("title") || "");
}

function looksLikeWithdrawal(text, labels) {
  return labels.includes(text) || (text.length <= 60 && WITHDRAW_WORDS.test(text));
}

/** Hidden by CSS on the element or an ancestor (display, visibility, opacity, hidden). */
function hiddenByStyle(el, win) {
  for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
    if (n.hidden) return true;
    const s = win.getComputedStyle(n);
    if (s.display === "none" || s.visibility === "hidden" || s.visibility === "collapse")
      return true;
    if (Number.parseFloat(s.opacity) === 0) return true;
  }
  return false;
}

function rectOf(el) {
  const r = el.getBoundingClientRect();
  return { top: r.top, left: r.left, width: r.width, height: r.height };
}

/** The element that floats: the nearest fixed or sticky ancestor, else the element itself. */
function floatingRoot(el, win) {
  for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
    const p = win.getComputedStyle(n).position;
    if (p === "fixed" || p === "sticky") return n;
  }
  return null;
}

function isFixed(el, win) {
  const root = floatingRoot(el, win);
  return !!root && win.getComputedStyle(root).position === "fixed";
}

/** A short description of an element for the merchant: tag, id or first class, and its text. */
export function describe(el) {
  if (!el) return "";
  const tag = el.tagName.toLowerCase();
  const id = el.id ? `#${el.id}` : "";
  const cls =
    !id && typeof el.className === "string" && el.className.trim()
      ? `.${el.className.trim().split(/\s+/)[0]}`
      : "";
  const text = String(el.textContent || "")
    .replace(/\s+/g, " ")
    .trim()
    .slice(0, 40);
  return `${tag}${id}${cls}${text ? ` "${text}"` : ""}`;
}

/** Relative luminance of an sRGB colour (WCAG 2.2). */
function luminance([r, g, b]) {
  const ch = (v) => {
    const c = v / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b);
}

/** [r, g, b, a] from a computed colour (rgb() or rgba()), or null. */
export function parseColor(value) {
  const m = /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)/i.exec(
    String(value || ""),
  );
  if (!m) return null;
  let a = m[4] === undefined ? 1 : Number.parseFloat(m[4]);
  if (m[4] && m[4].endsWith("%")) a /= 100;
  return [Number(m[1]), Number(m[2]), Number(m[3]), a];
}

/** Contrast ratio of two colours, rounded to one decimal. */
export function contrastRatio(fg, bg) {
  const a = luminance(fg);
  const b = luminance(bg);
  const ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
  return Math.round(ratio * 10) / 10;
}

/**
 * Contrast of a link's text against what is behind it, or null when it cannot be measured
 * (background images or gradients behind the text).
 */
function linkContrast(el, win) {
  const fg = parseColor(win.getComputedStyle(el).color);
  if (!fg) return null;
  let bg = null;
  for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
    const s = win.getComputedStyle(n);
    if (s.backgroundImage && s.backgroundImage !== "none") return null;
    const c = parseColor(s.backgroundColor);
    if (c && c[3] > 0) {
      if (c[3] < 1) return null;
      bg = c;
      break;
    }
  }
  bg = bg || [255, 255, 255, 1];
  // Blend a semi-transparent text colour over the background.
  const mixed = fg.slice(0, 3).map((v, i) => v * fg[3] + bg[i] * (1 - fg[3]));
  return contrastRatio(mixed, bg);
}

/** What sits on top at the centre of a box, if it is not the element itself or inside it. */
function coveredBy(el, rect, doc) {
  if (typeof doc.elementFromPoint !== "function") return null;
  const x = rect.left + rect.width / 2;
  const y = rect.top + rect.height / 2;
  const view = doc.defaultView;
  if (x < 0 || y < 0 || x > view.innerWidth || y > view.innerHeight) return null;
  const top = doc.elementFromPoint(x, y);
  if (!top || top === el || el.contains(top) || top.contains(el) || top.closest(ADMIN_ONLY))
    return null;
  return top;
}

/**
 * Inspects one rendered page.
 * @param {Document} doc the page (same origin, laid out)
 * @param {{ paths?: string[], labels?: string[], scroll?: boolean }} [options] paths: the withdrawal
 *   page's path(s), so links to it count even without our class; labels: the reviewed national
 *   withdrawal button labels; scroll: scroll each link into view before testing whether something
 *   covers it (default true)
 * @returns {{ links: object[], floating: object[], a11y: { area: string, visible: boolean }[], width: number }}
 */
export function inspectPlacement(doc, { paths = [], labels = [], scroll = true } = {}) {
  const win = doc.defaultView;
  const known = [...new Set(labels.map(norm).filter(Boolean))];
  const links = [];
  const candidates = [
    ...doc.querySelectorAll("a[href], button, [role='button'], [role='link']"),
  ].slice(0, MAX_ELEMENTS);
  for (const el of candidates) {
    const ours = el.matches(".shopproof-withdraw-link") || (isOurs(el) && el.matches("a"));
    const text = nameOf(el);
    const toPage = el.matches("a[href]") && matchesHref(el, paths);
    if (!ours && !toPage && !looksLikeWithdrawal(text, known)) continue;
    if (!ours && isOurs(el)) continue; // the buttons of our own form
    if (el.closest(ADMIN_ONLY)) continue;
    const hidden = hiddenByStyle(el, win);
    let rect = rectOf(el);
    const visible = !hidden && rect.width > 0 && rect.height > 0;
    let cover = null;
    if (visible) {
      if (scroll && typeof el.scrollIntoView === "function") {
        el.scrollIntoView({ block: "center", inline: "nearest" });
        rect = rectOf(el);
      }
      const top = coveredBy(el, rect, doc);
      cover = top ? floatingRoot(top, win) || top : null;
    }
    links.push({
      element: el,
      ours,
      toPage,
      text,
      label: known.includes(text),
      area: areaOf(el),
      visible,
      covered: !!cover,
      coveredBy: cover ? describe(cover) : "",
      contrast: visible ? linkContrast(el, win) : null,
      floating: isFixed(el, win),
    });
  }

  // Floating boxes: fixed position, visible, not a full-width bar (sticky headers and cookie
  // bars span the page; corner buttons and chat bubbles do not).
  const floating = [];
  const viewW = win.innerWidth || doc.documentElement.clientWidth || 0;
  const viewH = win.innerHeight || doc.documentElement.clientHeight || 0;
  let count = 0;
  for (const el of doc.body ? doc.body.querySelectorAll("*") : []) {
    if ((count += 1) > MAX_ELEMENTS) break;
    const s = win.getComputedStyle(el);
    if (s.position !== "fixed") continue;
    if (el.closest(ADMIN_ONLY)) continue;
    if (el.parentElement && floatingRoot(el.parentElement, win)) continue; // inside another floating box
    if (hiddenByStyle(el, win)) continue;
    const r = rectOf(el);
    if (r.width < 8 || r.height < 8) continue;
    if (r.width >= viewW * 0.9 || r.height >= viewH * 0.9) continue;
    const text = norm(el.textContent).slice(0, 80);
    floating.push({
      element: el,
      text,
      withdrawal:
        looksLikeWithdrawal(text, known) ||
        [...el.querySelectorAll("a, button")].some((a) => looksLikeWithdrawal(nameOf(a), known)),
      ours: isOurs(el),
      coversContent: coversContent(el, r, doc),
      rect: r,
      description: describe(el),
    });
  }
  // Links to accessibility information, wherever they are. Only where and whether they are seen.
  const a11y = [];
  for (const a of [...doc.querySelectorAll("a[href]")].slice(0, MAX_ELEMENTS)) {
    if (a.closest(ADMIN_ONLY) || !isA11yLink(a)) continue;
    const r = rectOf(a);
    a11y.push({ area: areaOf(a), visible: !hiddenByStyle(a, win) && r.width > 0 && r.height > 0 });
  }
  return { links, floating, a11y, width: viewW };
}

/** True if theme content with text or an image lies under the floating box. */
function coversContent(el, r, doc) {
  if (typeof doc.elementsFromPoint !== "function") return false;
  const points = [
    [r.left + r.width / 2, r.top + r.height / 2],
    [r.left + 4, r.top + 4],
    [r.left + r.width - 4, r.top + r.height - 4],
  ];
  for (const [x, y] of points) {
    const under = doc
      .elementsFromPoint(x, y)
      .filter((n) => n !== el && !el.contains(n) && !n.contains(el));
    if (under.some((n) => n !== doc.body && n !== doc.documentElement && contentLike(n)))
      return true;
  }
  return false;
}

function contentLike(n) {
  if (/^(img|picture|video|svg|input|select|textarea|button|a|table|td|th)$/i.test(n.tagName))
    return true;
  for (const c of n.childNodes) if (c.nodeType === 3 && c.textContent.trim()) return true;
  return false;
}

/**
 * Turns an inspection into checks with codes the admin translates:
 * found, label, visible, clear, floating, contrast, others and a11y. Status is pass, warn, fail or
 * skip; a11y is pass or info, a note that never changes the page result.
 * @param {{ links: object[], floating: object[] }} result
 * @param {{ expectLink?: boolean }} [options] expectLink: false on pages where the link is optional
 */
export function placementChecks(result, { expectLink = true } = {}) {
  const ours = result.links.filter((l) => l.ours || l.toPage);
  const others = result.links.filter((l) => !l.ours && !l.toPage);
  const shown = ours.filter((l) => l.visible);
  const checks = [];
  checks.push({
    id: "found",
    status: ours.length ? "pass" : expectLink ? "fail" : "skip",
    areas: [...new Set(ours.map((l) => l.area))],
  });
  if (ours.length) {
    checks.push({
      id: "visible",
      status: shown.length ? "pass" : "warn",
      areas: [...new Set(ours.filter((l) => !l.visible).map((l) => l.area))],
    });
    const covered = shown.filter((l) => l.covered);
    checks.push({
      id: "clear",
      status: !shown.length
        ? "skip"
        : covered.length === shown.length
          ? "fail"
          : covered.length
            ? "warn"
            : "pass",
      by: covered.map((l) => l.coveredBy),
    });
    const measured = shown.filter((l) => l.contrast !== null);
    const low = measured.filter((l) => l.contrast < MIN_CONTRAST);
    checks.push({
      id: "contrast",
      status: !measured.length ? "skip" : low.length ? "warn" : "pass",
      value: measured.length ? Math.min(...measured.map((l) => l.contrast)) : null,
    });
  }
  const floatingOverContent = result.floating.filter((f) => f.coversContent && !f.ours);
  const rivalFloating = result.floating.filter((f) => f.withdrawal && !f.ours);
  checks.push({
    id: "floating",
    status: floatingOverContent.length ? "warn" : "pass",
    items: floatingOverContent.map((f) => f.description),
  });
  checks.push({
    id: "others",
    status: rivalFloating.length || others.some((l) => l.visible) ? "warn" : "pass",
    items: [
      ...rivalFloating.map((f) => f.description),
      ...others.filter((l) => l.visible && !l.floating).map((l) => describe(l.element)),
    ],
  });
  // Many very small businesses are exempt from the accessibility statement, so a missing link is a
  // note, never a problem.
  const a11y = result.a11y || [];
  checks.push({
    id: "a11y",
    status: a11y.length ? "pass" : "info",
    areas: [...new Set(a11y.map((l) => l.area))],
  });
  return checks;
}

const MARK_STYLE_ID = "shopproof-placement-marks";

/**
 * Outlines our links (solid blue) and other apps' withdrawal buttons and floating boxes over
 * content (dashed red) inside the preview frame.
 * Adds one style element and data attributes; the shop's page itself is never changed.
 */
export function markPlacement(doc, result) {
  clearMarks(doc);
  const style = doc.createElement("style");
  style.id = MARK_STYLE_ID;
  style.textContent =
    "[data-shopproof-mark=link]{outline:3px solid #0b6bcb!important;outline-offset:3px!important;box-shadow:0 0 0 6px rgba(11,107,203,.25)!important}" +
    "[data-shopproof-mark=float],[data-shopproof-mark=other]{outline:3px dashed #b3261e!important;outline-offset:2px!important}";
  (doc.head || doc.documentElement).appendChild(style);
  for (const l of result.links)
    if (l.ours || l.toPage) l.element.setAttribute("data-shopproof-mark", "link");
  for (const l of result.links)
    if (!l.ours && !l.toPage && l.visible) l.element.setAttribute("data-shopproof-mark", "other");
  for (const f of result.floating)
    if (f.coversContent && !f.ours) f.element.setAttribute("data-shopproof-mark", "float");
}

export function clearMarks(doc) {
  doc.getElementById(MARK_STYLE_ID)?.remove();
  for (const el of doc.querySelectorAll("[data-shopproof-mark]"))
    el.removeAttribute("data-shopproof-mark");
}

/** English text for the checks. The WooCommerce plugin passes a translated table instead. */
export const PLACEMENT_TEXT = {
  "area.footer": "in the footer",
  "area.header": "in the header or menu",
  "area.main": "in the page content",
  "area.account": "in the account area",
  "area.other": "on the page",
  "found.pass": "Withdrawal link found {areas}.",
  "found.fail":
    "No withdrawal link on this page. Shoppers must be able to find it on every page, for example in the footer.",
  "found.skip": "No withdrawal link on this page, which is fine here.",
  "visible.pass": "Shoppers see it without opening anything.",
  "visible.warn":
    "At this width the link is hidden {areas}, for example inside a closed menu. Make sure it is also visible somewhere, such as the footer.",
  "clear.pass": "Nothing covers the link.",
  "clear.warn": "Something covers one of the links: {items}.",
  "clear.fail": "Something covers the link: {items}. Shoppers cannot reach it.",
  "contrast.pass": "Text contrast {value}:1 (WCAG 2.2 AA asks for 4.5:1).",
  "contrast.warn":
    "Text contrast is only {value}:1, and WCAG 2.2 AA asks for 4.5:1. Choose a darker or lighter link colour in your theme settings.",
  "contrast.skip": "Contrast not measured: an image or gradient sits behind the text.",
  "floating.pass": "Nothing floats over your theme's content.",
  "floating.warn":
    "Floating elements cover your theme's content: {items}. Shopproof never does this; they come from your theme or another app.",
  "others.pass": "No second withdrawal button found.",
  "others.warn":
    "Another withdrawal button was found: {items}. Shoppers should see only one; turn the other app's button off.",
  "a11y.pass": "Link to accessibility information found {areas}.",
  "a11y.info":
    "No link to accessibility information on this page. Most shops need an accessibility statement since 28 June 2025, usually linked in the footer; very small businesses are exempt.",
};

function fill(template, vars) {
  return String(template || "").replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m));
}

/** Joins words as "a, b and c", with the connector from the text table. */
function list(words, and) {
  if (words.length < 2) return words.join("");
  return `${words.slice(0, -1).join(", ")} ${and} ${words[words.length - 1]}`;
}

/**
 * One sentence per check, ready to show.
 * @returns {{ id: string, status: string, message: string }[]}
 */
export function placementMessages(checks, text = PLACEMENT_TEXT) {
  const and = text.and || "and";
  return checks.map((c) => {
    const areas = list(
      (c.areas || []).map((a) => text[`area.${a}`] || a),
      and,
    );
    const items = (c.items || c.by || []).slice(0, 3).join("; ");
    const value = c.value === null || c.value === undefined ? "" : String(c.value);
    return {
      id: c.id,
      status: c.status,
      message: fill(text[`${c.id}.${c.status}`], { areas, items, value }),
    };
  });
}
