import template from './shopproof-green-claims.html.twig';

/**
 * The "Green claims" card on the Shopproof page, as in the WooCommerce plugin and the Shopify
 * app: product names and descriptions with the example words of Directive (EU) 2024/825, listed
 * as worth checking, never as a breach. The shop's server reads its own products; nothing
 * leaves the shop.
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    inject: ['shopproofRulesService'],

    data() {
        return {
            loading: true,
            failed: false,
            result: null,
            shown: 50,
        };
    },

    computed: {
        aside() {
            if (!this.result || !this.result.aside) {
                return 0;
            }
            const a = this.result.aside;
            return a.not + a.disposal + a.specific;
        },

        scope() {
            const r = this.result;
            if (!r) {
                return '';
            }
            if (r.total > r.checked) {
                return this.$t('shopproof.greenClaims.scopeSome', { checked: r.checked, total: r.total });
            }
            return this.$tc('shopproof.greenClaims.scopeAll', r.checked, { checked: r.checked });
        },

        note() {
            return this.aside > 0 ? this.$tc('shopproof.greenClaims.aside', this.aside, { count: this.aside }) : '';
        },
    },

    created() {
        this.load();
    },

    methods: {
        load() {
            this.loading = true;
            this.failed = false;
            return this.shopproofRulesService
                .getGreenClaims()
                .then((result) => {
                    this.result = result;
                    this.failed = !result || result.ready !== true;
                })
                .catch(() => {
                    this.failed = true;
                })
                .finally(() => {
                    this.loading = false;
                });
        },

        line(found) {
            return this.$t('shopproof.greenClaims.line', {
                term: found.term,
                kind: this.$t(`shopproof.greenClaims.kind.${found.kind}`),
                field: this.$t(`shopproof.greenClaims.field.${found.field}`),
                excerpt: found.excerpt,
            });
        },
    },
});
