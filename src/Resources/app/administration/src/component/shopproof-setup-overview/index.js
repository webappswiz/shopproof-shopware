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
            ticked: [],
            answering: false,
            answerResult: null,
            hintsTicked: [],
            hinting: false,
            hintResult: null,
            quietSaving: false,
            quietFailed: false,
            keeping: false,
            keepFailed: false,
        };
    },

    computed: {
        setupDone() {
            return this.result && this.result.done === this.result.required;
        },

        /** New rules come only with plugin updates: the "Keep rules up to date" button shows. */
        rulesManual() {
            return !!(this.result && this.result.cards.some((card) => card.key === 'rules' && card.text === 'manual'));
        },
    },

    created() {
        this.load();
    },

    methods: {
        load() {
            this.failed = false;
            return this.shopproofRulesService
                .getOverview(String(Shopware.State?.get('session')?.currentLocale || ''))
                .then((result) => {
                    this.result = result;
                    // A ticked answer that is no longer offered is dropped, never saved later.
                    const offered = (result && Array.isArray(result.answers) ? result.answers : []).map((a) => a.key);
                    this.ticked = this.ticked.filter((key) => offered.includes(key));
                    const hints = (result && Array.isArray(result.exclusionHints) ? result.exclusionHints : []).map((h) => h.key);
                    this.hintsTicked = this.hintsTicked.filter((key) => hints.includes(key));
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

        /** Ready-made answers: only the ones the merchant ticked are saved. */
        saveAnswers() {
            if (!this.ticked.length) {
                this.answerResult = { saved: 0 };
                return Promise.resolve();
            }
            this.answering = true;
            this.answerResult = null;
            return this.shopproofRulesService
                .applyAnswers(this.ticked)
                .then((response) => {
                    // ticked: the merchant did tick something, so nothing saved means it was already done elsewhere.
                    this.answerResult = { saved: response && Array.isArray(response.saved) ? response.saved.length : 0, ticked: true };
                    this.ticked = [];
                    return this.load();
                })
                .catch(() => {
                    this.answerResult = { failed: true };
                })
                .finally(() => {
                    this.answering = false;
                });
        },

        /** Suggested withdrawal exclusions: only the ticked ones are excluded. */
        excludeHints() {
            if (!this.hintsTicked.length) {
                this.hintResult = { excluded: 0 };
                return Promise.resolve();
            }
            return this.runHints(() => this.shopproofRulesService.applyExclusionHints(this.hintsTicked), (response) => ({
                excluded: response && Array.isArray(response.excluded) ? response.excluded.length : 0,
                ticked: true,
            }));
        },

        hideHints() {
            return this.runHints(() => this.shopproofRulesService.hideExclusionHints(), () => ({ hidden: true }));
        },

        runHints(call, outcome) {
            this.hinting = true;
            this.hintResult = null;
            return call()
                .then((response) => {
                    this.hintResult = outcome(response);
                    this.hintsTicked = [];
                    return this.load();
                })
                .catch(() => {
                    this.hintResult = { failed: true };
                })
                .finally(() => {
                    this.hinting = false;
                });
        },

        /**
         * The quiet email switch: saved as soon as it is ticked or unticked. When the save fails, the
         * box goes back to the saved state, so it never shows a choice that was not kept.
         */
        setQuietEmail(on, box = null) {
            this.quietSaving = true;
            this.quietFailed = false;
            return this.shopproofRulesService
                .setQuietEmail(on)
                .then((response) => {
                    if (this.result && this.result.quietEmail) {
                        this.result.quietEmail.on = response && response.on === true;
                    }
                })
                .catch(() => {
                    this.quietFailed = true;
                    if (box && this.result && this.result.quietEmail) {
                        box.checked = this.result.quietEmail.on === true;
                    }
                })
                .finally(() => {
                    this.quietSaving = false;
                });
        },

        /** "Keep rules up to date": turns on the daily rule check and checks once now. */
        keepCurrent() {
            this.keeping = true;
            this.keepFailed = false;
            return this.shopproofRulesService
                .keepRulesCurrent()
                .then(() => this.load())
                .catch(() => {
                    this.keepFailed = true;
                })
                .finally(() => {
                    this.keeping = false;
                });
        },

        hintTitle(hint) {
            if (hint.kind === 'download') {
                return this.$t('shopproof.setup.exclusionHints.digital');
            }
            return this.$tc('shopproof.setup.exclusionHints.category', hint.count, { name: hint.name, count: hint.count });
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
            if (key === 'rules' && this.rulesManual) {
                return { anchor: 'shopproof-keep-current' };
            }
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
