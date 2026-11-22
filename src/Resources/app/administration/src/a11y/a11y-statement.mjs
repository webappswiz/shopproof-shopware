// Copied from packages/widgets/src/a11y-statement.mjs by apps/shopware/scripts/build-storefront.mjs; do not edit by hand.
// Accessibility statement draft for the Shopproof hub (gap audit 22 Nov 2026, item 10): the same
// wording as Shopproof Accessibility's statement helper, without its scan results, in English,
// German, French, Italian and Dutch. The authority per country comes from the verified rules
// AT-AC-01, DE-AC-01, FR-AC-01, IT-AC-01 and NL-AC-01 (name and website only). The draft never
// claims compliance: it says what the shop works towards, what it did, the barriers it knows of,
// how to report one and which authority to contact. The shop reads and changes it before it goes
// on a page. Used by the Shopify app and the Shopware admin; the build writes the same texts to
// apps/wordpress/shopproof/assets/notes/accessibility-statement.json for the WooCommerce plugin.
// Technical help, not legal advice.

export const STATEMENT = {
  languages: {
    en: {
      title: "Accessibility statement",
      updated: "Last updated: {date}",
      h_shop: "Our shop",
      shop: "{shop} is an online shop at {url}.",
      shop_default:
        "You can browse our products, choose options such as size or colour, put them in the cart and order through the checkout.",
      h_target: "Our accessibility goal",
      target:
        "We want everyone to be able to shop with us, including people who use a screen reader, only a keyboard, zoom or other assistive technology. We work towards the Web Content Accessibility Guidelines (WCAG) 2.2 at level AA, which also covers the European standard EN 301 549.",
      h_measures: "What we have done",
      measures_intro: "Among other things, we have made these changes:",
      h_known: "Known barriers",
      known_intro: "We know about these barriers and are working on them:",
      known_unknown: "We have not found every barrier yet. If you meet one, please tell us.",
      h_contact: "Tell us about a barrier",
      contact:
        "If something in our shop does not work for you, or you need information in another form, email us at {email}. Tell us the page and what went wrong. We will answer as soon as we can.",
      email_missing: "[email address]",
      h_authority: "Market surveillance authority",
      authority:
        "If you are not happy with our answer, you can contact the market surveillance authority: {authority}, {authority_url}.",
      authority_missing:
        "If you are not happy with our answer, you can contact the market surveillance authority for accessibility in our country: [name and website of the authority].",
    },
    de: {
      title: "Erklärung zur Barrierefreiheit",
      updated: "Stand: {date}",
      h_shop: "Unser Shop",
      shop: "{shop} ist ein Online-Shop unter {url}.",
      shop_default:
        "Sie können unsere Produkte ansehen, Optionen wie Größe oder Farbe wählen, sie in den Warenkorb legen und über die Kasse bestellen.",
      h_target: "Unser Ziel",
      target:
        "Alle sollen bei uns einkaufen können, auch Menschen, die einen Screenreader, nur die Tastatur, eine Vergrößerung oder andere Hilfsmittel nutzen. Wir arbeiten auf die Web Content Accessibility Guidelines (WCAG) 2.2 in Stufe AA hin, die auch die europäische Norm EN 301 549 abdecken.",
      h_measures: "Was wir getan haben",
      measures_intro: "Unter anderem haben wir Folgendes umgesetzt:",
      h_known: "Bekannte Barrieren",
      known_intro: "Diese Barrieren kennen wir und arbeiten daran:",
      known_unknown:
        "Wir haben noch nicht jede Barriere gefunden. Wenn Sie auf eine stoßen, sagen Sie es uns bitte.",
      h_contact: "Barrieren melden",
      contact:
        "Wenn etwas in unserem Shop für Sie nicht funktioniert oder Sie Informationen in einer anderen Form brauchen, schreiben Sie uns an {email}. Nennen Sie uns die Seite und was nicht funktioniert hat. Wir antworten so schnell wie möglich.",
      email_missing: "[E-Mail-Adresse]",
      h_authority: "Marktüberwachungsbehörde",
      authority:
        "Wenn Sie mit unserer Antwort nicht zufrieden sind, können Sie sich an die Marktüberwachungsbehörde wenden: {authority}, {authority_url}.",
      authority_missing:
        "Wenn Sie mit unserer Antwort nicht zufrieden sind, können Sie sich an die für Barrierefreiheit zuständige Marktüberwachungsbehörde in unserem Land wenden: [Name und Website der Behörde].",
    },
    fr: {
      title: "Déclaration d'accessibilité",
      updated: "Dernière mise à jour : {date}",
      h_shop: "Notre boutique",
      shop: "{shop} est une boutique en ligne à l'adresse {url}.",
      shop_default:
        "Vous pouvez parcourir nos produits, choisir des options comme la taille ou la couleur, les mettre dans le panier et commander via la caisse.",
      h_target: "Notre objectif",
      target:
        "Nous voulons que chacun puisse acheter chez nous, y compris les personnes qui utilisent un lecteur d'écran, uniquement le clavier, un zoom ou d'autres aides techniques. Nous travaillons à respecter les règles WCAG 2.2 au niveau AA, qui couvrent aussi la norme européenne EN 301 549.",
      h_measures: "Ce que nous avons fait",
      measures_intro: "Nous avons notamment fait ces changements :",
      h_known: "Obstacles connus",
      known_intro: "Nous connaissons ces obstacles et nous y travaillons :",
      known_unknown:
        "Nous n'avons pas encore trouvé tous les obstacles. Si vous en rencontrez un, dites-le-nous.",
      h_contact: "Signaler un obstacle",
      contact:
        "Si quelque chose dans notre boutique ne fonctionne pas pour vous, ou si vous avez besoin d'une information sous une autre forme, écrivez-nous à {email}. Indiquez la page et ce qui n'a pas fonctionné. Nous répondrons dès que possible.",
      email_missing: "[adresse e-mail]",
      h_authority: "Autorité de surveillance du marché",
      authority:
        "Si notre réponse ne vous satisfait pas, vous pouvez contacter l'autorité de surveillance du marché : {authority}, {authority_url}.",
      authority_missing:
        "Si notre réponse ne vous satisfait pas, vous pouvez contacter l'autorité de surveillance du marché chargée de l'accessibilité dans notre pays : [nom et site de l'autorité].",
    },
    it: {
      title: "Dichiarazione di accessibilità",
      updated: "Ultimo aggiornamento: {date}",
      h_shop: "Il nostro negozio",
      shop: "{shop} è un negozio online all'indirizzo {url}.",
      shop_default:
        "Puoi sfogliare i nostri prodotti, scegliere opzioni come taglia o colore, metterli nel carrello e ordinare tramite la cassa.",
      h_target: "Il nostro obiettivo",
      target:
        "Vogliamo che tutti possano acquistare da noi, anche chi usa un lettore di schermo, solo la tastiera, l'ingrandimento o altre tecnologie assistive. Lavoriamo per rispettare le linee guida WCAG 2.2 al livello AA, che coprono anche la norma europea EN 301 549.",
      h_measures: "Cosa abbiamo fatto",
      measures_intro: "Tra l'altro abbiamo fatto queste modifiche:",
      h_known: "Barriere note",
      known_intro: "Conosciamo queste barriere e ci stiamo lavorando:",
      known_unknown:
        "Non abbiamo ancora trovato tutte le barriere. Se ne incontri una, faccelo sapere.",
      h_contact: "Segnalaci una barriera",
      contact:
        "Se qualcosa nel nostro negozio non funziona per te, o ti servono informazioni in un'altra forma, scrivici a {email}. Indica la pagina e cosa non ha funzionato. Risponderemo il prima possibile.",
      email_missing: "[indirizzo email]",
      h_authority: "Autorità di vigilanza del mercato",
      authority:
        "Se la nostra risposta non ti soddisfa, puoi rivolgerti all'autorità di vigilanza del mercato: {authority}, {authority_url}.",
      authority_missing:
        "Se la nostra risposta non ti soddisfa, puoi rivolgerti all'autorità di vigilanza del mercato competente per l'accessibilità nel nostro paese: [nome e sito dell'autorità].",
    },
    nl: {
      title: "Toegankelijkheidsverklaring",
      updated: "Laatst bijgewerkt: {date}",
      h_shop: "Onze winkel",
      shop: "{shop} is een webwinkel op {url}.",
      shop_default:
        "U kunt onze producten bekijken, opties zoals maat of kleur kiezen, ze in de winkelwagen leggen en via de kassa bestellen.",
      h_target: "Ons doel",
      target:
        "Iedereen moet bij ons kunnen winkelen, ook mensen die een schermlezer, alleen een toetsenbord, vergroting of andere hulpmiddelen gebruiken. Wij werken toe naar de Web Content Accessibility Guidelines (WCAG) 2.2 op niveau AA, die ook de Europese norm EN 301 549 dekken.",
      h_measures: "Wat we hebben gedaan",
      measures_intro: "We hebben onder meer deze aanpassingen gedaan:",
      h_known: "Bekende knelpunten",
      known_intro: "Deze knelpunten kennen we en we werken eraan:",
      known_unknown:
        "We hebben nog niet elk knelpunt gevonden. Loopt u ergens tegenaan, laat het ons dan weten.",
      h_contact: "Een knelpunt melden",
      contact:
        "Werkt er iets in onze winkel niet voor u, of heeft u informatie in een andere vorm nodig? Mail ons op {email}. Vertel ons de pagina en wat er misging. We antwoorden zo snel mogelijk.",
      email_missing: "[e-mailadres]",
      h_authority: "Markttoezichthouder",
      authority:
        "Bent u niet tevreden met ons antwoord, dan kunt u contact opnemen met de markttoezichthouder: {authority}, {authority_url}.",
      authority_missing:
        "Bent u niet tevreden met ons antwoord, dan kunt u contact opnemen met de markttoezichthouder voor toegankelijkheid in ons land: [naam en website van de toezichthouder].",
    },
  },
  countries: {
    AT: "de",
    DE: "de",
    FR: "fr",
    IT: "it",
    NL: "nl",
    EU: "en",
  },
  authorities: {
    AT: {
      name: "Sozialministeriumservice",
      url: "https://www.sozialministeriumservice.gv.at",
      rule: "AT-AC-01",
    },
    DE: {
      name: "Marktüberwachungsstelle der Länder für die Barrierefreiheit von Produkten und Dienstleistungen (MLBF)",
      url: "https://www.mlbf-barrierefrei.de",
      rule: "DE-AC-01",
    },
    FR: {
      name: "Direction générale de la concurrence, de la consommation et de la répression des fraudes (DGCCRF)",
      url: "https://www.economie.gouv.fr/dgccrf",
      rule: "FR-AC-01",
    },
    IT: {
      name: "Agenzia per l'Italia Digitale (AgID)",
      url: "https://www.agid.gov.it",
      rule: "IT-AC-01",
    },
    NL: {
      name: "Autoriteit Consument & Markt (ACM)",
      url: "https://www.acm.nl",
      rule: "NL-AC-01",
    },
  },
};

export const STATEMENT_LANGS = Object.keys(STATEMENT.languages);

/** Most lines a list in the draft takes, and characters per line. */
export const MAX_ITEMS = 12;
export const MAX_ITEM = 300;

const fill = (template, vars) =>
  String(template).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m));

/**
 * Lines typed into a box, one item per line, cleaned: no markup, trimmed, empty lines left out.
 * @param {unknown} value
 * @returns {string[]}
 */
export function statementLines(value) {
  const list = Array.isArray(value) ? value : String(value ?? "").split(/\r?\n/);
  return list
    .map((line) =>
      String(line ?? "")
        .replace(/<[^>]*>/g, "")
        .replace(/[\u0000-\u001f\u007f]/g, " ")
        .replace(/\s+/g, " ")
        .trim()
        .slice(0, MAX_ITEM),
    )
    .filter(Boolean)
    .slice(0, MAX_ITEMS);
}

/**
 * The language to start with: the one asked for, else the shop country's, else English.
 * @param {string | null | undefined} wanted
 * @param {string | null | undefined} country
 */
export function statementLanguage(wanted, country) {
  const lang = String(wanted || "").toLowerCase();
  if (STATEMENT_LANGS.includes(lang)) return lang;
  return STATEMENT.countries[String(country || "").toUpperCase()] || "en";
}

/**
 * The draft as blocks (headings, paragraphs, lists) and as plain text to paste into a page.
 * @param {{ lang?: string, country?: string, shop?: string, url?: string, email?: string,
 *   date?: string, about?: string, measures?: unknown, barriers?: unknown }} values
 * @returns {{ lang: string, title: string, authority: boolean, blocks: { type: "h" | "p" | "ul", text?: string, items?: string[] }[], text: string }}
 */
export function buildStatement(values = {}) {
  const lang = statementLanguage(values.lang, values.country);
  const t = STATEMENT.languages[lang];
  const clean = (v, max = 200) => statementLines([v])[0]?.slice(0, max) ?? "";
  const shop = clean(values.shop, 120);
  const url = clean(values.url, 200);
  const email = clean(values.email, 200);
  const date = clean(values.date, 40);
  const about = clean(values.about, MAX_ITEM);
  const measures = statementLines(values.measures);
  const barriers = statementLines(values.barriers);
  const authority = STATEMENT.authorities[String(values.country || "").toUpperCase()] || null;
  /** @type {{ type: "h" | "p" | "ul", text?: string, items?: string[] }[]} */
  const blocks = [];
  const h = (text) => blocks.push({ type: "h", text });
  const p = (text) => blocks.push({ type: "p", text });
  if (date) p(fill(t.updated, { date }));
  h(t.h_shop);
  p(
    `${shop || url ? fill(t.shop, { shop: shop || url, url: url || shop }) + " " : ""}${about || t.shop_default}`,
  );
  h(t.h_target);
  p(t.target);
  if (measures.length) {
    h(t.h_measures);
    p(t.measures_intro);
    blocks.push({ type: "ul", items: measures });
  }
  h(t.h_known);
  if (barriers.length) {
    p(t.known_intro);
    blocks.push({ type: "ul", items: barriers });
  }
  p(t.known_unknown);
  h(t.h_contact);
  p(fill(t.contact, { email: email || t.email_missing }));
  h(t.h_authority);
  p(
    authority
      ? fill(t.authority, { authority: authority.name, authority_url: authority.url })
      : t.authority_missing,
  );
  const text = [
    t.title,
    ...blocks.map((b) =>
      b.type === "ul" ? (b.items ?? []).map((i) => `- ${i}`).join("\n") : b.text,
    ),
  ].join("\n\n");
  return { lang, title: t.title, authority: Boolean(authority), blocks, text };
}
