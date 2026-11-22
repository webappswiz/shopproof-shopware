<?php declare(strict_types=1);

namespace Shopproof\Compliance\Installer;

use Doctrine\DBAL\Connection;
use Shopproof\Compliance\Checkout\ServiceWithdrawal;
use Shopproof\Compliance\Notes\CheckoutBoxes;
use Shopproof\Compliance\Notes\Deposit;
use Shopproof\Compliance\Notes\FoodInformation;
use Shopproof\Compliance\Notes\NotesCartProcessor;
use Shopproof\Compliance\Notes\PriceNotes;
use Shopproof\Compliance\Storefront\DurabilityLabel;
use Shopproof\Compliance\Storefront\EnergyLabel;
use Shopproof\Compliance\Storefront\HazardLabel;
use Shopproof\Compliance\Storefront\Registrations;
use Shopproof\Compliance\Storefront\SafetyData;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\CustomField\CustomFieldTypes;

/**
 * Product safety custom fields: one set on products (overrides, identifiers, warnings) and one on
 * manufacturers (address, contact, EU responsible person), with the other Shopproof sets (labels,
 * notes, the withdrawal type of a product, and the order's checkout and service fields). Created on install and update, kept
 * in step with this class, removed on uninstall unless the merchant keeps the data.
 */
final class CustomFieldInstaller
{
    public const PRODUCT_SET = 'shopproof_safety';
    public const MANUFACTURER_SET = 'shopproof_safety_manufacturer';
    public const DURABILITY_SET = 'shopproof_durability';
    public const DURABILITY_MAKER_SET = 'shopproof_durability_manufacturer';
    public const HAZARD_SET = 'shopproof_hazard';
    public const TAKEBACK_SET = 'shopproof_takeback';
    public const FOOD_SET = 'shopproof_food';
    public const NOTES_SET = 'shopproof_notes';
    public const ORDER_SET = CheckoutBoxes::ORDER_SET;
    public const WITHDRAWAL_SET = 'shopproof_withdrawal';
    public const ENERGY_SET = 'shopproof_energy';
    public const SETS = [self::PRODUCT_SET, self::MANUFACTURER_SET, self::DURABILITY_SET, self::DURABILITY_MAKER_SET, self::HAZARD_SET, self::TAKEBACK_SET, self::FOOD_SET, self::NOTES_SET, self::ORDER_SET, self::WITHDRAWAL_SET, self::ENERGY_SET];

    /** The order fields for services, without the prefix: the consent record, then what the merchant enters. */
    public const SERVICE_ORDER_FIELDS = ['service_consent', 'service_start', 'service_end', 'service_share', 'service_done'];

    /** The price, deposit and cart description fields, by their full names. */
    public const NOTES_FIELDS = [
        NotesCartProcessor::DESCRIPTION_FIELD,
        PriceNotes::PREFIX . 'margin_scheme',
        PriceNotes::PREFIX . 'defects',
        Deposit::PREFIX . 'types',
        Deposit::PREFIX . 'units',
    ];

    /** Help texts of the energy label fields. */
    private const ENERGY_HELP = [
        'energy_class' => ['For goods with an EU energy label (appliances, lamps, TVs, heaters). Shoppers see the coloured class arrow and the "Product information sheet" link right below the price, on the product page and in listings. Variants can set their own. Technical help, not legal advice.', 'Für Waren mit EU-Energielabel (Geräte, Lampen, Fernseher, Heizgeräte). Kunden sehen den farbigen Klassenpfeil und den Link "Produktdatenblatt" direkt unter dem Preis, auf der Produktseite und in Listen. Varianten können eigene Werte haben. Technische Hilfe, keine Rechtsberatung.'],
        'energy_range' => ['Empty means A to G, the scale of the new labels. Older labels (ovens, range hoods, heaters) use another range, for example A+++ to D.', 'Leer bedeutet A bis G, die Skala der neuen Labels. Ältere Labels (Backöfen, Dunstabzugshauben, Heizgeräte) haben eine andere Skala, zum Beispiel A+++ bis D.'],
        'energy_eprel' => ['The number in the EU product database EPREL, behind the QR code on the label, or ask your supplier. The arrow and the "Product information sheet" link then open the official EPREL page.', 'Die Nummer in der EU-Produktdatenbank EPREL, hinter dem QR-Code auf dem Label, oder beim Lieferanten erfragen. Pfeil und Link "Produktdatenblatt" öffnen dann die offizielle EPREL-Seite.'],
        'energy_label' => ['Optional with an EPREL number: the label file from your supplier, opened when the shopper selects the arrow.', 'Optional mit EPREL-Nummer: die Label-Datei Ihres Lieferanten, die sich beim Klick auf den Pfeil öffnet.'],
        'energy_sheet' => ['Only without an EPREL number: a https link to the product information sheet.', 'Nur ohne EPREL-Nummer: ein https-Link zum Produktdatenblatt.'],
        'energy_fr_type' => ['For sales to France: the repairability index (laptops, dishwashers, vacuum cleaners, lawn mowers, pressure washers) or the durability index (TVs, washing machines).', 'Für Verkäufe nach Frankreich: der Reparierbarkeitsindex (Laptops, Geschirrspüler, Staubsauger, Rasenmäher, Hochdruckreiniger) oder der Haltbarkeitsindex (Fernseher, Waschmaschinen).'],
        'energy_fr_score' => ['From 0 to 10 with one decimal, as the producer gives it.', 'Von 0 bis 10 mit einer Nachkommastelle, wie vom Hersteller angegeben.'],
        'energy_fr_logo' => ['The official pictogram with the score, from the producer. Shown next to the price.', 'Das offizielle Piktogramm mit der Note, vom Hersteller. Erscheint neben dem Preis.'],
        'energy_fr_detail' => ['A https link to the detail of the score: shoppers must reach it in one click.', 'Ein https-Link zum Detail der Note: Kunden müssen es mit einem Klick erreichen.'],
    ];

    private const LABELS = [
        'energy_class' => ['Energy class', 'Energieeffizienzklasse'],
        'energy_range' => ['Class range', 'Klassenspektrum'],
        'energy_eprel' => ['EPREL registration number', 'EPREL-Registriernummer'],
        'energy_label' => ['Label file', 'Label-Datei'],
        'energy_sheet' => ['Product information sheet link', 'Link zum Produktdatenblatt'],
        'energy_fr_type' => ['French index', 'Französischer Index'],
        'energy_fr_score' => ['French index score', 'Note des französischen Index'],
        'energy_fr_logo' => ['French index pictogram', 'Piktogramm des französischen Index'],
        'energy_fr_detail' => ['French index detail link', 'Link zum Detail des französischen Index'],
        'manufacturer_name' => ['Manufacturer name', 'Name des Herstellers'],
        'manufacturer_address' => ['Manufacturer postal address', 'Postanschrift des Herstellers'],
        'manufacturer_email' => ['Manufacturer email', 'E-Mail des Herstellers'],
        'manufacturer_url' => ['Manufacturer website or contact page', 'Website oder Kontaktseite des Herstellers'],
        'responsible_name' => ['EU responsible person name (if the manufacturer is outside the EU)', 'Name der verantwortlichen Person in der EU (wenn der Hersteller außerhalb der EU sitzt)'],
        'responsible_address' => ['EU responsible person postal address', 'Postanschrift der verantwortlichen Person in der EU'],
        'responsible_email' => ['EU responsible person email', 'E-Mail der verantwortlichen Person in der EU'],
        'responsible_url' => ['EU responsible person website', 'Website der verantwortlichen Person in der EU'],
        'identifiers' => ['Product identifiers (one per line, for example "Model: X1" or "Batch: 2026-04")', 'Produktkennzeichnung (eine pro Zeile, zum Beispiel "Modell: X1" oder "Charge: 2026-04")'],
        'warnings' => ['Warnings and safety information (in the buyer\'s language)', 'Warnhinweise und Sicherheitsinformationen (in der Sprache der Käufer)'],
        'years' => ['Durability guarantee in years (3 to 99; empty: the manufacturer\'s guarantee, if it has one)', 'Haltbarkeitsgarantie in Jahren (3 bis 99; leer: die Garantie des Herstellers, falls angegeben)'],
        'free' => ['Free of charge: the shopper gets this guarantee at no extra cost', 'Kostenlos: die Kunden bekommen diese Garantie ohne Aufpreis'],
        'whole' => ['Covers the whole product, not only a part of it', 'Gilt für das ganze Produkt, nicht nur für einen Teil'],
        'source' => ['Source of the guarantee (link to the producer\'s terms, for your records)', 'Quelle der Garantie (Link zu den Bedingungen des Herstellers, für Ihre Unterlagen)'],
        'checked' => ['Guarantee terms last checked on', 'Garantiebedingungen zuletzt geprüft am'],
        'maker_years' => ['Durability guarantee in years (3 to 99; products of this manufacturer use it unless they set their own)', 'Haltbarkeitsgarantie in Jahren (3 bis 99; Produkte dieses Herstellers nutzen sie, wenn sie keine eigene haben)'],
        'maker_free' => ['Free of charge: the shopper gets this guarantee at no extra cost', 'Kostenlos: die Kunden bekommen diese Garantie ohne Aufpreis'],
        'maker_whole' => ['Covers the whole product, not only a part of it', 'Gilt für das ganze Produkt, nicht nur für einen Teil'],
        'maker_source' => ['Source of the guarantee (link to the producer\'s terms, for your records)', 'Quelle der Garantie (Link zu den Bedingungen des Herstellers, für Ihre Unterlagen)'],
        'maker_checked' => ['Guarantee terms last checked on', 'Garantiebedingungen zuletzt geprüft am'],
        'brand' => ['Brand on the label (empty: the manufacturer\'s name)', 'Marke auf dem Label (leer: Name des Herstellers)'],
        'model' => ['Model identifier on the label (empty: the manufacturer number)', 'Modellkennung auf dem Label (leer: Herstellernummer)'],
        'display' => ['How to show the label', 'Darstellung des Labels'],
        'hazard_pictograms' => ['Hazard pictograms (copy them from the label or safety data sheet)', 'Gefahrenpiktogramme (vom Etikett oder Sicherheitsdatenblatt übernehmen)'],
        'hazard_signal' => ['Signal word', 'Signalwort'],
        'hazard_statements' => ['Hazard statements, one code per line (H225, EUH066); own words after a colon', 'Gefahrenhinweise, ein Code pro Zeile (H225, EUH066); eigene Worte nach einem Doppelpunkt'],
        'precautionary_statements' => ['Precautionary statements, one code per line (P210, P305+P351+P338); own words after a colon', 'Sicherheitshinweise, ein Code pro Zeile (P210, P305+P351+P338); eigene Worte nach einem Doppelpunkt'],
        'weee' => ['Electrical equipment: show how to return old devices on this product page', 'Elektrogerät: auf dieser Produktseite zeigen, wie Altgeräte zurückgegeben werden'],
        'digital_consent' => ['Digital content consent at checkout (what the shopper ticked, and when)', 'Zustimmung zu digitalen Inhalten an der Kasse (was die Kunden angekreuzt haben, und wann)'],
        'batteries' => ['Contains batteries: show how to return old batteries on this product page', 'Enthält Batterien: auf dieser Produktseite zeigen, wie Altbatterien zurückgegeben werden'],
        'food_name' => ['Name of the food', 'Bezeichnung des Lebensmittels'],
        'food_ingredients' => ['Ingredients (mark allergens with stars, for example: sugar, *milk* powder; they show in bold)', 'Zutaten (Allergene mit Sternchen markieren, zum Beispiel: Zucker, *Milch*pulver; sie erscheinen fett)'],
        'food_allergens' => ['Allergens it contains', 'Enthaltene Allergene'],
        'food_traces' => ['May contain', 'Kann Spuren enthalten von'],
        'food_net' => ['Net quantity', 'Nettofüllmenge'],
        'food_drained' => ['Drained net weight', 'Abtropfgewicht'],
        'food_storage' => ['Storage and use', 'Aufbewahrung und Verwendung'],
        'food_origin' => ['Country of origin', 'Ursprungsland'],
        'food_alcohol' => ['Alcohol (% vol, only for drinks with more than 1.2 %)', 'Alkohol (% vol, nur für Getränke mit mehr als 1,2 %)'],
        'food_business' => ['Food business name and address (empty: the one in the Shopproof settings)', 'Name und Anschrift des Lebensmittelunternehmers (leer: der aus den Shopproof-Einstellungen)'],
        'food_nutriscore' => ['Nutri-Score', 'Nutri-Score'],
        'food_basis' => ['Nutrition values per', 'Nährwerte pro'],
        'food_energy_kj' => ['Energy (kJ)', 'Energie (kJ)'],
        'food_energy_kcal' => ['Energy (kcal; empty: worked out from kJ)', 'Energie (kcal; leer: aus kJ berechnet)'],
        'food_fat' => ['Fat (g)', 'Fett (g)'],
        'food_saturates' => ['of which saturates (g)', 'davon gesättigte Fettsäuren (g)'],
        'food_carbs' => ['Carbohydrate (g)', 'Kohlenhydrate (g)'],
        'food_sugars' => ['of which sugars (g)', 'davon Zucker (g)'],
        'food_fibre' => ['Fibre (g)', 'Ballaststoffe (g)'],
        'food_protein' => ['Protein (g)', 'Eiweiß (g)'],
        'food_salt' => ['Salt (g)', 'Salz (g)'],
        NotesCartProcessor::DESCRIPTION_FIELD => ['Description in the cart (one line under the name in the cart, at checkout and in the order, for example: Wool socks, size 42, dark blue)', 'Beschreibung im Warenkorb (eine Zeile unter dem Namen im Warenkorb, an der Kasse und in der Bestellung, zum Beispiel: Wollsocken, Größe 42, dunkelblau)'],
        PriceNotes::PREFIX . 'margin_scheme' => ['Margin scheme (second-hand goods): the tax line says VAT is included but not shown separately', 'Differenzbesteuerung (Gebrauchtwaren): die Steuerzeile sagt, dass die MwSt. enthalten, aber nicht ausgewiesen ist'],
        PriceNotes::PREFIX . 'defects' => ['Known defects (used or B-stock goods; shown below the price)', 'Bekannte Mängel (gebrauchte oder B-Ware; erscheinen unter dem Preis)'],
        Deposit::PREFIX . 'types' => ['Deposit types (codes from the Shopproof settings, comma separated, for example: de-single, at-single, de-crate)', 'Pfandarten (Codes aus den Shopproof-Einstellungen, mit Komma getrennt, zum Beispiel: de-einweg, at-einweg, de-kasten)'],
        Deposit::PREFIX . 'units' => ['Bottles or cans with a deposit (for example 6 for a six-pack; empty means 1)', 'Flaschen oder Dosen mit Pfand (zum Beispiel 6 für ein Sechserpack; leer heißt 1)'],
        'checks_text' => ['Ticked at checkout', 'An der Kasse angehakt'],
        'withdrawal_kind' => ['Right of withdrawal', 'Widerrufsrecht'],
        'service_consent' => ['Services: early start asked at checkout (what the shopper ticked, and when)', 'Dienstleistungen: vorzeitiger Beginn an der Kasse verlangt (was die Kunden angekreuzt haben, und wann)'],
        'service_start' => ['Services: service starts on', 'Dienstleistungen: Leistung beginnt am'],
        'service_end' => ['Services: service ends on', 'Dienstleistungen: Leistung endet am'],
        'service_share' => ['Services: part already provided when the withdrawal arrived (%)', 'Dienstleistungen: bei Eingang des Widerrufs bereits erbrachter Teil (%)'],
        'service_done' => ['Services: fully provided on', 'Dienstleistungen: vollständig erbracht am'],
    ];

    /** Allergen names from Annex II of Regulation 1169/2011, English and German, for the product editor. */
    private const ALLERGEN_NAMES = [
        'gluten' => ['Cereals containing gluten', 'Glutenhaltiges Getreide'],
        'crustaceans' => ['Crustaceans', 'Krebstiere'],
        'eggs' => ['Eggs', 'Eier'],
        'fish' => ['Fish', 'Fisch'],
        'peanuts' => ['Peanuts', 'Erdnüsse'],
        'soybeans' => ['Soybeans', 'Soja'],
        'milk' => ['Milk', 'Milch'],
        'nuts' => ['Nuts', 'Schalenfrüchte'],
        'celery' => ['Celery', 'Sellerie'],
        'mustard' => ['Mustard', 'Senf'],
        'sesame' => ['Sesame seeds', 'Sesamsamen'],
        'sulphites' => ['Sulphur dioxide and sulphites', 'Schwefeldioxid und Sulphite'],
        'lupin' => ['Lupin', 'Lupinen'],
        'molluscs' => ['Molluscs', 'Weichtiere'],
    ];

    /** Pictogram names from CLP Annex V, English and German, for the product editor. */
    private const PICTOGRAM_NAMES = [
        'GHS01' => ['Exploding bomb', 'Explodierende Bombe'],
        'GHS02' => ['Flame', 'Flamme'],
        'GHS03' => ['Flame over circle', 'Flamme über einem Kreis'],
        'GHS04' => ['Gas cylinder', 'Gasflasche'],
        'GHS05' => ['Corrosion', 'Ätzwirkung'],
        'GHS06' => ['Skull and crossbones', 'Totenkopf mit gekreuzten Knochen'],
        'GHS07' => ['Exclamation mark', 'Ausrufezeichen'],
        'GHS08' => ['Health hazard', 'Gesundheitsgefahr'],
        'GHS09' => ['Environment', 'Umwelt'],
    ];

    private const MULTILINE = ['digital_consent', 'service_consent', 'checks_text', 'manufacturer_address', 'responsible_address', 'identifiers', 'warnings', 'hazard_statements', 'precautionary_statements', 'food_ingredients', 'food_storage', 'food_business', PriceNotes::PREFIX . 'defects'];

    private const BOOLEAN = ['weee', 'batteries', PriceNotes::PREFIX . 'margin_scheme', 'free', 'whole', 'maker_free', 'maker_whole'];

    private const DATES = ['checked', 'maker_checked', 'service_start', 'service_end', 'service_done'];

    /** Help texts for the withdrawal type and the service fields on the order. */
    private const SERVICE_HELP = [
        'withdrawal_kind' => ['Automatic: digital products are digital content, all others goods. Service: the 14 days count from the order, and shoppers can ask you to start early. Dated booking: leisure, travel, catering, accommodation or car hire on a fixed date, usually without a right of withdrawal (Art. 16(l) of Directive 2011/83/EU). An order with any goods counts as a sales contract. Technical help, not legal advice.', 'Automatisch: digitale Produkte sind digitale Inhalte, alle anderen Waren. Dienstleistung: die 14 Tage zählen ab der Bestellung, und Kunden können einen früheren Beginn verlangen. Termingebundene Buchung: Freizeit, Reise, Gastronomie, Unterkunft oder Mietwagen zu einem festen Termin, meist ohne Widerrufsrecht (Art. 16 lit. l der Richtlinie 2011/83/EU). Eine Bestellung mit Waren zählt als Kaufvertrag. Technische Hilfe, keine Rechtsberatung.'],
        'service_start' => ['With the start and end, the Shopproof page works out the part provided by days when a withdrawal arrives, unless you enter the part below.', 'Mit Beginn und Ende berechnet die Shopproof-Seite bei einem Widerruf den erbrachten Teil nach Tagen, wenn Sie unten keinen Teil eintragen.'],
        'service_share' => ['Use this when days do not fit, for example 3 of 10 lessons given: 30. The customer pays this part of the price only if they asked for the early start at checkout (Art. 14(3) and (4) of Directive 2011/83/EU).', 'Nutzen Sie dies, wenn Tage nicht passen, zum Beispiel 3 von 10 Stunden gegeben: 30. Die Kunden zahlen diesen Teil des Preises nur, wenn sie an der Kasse den früheren Beginn verlangt haben (Art. 14 Abs. 3 und 4 der Richtlinie 2011/83/EU).'],
        'service_done' => ['Once the service is fully provided, after the shopper asked for the early start and acknowledged the loss of the right, a later withdrawal has no effect (Art. 16(a) of Directive 2011/83/EU).', 'Ist die Leistung vollständig erbracht, nachdem die Kunden den früheren Beginn verlangt und den Verlust des Rechts bestätigt haben, bleibt ein späterer Widerruf ohne Wirkung (Art. 16 lit. a der Richtlinie 2011/83/EU).'],
    ];

    /** Help texts under a field: the durability label's rules, so a wrong label is hard to set. */
    private const HELP = [
        'years' => ['The EU "GARAN" label shows only when the years are set and both ticks below are on. Technical help, not legal advice.', 'Das EU-Label "GARAN" erscheint nur, wenn die Jahre angegeben und beide Haken unten gesetzt sind. Technische Hilfe, keine Rechtsberatung.'],
        'maker_years' => ['The EU "GARAN" label shows only when the years are set and both ticks below are on. Each product still needs a manufacturer number or its own model identifier. Technical help, not legal advice.', 'Das EU-Label "GARAN" erscheint nur, wenn die Jahre angegeben und beide Haken unten gesetzt sind. Jedes Produkt braucht trotzdem eine Herstellernummer oder eine eigene Modellkennung. Technische Hilfe, keine Rechtsberatung.'],
        'free' => ['A paid or extended warranty is a different offer: describe it in your own words, without the label.', 'Eine kostenpflichtige oder verlängerte Garantie ist ein anderes Angebot: beschreiben Sie sie mit eigenen Worten, ohne das Label.'],
        'whole' => ['If the guarantee covers only a part (for example only the motor), do not use the label: say in the product description which part is covered and for how long, so shoppers do not take it for a guarantee on the whole product.', 'Gilt die Garantie nur für einen Teil (zum Beispiel nur den Motor), nutzen Sie das Label nicht: schreiben Sie in die Produktbeschreibung, welcher Teil wie lange abgedeckt ist, damit Kunden sie nicht für eine Garantie auf das ganze Produkt halten.'],
        'checked' => ['Check the producer\'s terms again at least once a year, because producers can change or end a guarantee.', 'Prüfen Sie die Bedingungen des Herstellers mindestens einmal im Jahr, denn Hersteller können eine Garantie ändern oder beenden.'],
    ];

    /**
     * @param EntityRepository<\Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection> $setRepository
     */
    public function __construct(
        private readonly EntityRepository $setRepository,
        private readonly Connection $connection,
    ) {
    }

    public function install(Context $context): void
    {
        $this->setRepository->upsert([
            $this->set(self::PRODUCT_SET, 'product', SafetyData::PREFIX, [...SafetyData::PARTY_FIELDS, ...SafetyData::PRODUCT_FIELDS], [
                'Shopproof: product safety (GPSR)',
                'Shopproof: Produktsicherheit (GPSR)',
            ]),
            $this->set(self::MANUFACTURER_SET, 'product_manufacturer', SafetyData::MANUFACTURER_PREFIX, SafetyData::MANUFACTURER_FIELDS, [
                'Shopproof: product safety (GPSR)',
                'Shopproof: Produktsicherheit (GPSR)',
            ]),
            $this->set(self::DURABILITY_SET, 'product', DurabilityLabel::PREFIX, DurabilityLabel::FIELDS, [
                'Shopproof: EU durability label',
                'Shopproof: EU-Haltbarkeitslabel',
            ]),
            $this->set(self::DURABILITY_MAKER_SET, 'product_manufacturer', DurabilityLabel::PREFIX, DurabilityLabel::MAKER_FIELDS, [
                'Shopproof: EU durability label',
                'Shopproof: EU-Haltbarkeitslabel',
            ]),
            $this->set(self::HAZARD_SET, 'product', HazardLabel::PREFIX, HazardLabel::FIELDS, [
                'Shopproof: hazardous chemicals (CLP)',
                'Shopproof: gefährliche Chemikalien (CLP)',
            ]),
            $this->set(self::TAKEBACK_SET, 'product', Registrations::PREFIX, Registrations::SECTIONS, [
                'Shopproof: take-back of old devices and batteries',
                'Shopproof: Rücknahme von Altgeräten und Altbatterien',
            ]),
            $this->set(self::FOOD_SET, 'product', 'shopproof_', array_map(static fn (string $field): string => 'food_' . $field, FoodInformation::FIELDS), [
                'Shopproof: food information (food and drinks only)',
                'Shopproof: Lebensmittelinformationen (nur Lebensmittel und Getränke)',
            ]),
            $this->set(self::NOTES_SET, 'product', '', self::NOTES_FIELDS, [
                'Shopproof: price, deposit and cart notes',
                'Shopproof: Preis-, Pfand- und Warenkorbhinweise',
            ]),
            $this->set(self::ORDER_SET, 'order', 'shopproof_', ['digital_consent', 'checks_text', ...self::SERVICE_ORDER_FIELDS], [
                'Shopproof',
                'Shopproof',
            ]),
            $this->set(self::WITHDRAWAL_SET, 'product', 'shopproof_', ['withdrawal_kind'], [
                'Shopproof: right of withdrawal',
                'Shopproof: Widerrufsrecht',
            ]),
            $this->set(self::ENERGY_SET, 'product', 'shopproof_', self::energyFields(), [
                'Shopproof: energy label and French repair index',
                'Shopproof: Energielabel und französischer Reparaturindex',
            ]),
        ], $context);
    }

    public function uninstall(Context $context): void
    {
        $ids = $this->setRepository->searchIds(
            (new Criteria())->addFilter(new EqualsAnyFilter('name', self::SETS)),
            $context
        )->getIds();
        if ($ids !== []) {
            $this->setRepository->delete(array_map(static fn ($id): array => ['id' => $id], $ids), $context);
        }
    }

    /**
     * Remove the stored values from products, manufacturers and orders. Used only when the merchant does
     * not keep the data on uninstall.
     */
    public function removeValues(): void
    {
        $keys = [
            'product_translation' => [
                ...array_map(static fn (string $field): string => SafetyData::PREFIX . $field, [...SafetyData::PARTY_FIELDS, ...SafetyData::PRODUCT_FIELDS]),
                ...array_map(static fn (string $field): string => DurabilityLabel::PREFIX . $field, DurabilityLabel::FIELDS),
                ...array_map(static fn (string $field): string => HazardLabel::PREFIX . $field, HazardLabel::FIELDS),
                ...array_map(static fn (string $field): string => Registrations::PREFIX . $field, Registrations::SECTIONS),
                ...array_map(static fn (string $field): string => FoodInformation::PREFIX . $field, FoodInformation::FIELDS),
                ...self::NOTES_FIELDS,
                ServiceWithdrawal::KIND_FIELD,
                ...array_map(static fn (string $field): string => 'shopproof_' . $field, self::energyFields()),
            ],
            'product_manufacturer_translation' => [
                ...array_map(static fn (string $field): string => SafetyData::MANUFACTURER_PREFIX . $field, SafetyData::MANUFACTURER_FIELDS),
                ...array_map(static fn (string $field): string => DurabilityLabel::PREFIX . $field, DurabilityLabel::MAKER_FIELDS),
            ],
            'order' => ['shopproof_digital_consent', CheckoutBoxes::ORDER_FIELD, CheckoutBoxes::ORDER_TEXT_FIELD, ...array_map(static fn (string $field): string => 'shopproof_' . $field, self::SERVICE_ORDER_FIELDS)],
        ];
        foreach ($keys as $table => $fields) {
            foreach ($fields as $key) {
                $this->connection->executeStatement(
                    \sprintf('UPDATE `%s` SET custom_fields = JSON_REMOVE(custom_fields, :path) WHERE JSON_CONTAINS_PATH(custom_fields, \'one\', :path)', $table),
                    ['path' => '$.' . $key]
                );
            }
        }
    }

    /**
     * @param list<string>          $fields
     * @param array{string, string} $label
     *
     * @return array<string, mixed>
     */
    private function set(string $name, string $entity, string $prefix, array $fields, array $label): array
    {
        $existingId = $this->connection->fetchOne('SELECT LOWER(HEX(id)) FROM custom_field_set WHERE name = :name', ['name' => $name]);
        $setId = \is_string($existingId) ? $existingId : Uuid::fromStringToHex($name);

        $customFields = [];
        foreach ($fields as $position => $field) {
            $fieldName = $prefix . $field;
            $fieldId = $this->connection->fetchOne('SELECT LOWER(HEX(id)) FROM custom_field WHERE name = :name', ['name' => $fieldName]);
            $config = [
                'componentName' => \in_array($field, self::MULTILINE, true) ? 'sw-textarea-field' : 'sw-text-field',
                'customFieldType' => \in_array($field, self::MULTILINE, true) ? 'textarea' : 'text',
                'customFieldPosition' => $position + 1,
                'label' => ['en-GB' => self::LABELS[$field][0], 'de-DE' => self::LABELS[$field][1]],
            ];
            $type = CustomFieldTypes::TEXT;
            if ($field === 'display') {
                $type = CustomFieldTypes::SELECT;
                $config = [
                    ...$config,
                    'componentName' => 'sw-single-select',
                    'customFieldType' => 'select',
                    'options' => [
                        ['value' => 'nested', 'label' => ['en-GB' => 'Short strip that opens the full label (default)', 'de-DE' => 'Schmaler Streifen, der das ganze Label öffnet (Standard)']],
                        ['value' => 'full', 'label' => ['en-GB' => 'Full label', 'de-DE' => 'Ganzes Label']],
                    ],
                ];
            }
            if ($field === 'hazard_pictograms') {
                $type = CustomFieldTypes::SELECT;
                $config = [
                    ...$config,
                    'componentName' => 'sw-multi-select',
                    'customFieldType' => 'select',
                    'options' => array_map(static fn (string $code): array => [
                        'value' => $code,
                        'label' => ['en-GB' => $code . ' ' . self::PICTOGRAM_NAMES[$code][0], 'de-DE' => $code . ' ' . self::PICTOGRAM_NAMES[$code][1]],
                    ], HazardLabel::PICTOGRAMS),
                ];
            }
            if ($field === 'withdrawal_kind') {
                $type = CustomFieldTypes::SELECT;
                $config = [
                    ...$config,
                    'componentName' => 'sw-single-select',
                    'customFieldType' => 'select',
                    'placeholder' => ['en-GB' => 'Automatic', 'de-DE' => 'Automatisch'],
                    'options' => [
                        ['value' => 'goods', 'label' => ['en-GB' => 'Goods (14 days from delivery)', 'de-DE' => 'Ware (14 Tage ab Lieferung)']],
                        ['value' => 'service', 'label' => ['en-GB' => 'Service (14 days from the order)', 'de-DE' => 'Dienstleistung (14 Tage ab Bestellung)']],
                        ['value' => 'dated', 'label' => ['en-GB' => 'Dated booking (usually no right of withdrawal)', 'de-DE' => 'Termingebundene Buchung (meist kein Widerrufsrecht)']],
                    ],
                ];
            }
            if ($field === 'service_share') {
                $type = CustomFieldTypes::FLOAT;
                $config = [
                    ...$config,
                    'componentName' => 'sw-field',
                    'customFieldType' => 'number',
                    'type' => 'number',
                    'numberType' => 'float',
                    'min' => 0,
                    'max' => 100,
                ];
            }
            if ($field === 'hazard_signal') {
                $type = CustomFieldTypes::SELECT;
                $config = [
                    ...$config,
                    'componentName' => 'sw-single-select',
                    'customFieldType' => 'select',
                    'options' => [
                        ['value' => 'danger', 'label' => ['en-GB' => 'Danger', 'de-DE' => 'Gefahr']],
                        ['value' => 'warning', 'label' => ['en-GB' => 'Warning', 'de-DE' => 'Achtung']],
                    ],
                ];
            }
            if ($field === 'food_allergens') {
                $type = CustomFieldTypes::SELECT;
                $config = [
                    ...$config,
                    'componentName' => 'sw-multi-select',
                    'customFieldType' => 'select',
                    'options' => array_map(static fn (string $code): array => [
                        'value' => $code,
                        'label' => ['en-GB' => self::ALLERGEN_NAMES[$code][0], 'de-DE' => self::ALLERGEN_NAMES[$code][1]],
                    ], FoodInformation::ALLERGENS),
                ];
            }
            if ($field === 'food_nutriscore' || $field === 'food_basis') {
                $choices = $field === 'food_basis' ? FoodInformation::BASES : FoodInformation::NUTRI_SCORES;
                $type = CustomFieldTypes::SELECT;
                $config = [
                    ...$config,
                    'componentName' => 'sw-single-select',
                    'customFieldType' => 'select',
                    'options' => array_map(static fn (string $choice): array => ['value' => $choice, 'label' => ['en-GB' => $choice, 'de-DE' => $choice]], $choices),
                ];
            }
            if (str_starts_with($field, 'food_') && \in_array(substr($field, 5), FoodInformation::NUMBERS, true)) {
                $type = CustomFieldTypes::FLOAT;
                $config = [
                    ...$config,
                    'componentName' => 'sw-field',
                    'customFieldType' => 'number',
                    'type' => 'number',
                    'numberType' => 'float',
                    'min' => 0,
                ];
            }
            if ($field === Deposit::PREFIX . 'units') {
                $type = CustomFieldTypes::INT;
                $config = [
                    ...$config,
                    'componentName' => 'sw-field',
                    'customFieldType' => 'number',
                    'type' => 'number',
                    'numberType' => 'int',
                    'min' => 1,
                    'max' => 999,
                ];
            }
            if (\in_array($field, self::DATES, true)) {
                $type = CustomFieldTypes::DATETIME;
                $config = [
                    ...$config,
                    'componentName' => 'sw-field',
                    'customFieldType' => 'date',
                    'type' => 'date',
                    'dateType' => 'date',
                ];
            }
            if (\in_array($field, self::BOOLEAN, true)) {
                $type = CustomFieldTypes::BOOL;
                $config = [
                    ...$config,
                    'componentName' => 'sw-field',
                    'customFieldType' => 'checkbox',
                    'type' => 'checkbox',
                ];
            }
            $config = [...$config, ...self::energyConfig($field)];
            $type = self::energyType($field) ?? $type;
            $help = $prefix === DurabilityLabel::PREFIX ? (self::HELP[$field] ?? self::HELP[str_replace('maker_', '', $field)] ?? null) : (self::SERVICE_HELP[$field] ?? self::ENERGY_HELP[$field] ?? null);
            if ($help !== null) {
                $config['helpText'] = ['en-GB' => $help[0], 'de-DE' => $help[1]];
            }
            $customField = [
                'id' => \is_string($fieldId) ? $fieldId : Uuid::fromStringToHex($fieldName),
                'name' => $fieldName,
                'type' => $type,
                'config' => $config,
            ];
            // The withdrawal type must reach the cart and the order line item, whose payload keeps
            // only custom fields marked for the cart.
            if ($fieldName === ServiceWithdrawal::KIND_FIELD) {
                $customField['allowCartExpose'] = true;
            }
            $customFields[] = $customField;
        }

        $relationId = $this->connection->fetchOne(
            'SELECT LOWER(HEX(r.id)) FROM custom_field_set_relation r JOIN custom_field_set s ON s.id = r.set_id WHERE s.name = :name AND r.entity_name = :entity',
            ['name' => $name, 'entity' => $entity]
        );

        return [
            'id' => $setId,
            'name' => $name,
            'config' => ['label' => ['en-GB' => $label[0], 'de-DE' => $label[1]], 'translated' => true],
            'relations' => [[
                'id' => \is_string($relationId) ? $relationId : Uuid::fromStringToHex($name . '.' . $entity),
                'entityName' => $entity,
            ]],
            'customFields' => $customFields,
        ];
    }

    /** @return list<string> the energy fields after "shopproof_" */
    public static function energyFields(): array
    {
        return array_map(static fn (string $field): string => 'energy_' . $field, array_keys(EnergyLabel::FIELDS));
    }

    private static function energyType(string $field): ?string
    {
        return match ($field) {
            'energy_class', 'energy_range', 'energy_fr_type' => CustomFieldTypes::SELECT,
            'energy_fr_score' => CustomFieldTypes::FLOAT,
            'energy_label', 'energy_fr_logo' => CustomFieldTypes::MEDIA,
            default => null,
        };
    }

    /**
     * @param list<array{string, string, string}> $options Value, English label, German label.
     *
     * @return array<string, mixed>
     */
    private static function energyChoices(array $options): array
    {
        return [
            'componentName' => 'sw-single-select',
            'customFieldType' => 'select',
            'options' => array_map(
                static fn (array $o): array => ['value' => $o[0], 'label' => ['en-GB' => $o[1], 'de-DE' => $o[2]]],
                $options,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private static function energyConfig(string $field): array
    {
        $choices = self::energyChoices(...);

        return match ($field) {
            'energy_class' => $choices(array_map(static fn (string $c): array => [$c, $c, $c], EnergyLabel::CLASSES)),
            'energy_range' => $choices(array_map(static function (string $r): array {
                [$top, $bottom] = EnergyLabel::rangeEnds($r);

                return [$r, $top . ' to ' . $bottom, $top . ' bis ' . $bottom];
            }, EnergyLabel::RANGES)),
            'energy_fr_type' => $choices([
                ['repair', 'Repairability index', 'Reparierbarkeitsindex (indice de réparabilité)'],
                ['durability', 'Durability index', 'Haltbarkeitsindex (indice de durabilité)'],
            ]),
            'energy_fr_score' => ['componentName' => 'sw-field', 'customFieldType' => 'number', 'type' => 'number', 'numberType' => 'float', 'min' => 0, 'max' => 10, 'step' => 0.1],
            'energy_label', 'energy_fr_logo' => ['componentName' => 'sw-media-field', 'customFieldType' => 'media'],
            default => [],
        };
    }
}
