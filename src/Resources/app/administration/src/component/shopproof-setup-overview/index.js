import template from './shopproof-setup-overview.html.twig';
import './shopproof-setup-overview.scss';

/** Where each step and card is done: a route in the admin, or a card further down this page. */
const PLACES = {
    button: { route: { name: 'sw.settings.basic.information.index' } },
    placement: { anchor: 'shopproof-placement' },
    safety: { route: { name: 'sw.manufacturer.index' } },
    guarantee: { route: { name: 'sw.extension.config', params: { namespace: 'ShopproofCompliance' } } },
    registrations: { route: { name: 'sw.extension.config', params: { namespace: 'ShopproofCompliance' } } },
    withdrawal: { anchor: 'shopproof-placement' },
    withdrawals: { anchor: 'shopproof-withdrawals' },
    refunds: { anchor: 'shopproof-withdrawals' },
    proof: { anchor: 'shopproof-proof-log' },
    rules: { anchor: 'shopproof-rules' },
};

/** The rule behind each step, for "Why is this needed?". */
const RULES = { button: 'EU-WD-01', placement: 'EU-WD-01', safety: 'EU-PS-01', guarantee: 'EU-GU-01' };

/**
 * The top of the Shopproof page, as the WooCommerce plugin's Overview tab: the setup checklist
 * with a way to each step, and one status card per feature. The server sends codes and numbers;
 * the words are here.
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    inject: ['shopproofRulesService'],

    props: {
        rules: {
            type: Array,
            required: false,
            default: () => [],
        },
    },

    data() {
        return {
            result: null,
            failed: false,
            switching: false,
            switchResult: '',
        };
    },

    computed: {
        setupDone() {
            return this.result && this.result.done === this.result.required;
        },
    },

    created() {
        this.load();
    },

    methods: {
        load() {
            this.failed = false;
            return this.shopproofRulesService
                .getOverview()
                .then((result) => {
                    this.result = result;
                })
                .catch(() => {
                    this.failed = true;
                });
        },

        /** "Switch on what your shop needs": the server reads the shop's facts again on the press. */
        switchOn() {
            this.switching = true;
            this.switchResult = '';
            return this.shopproofRulesService
                .applyRecommended()
                .then(() => this.load())
                .catch(() => {
                    this.switchResult = 'failed';
                })
                .finally(() => {
                    this.switching = false;
                });
        },

        undoSwitch() {
            this.switching = true;
            this.switchResult = '';
            return this.shopproofRulesService
                .undoRecommended()
                .then((response) => {
                    // The server says whether anything was put back; after 14 days nothing is.
                    this.switchResult = response && response.undone === true ? 'undone' : 'nothing';
                    return this.load();
                })
                .catch(() => {
                    this.switchResult = 'failed';
                })
                .finally(() => {
                    this.switching = false;
                });
        },

        place(key) {
            return PLACES[key] || {};
        },

        go(key) {
            const anchor = this.place(key).anchor;
            const target = anchor ? document.getElementById(anchor) : null;
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                const focusable = target.querySelector('select, button, a[href], input');
                if (focusable) {
                    focusable.focus({ preventScroll: true });
                }
            }
        },

        why(key) {
            const id = RULES[key];
            const rule = id ? this.rules.find((r) => r.id === id) : null;
            if (!rule || !rule.legal_basis || !rule.source_url || !String(rule.source_url).startsWith('https://')) {
                return null;
            }
            return {
                text: this.$t('shopproof.setup.whyText', {
                    law: rule.legal_basis,
                    date: rule.effective_from ? Shopware.Utils.format.date(`${rule.effective_from}T12:00:00`, { hour: undefined, minute: undefined }) : '',
                }),
                url: rule.source_url,
            };
        },

        cardText(card) {
            const key = `shopproof.setup.card.${card.key}.${card.text}`;
            const values = { ...card.values };
            if (card.values.date) {
                values.date = Shopware.Utils.format.date(`${card.values.date}T12:00:00`, { hour: undefined, minute: undefined });
            }
            return typeof card.values.count === 'number' ? this.$t(key, values, card.values.count) : this.$t(key, values);
        },

        cardLink(card) {
            const action = ['good', 'info'].includes(card.state) ? 'open' : 'fix';
            return this.$t(`shopproof.setup.link.${action}`);
        },
    },
});
