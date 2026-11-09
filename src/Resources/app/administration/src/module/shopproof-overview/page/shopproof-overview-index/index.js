import template from './shopproof-overview-index.html.twig';
import './shopproof-overview-index.scss';

/** Country order and names follow the rules file's launch countries. */
const ORDER = ['EU', 'AT', 'DE', 'FR', 'IT', 'NL'];

export default Shopware.Component.wrapComponentConfig({
    template,

    inject: ['shopproofRulesService'],

    data() {
        return {
            isLoading: true,
            failed: false,
            overview: null,
            refreshing: false,
            refreshResult: '',
            check: null,
            checkFailed: false,
            withdrawals: null,
            withdrawalsFailed: false,
            withdrawalsLoading: false,
            page: 1,
            term: '',
            confirmId: null,
            clearing: false,
        };
    },

    metaInfo() {
        return { title: this.$createTitle() };
    },

    computed: {
        groups() {
            if (!this.overview || !Array.isArray(this.overview.rules)) {
                return [];
            }
            return ORDER.map((code) => ({
                code,
                rules: this.overview.rules.filter((rule) => rule.jurisdiction === code),
            })).filter((group) => group.rules.length > 0);
        },

        fetchedAt() {
            if (!this.overview || !this.overview.fetchedAt) {
                return '';
            }
            return Shopware.Utils.format.date(this.overview.fetchedAt);
        },
    },

    created() {
        this.load();
        this.loadCheck();
        this.loadWithdrawals();
    },

    methods: {
        load() {
            this.isLoading = true;
            this.failed = false;
            return this.shopproofRulesService
                .getRules()
                .then((overview) => {
                    this.overview = overview;
                })
                .catch(() => {
                    this.failed = true;
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        refreshRules() {
            this.refreshing = true;
            this.refreshResult = '';
            return this.shopproofRulesService
                .refreshRules()
                .then((result) => {
                    this.refreshResult = result && result.updated ? 'updated' : 'failed';
                    return this.load();
                })
                .catch(() => {
                    this.refreshResult = 'failed';
                })
                .finally(() => {
                    this.refreshing = false;
                });
        },

        loadCheck() {
            this.checkFailed = false;
            return this.shopproofRulesService
                .getWithdrawalCheck()
                .then((check) => {
                    this.check = check;
                })
                .catch(() => {
                    this.checkFailed = true;
                });
        },

        loadWithdrawals() {
            this.withdrawalsLoading = true;
            this.withdrawalsFailed = false;
            return this.shopproofRulesService
                .getWithdrawals(this.page, this.term)
                .then((result) => {
                    this.withdrawals = result;
                })
                .catch(() => {
                    this.withdrawalsFailed = true;
                })
                .finally(() => {
                    this.withdrawalsLoading = false;
                });
        },

        search() {
            this.page = 1;
            this.loadWithdrawals();
        },

        onPageChange({ page }) {
            this.page = page;
            this.loadWithdrawals();
        },

        removeWithdrawal(id) {
            return this.shopproofRulesService.deleteWithdrawal(id).then(() => {
                this.confirmId = null;
                this.loadWithdrawals();
            });
        },

        clearFailures() {
            this.clearing = true;
            return this.shopproofRulesService
                .clearWithdrawalFailures()
                .then(() => this.loadCheck())
                .finally(() => {
                    this.clearing = false;
                });
        },

        checkText(check) {
            const key = check.key === 'labels' && !check.ok ? 'labelsMissing' : check.key;
            return this.$t(`shopproof.withdrawal.check.${key}`, { languages: check.detail || '' });
        },

        formatTime(value) {
            return value ? Shopware.Utils.format.date(value, { hour: '2-digit', minute: '2-digit' }) : '';
        },

        orderLink(id) {
            return { name: 'sw.order.detail', params: { id } };
        },

        countryName(code) {
            return this.$t(`shopproof.countries.${code}`);
        },
    },
});
