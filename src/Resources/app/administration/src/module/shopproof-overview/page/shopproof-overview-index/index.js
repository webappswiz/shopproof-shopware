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
            statusNotice: null,
            pendingStatus: null,
            withdrawalsFailed: false,
            withdrawalsLoading: false,
            page: 1,
            term: '',
            confirmId: null,
            clearing: false,
            proof: null,
            proofFailed: false,
            proofLoading: false,
            proofPage: 1,
            verifying: false,
            verifyResult: null,
            snapshotting: false,
            returns: null,
            returnsLoading: false,
            returnsTesting: false,
            returnsResult: '',
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
        this.loadProof();
        this.loadReturns();
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

        loadReturns() {
            this.returnsLoading = true;
            return this.shopproofRulesService
                .getReturns()
                .then((result) => {
                    this.returns = result;
                })
                .catch(() => {
                    this.returns = null;
                })
                .finally(() => {
                    this.returnsLoading = false;
                });
        },

        renewSecret() {
            return this.shopproofRulesService.renewReturnsSecret().then((result) => {
                this.returns = { ...this.returns, secret: result.secret };
                this.returnsResult = this.$t('shopproof.returns.renewed');
            });
        },

        sendTest() {
            this.returnsTesting = true;
            this.returnsResult = '';
            return this.shopproofRulesService
                .sendReturnsTest()
                .then((result) => {
                    if (result.ok) {
                        this.returnsResult = this.$t('shopproof.returns.testOk', { code: result.code });
                    } else if (result.code > 0) {
                        this.returnsResult = this.$t('shopproof.returns.testRefused', { code: result.code });
                    } else {
                        this.returnsResult = this.$t('shopproof.returns.testUnreachable');
                    }
                })
                .catch(() => {
                    this.returnsResult = this.$t('shopproof.returns.testUnreachable');
                })
                .finally(() => {
                    this.returnsTesting = false;
                });
        },

        webhookTone(webhook) {
            return { sent: 'ok', queued: 'none', retrying: 'warn', failed: 'bad' }[webhook.status] || 'none';
        },

        webhookText(webhook) {
            return this.$t(`shopproof.returns.delivery.${webhook.status}`, {
                time: this.formatTime(webhook.at),
                code: webhook.code,
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

        /** A move to Return arrived or Refund sent can email the customer, so it is confirmed first. */
        askStatus(item, event) {
            const status = event.target.value;
            this.statusNotice = null;
            if (status === 'received' || status === item.status) {
                this.pendingStatus = null;
                return status === item.status ? null : this.setStatus(item, status);
            }
            this.pendingStatus = { id: item.id, status, select: event.target };
            return null;
        },

        confirmStatus(item) {
            const pending = this.pendingStatus;
            this.pendingStatus = null;
            return pending && pending.id === item.id ? this.setStatus(item, pending.status) : null;
        },

        cancelStatus(item) {
            if (this.pendingStatus && this.pendingStatus.select) {
                this.pendingStatus.select.value = item.status;
            }
            this.pendingStatus = null;
        },

        setStatus(item, status) {
            this.statusNotice = null;
            return this.shopproofRulesService
                .setWithdrawalStatus(item.id, status)
                .then((result) => {
                    this.statusNotice = { id: item.id, key: result.emailed ? 'emailed' : 'notEmailed', show: result.changed && status !== 'received' };
                    return this.loadWithdrawals();
                })
                .catch(() => {
                    this.statusNotice = { id: item.id, key: 'statusFailed', show: true };
                });
        },

        setDelivered(item, day) {
            return this.shopproofRulesService
                .setWithdrawalDelivered(item.id, day || '')
                .then(() => this.loadWithdrawals())
                .catch(() => {
                    this.statusNotice = { id: item.id, key: 'statusFailed', show: true };
                });
        },

        /** The window line and the refund line of a clock, each with its tone (ok, warn, bad, none). */
        clockLines(clock) {
            if (!clock) {
                return [];
            }
            const t = (key, values, count) => (count === undefined
                ? this.$t(`shopproof.withdrawal.clock.${key}`, values)
                : this.$tc(`shopproof.withdrawal.clock.${key}`, count, values));
            const day = (value) => (value ? Shopware.Utils.format.date(`${value}T12:00:00`, { hour: undefined, minute: undefined }) : '');
            const windowTone = { inside: 'ok', before_delivery: 'ok', late: 'bad', check: 'warn' }[clock.window] || 'none';
            const windowText = clock.window === 'late'
                ? t('late', { days: clock.daysOver, date: day(clock.windowEnd) }, clock.daysOver)
                : t(clock.window, { date: day(clock.windowEnd) });
            let refund;
            if (clock.refund === 'paid') {
                refund = [t('paid', { date: day(clock.refunded) }), 'ok'];
            } else if (clock.refund === 'none') {
                refund = [t('none'), 'none'];
            } else if (clock.refund === 'today') {
                refund = [t('today', { date: day(clock.refundDue) }), 'bad'];
            } else if (clock.refund === 'overdue') {
                refund = [t('overdue', { days: -clock.daysLeft, date: day(clock.refundDue) }, -clock.daysLeft), 'bad'];
            } else {
                refund = [t('due', { days: clock.daysLeft, date: day(clock.refundDue) }, clock.daysLeft), clock.daysLeft <= 3 ? 'warn' : 'ok'];
            }
            if (clock.partlyRefunded && ['due', 'today', 'overdue'].includes(clock.refund)) {
                refund = [t('partly', { date: day(clock.partlyRefunded), line: refund[0] }), refund[1] === 'bad' ? 'bad' : 'warn'];
            }
            return [[windowText, windowTone], refund];
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

        loadProof() {
            this.proofLoading = true;
            this.proofFailed = false;
            return this.shopproofRulesService
                .getProofLog(this.proofPage)
                .then((result) => {
                    this.proof = result;
                })
                .catch(() => {
                    this.proofFailed = true;
                })
                .finally(() => {
                    this.proofLoading = false;
                });
        },

        onProofPageChange({ page }) {
            this.proofPage = page;
            this.loadProof();
        },

        verifyProof() {
            this.verifying = true;
            this.verifyResult = null;
            return this.shopproofRulesService
                .verifyProofLog()
                .then((result) => {
                    this.verifyResult = result;
                })
                .catch(() => {
                    this.verifyResult = { failed: true };
                })
                .finally(() => {
                    this.verifying = false;
                });
        },

        recordSnapshot() {
            this.snapshotting = true;
            return this.shopproofRulesService
                .recordProofSnapshot()
                .then(() => {
                    this.proofPage = 1;
                    return this.loadProof();
                })
                .finally(() => {
                    this.snapshotting = false;
                });
        },

        downloadProof() {
            return this.shopproofRulesService.downloadProofLog().then((blob) => {
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = `shopproof-proof-log-${new Date().toISOString().slice(0, 10)}.csv`;
                document.body.appendChild(link);
                link.click();
                link.remove();
                URL.revokeObjectURL(link.href);
            });
        },

        checkText(check) {
            let key = check.key;
            if (!check.ok && check.key === 'labels') {
                key = 'labelsMissing';
            } else if (!check.ok && check.key === 'wording') {
                key = check.detail ? 'wordingMissing' : 'wordingOwn';
            }
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
