import template from './shopproof-a11y-statement.html.twig';
import './shopproof-a11y-statement.scss';
import { buildStatement, statementLanguage, STATEMENT_LANGS } from '../../a11y/a11y-statement.mjs';

/**
 * The "Accessibility statement" card on the Shopproof page (gap audit 22 Nov 2026, item 10), as in
 * the WooCommerce plugin and the Shopify app: a draft statement from the shop's details in
 * English, German, French, Italian or Dutch, to copy into a page. Nothing is stored or sent; the
 * draft is built in the browser. It never claims compliance. The placement check on the same page
 * also looks for a link to it. Technical help, not legal advice.
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    inject: ['shopproofRulesService'],

    data() {
        return {
            countries: ['AT', 'DE', 'FR', 'IT', 'NL', 'other'],
            languages: STATEMENT_LANGS,
            country: 'DE',
            lang: '',
            shop: '',
            url: '',
            email: '',
            about: '',
            measures: '',
            barriers: '',
            copied: false,
        };
    },

    computed: {
        language() {
            return statementLanguage(this.lang, this.country);
        },

        draft() {
            return buildStatement({
                lang: this.language,
                country: this.country === 'other' ? '' : this.country,
                shop: this.shop,
                url: this.url,
                email: this.email,
                date: new Date().toLocaleDateString(this.language),
                about: this.about,
                measures: this.measures,
                barriers: this.barriers,
            });
        },

        canCopy() {
            return typeof navigator !== 'undefined' && !!navigator.clipboard;
        },
    },

    watch: {
        draft() {
            this.copied = false;
        },
    },

    created() {
        // Start with the country of the admin language; the shop changes it if it sits elsewhere.
        const admin = String((Shopware.State.get('session') || {}).currentLocale || '').slice(3, 5).toUpperCase();
        if (this.countries.includes(admin)) {
            this.country = admin;
        }
        this.shopproofRulesService
            .getShopDetails()
            .then((result) => {
                const shop = (result && result.shop) || {};
                this.shop = this.shop || shop.name || '';
                // The admin runs on the shop's own address when no storefront address is set.
                this.url = this.url || shop.url || window.location.origin;
                this.email = this.email || shop.email || '';
            })
            .catch(() => {});
    },

    methods: {
        copy() {
            navigator.clipboard.writeText(this.draft.text).then(() => {
                this.copied = true;
            }, () => {
                this.copied = false;
            });
        },
    },
});
