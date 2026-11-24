import template from './shopproof-overview-index.html.twig';
import './shopproof-overview-index.scss';
import { refundLine, refundLink } from '../../../../withdrawal/refund-line.mjs';

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
            exporting: false,
            exportFailed: false,
            shopDetails: null,
            usingShopDetails: false,
            shopDetailsResult: '',
            cancellations: null,
            cancellationsFailed: false,
            cancellationsLoading: false,
            cancellationsPage: 1,
            confirmCancelId: null,
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
        this.loadShopDetails();
        this.loadWithdrawals();
        this.loadProof();
        this.loadReturns();
        this.loadCancellations();
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

        /**
         * The window line and the refund line of a clock, and for services the amount owed, each
         * with its tone (ok, warn, bad, none).
         */
        clockLines(clock) {
            if (!clock) {
                return [];
            }
            const t = (key, values, count) => (count === undefined
                ? this.$t(`shopproof.withdrawal.clock.${key}`, values)
                : this.$t(`shopproof.withdrawal.clock.${key}`, values, count));
            const day = (value) => (value ? Shopware.Utils.format.date(`${value}T12:00:00`, { hour: undefined, minute: undefined }) : '');
            const windowTone = { inside: 'ok', before_delivery: 'ok', late: 'bad', check: 'warn', performed: 'warn', digital: 'warn', exempt: 'warn' }[clock.window] || 'none';
            let windowText;
            if (clock.window === 'late') {
                windowText = t('late', { days: clock.daysOver, date: day(clock.windowEnd) }, clock.daysOver);
            } else if (clock.window === 'inside' && clock.basis === 'order') {
                windowText = t('insideOrder', { date: day(clock.windowEnd) });
            } else {
                windowText = t(clock.window, { date: day(clock.windowEnd) });
            }
            const line = refundLine(clock, day);
            let refund = [t(line.key, line.values, line.count), line.tone];
            if (clock.partlyRefunded && ['due', 'today', 'overdue', 'waiting'].includes(clock.refund)) {
                refund = [t('partly', { date: day(clock.partlyRefunded), line: refund[0] }), refund[1] === 'bad' ? 'bad' : 'warn'];
            }
            const lines = [[windowText, windowTone], refund];
            // Services: what the customer pays for the part provided (Art. 14(3) and (4)).
            if (clock.owed) {
                const money = (value) => Shopware.Utils.format.currency(value, clock.owed.currency || 'EUR', 2);
                lines.push([t(`owed.${clock.owed.state}`, {
                    days: clock.owed.days,
                    of: clock.owed.of,
                    share: clock.owed.share,
                    owed: money(clock.owed.owed),
                    refund: money(clock.owed.refund),
                }), clock.owed.state === 'unknown' ? 'warn' : 'none']);
            }
            return lines;
        },

        removeWithdrawal(id) {
            return this.shopproofRulesService.deleteWithdrawal(id).then(() => {
                this.confirmId = null;
                this.loadWithdrawals();
            });
        },

        loadCancellations() {
            this.cancellationsLoading = true;
            this.cancellationsFailed = false;
            return this.shopproofRulesService
                .getCancellations(this.cancellationsPage)
                .then((result) => {
                    this.cancellations = result;
                })
                .catch(() => {
                    this.cancellationsFailed = true;
                })
                .finally(() => {
                    this.cancellationsLoading = false;
                });
        },

        onCancellationsPageChange({ page }) {
            this.cancellationsPage = page;
            this.loadCancellations();
        },

        markCancellationDone(id) {
            return this.shopproofRulesService.markCancellationDone(id).then(() => this.loadCancellations());
        },

        removeCancellation(id) {
            return this.shopproofRulesService.deleteCancellation(id).then(() => {
                this.confirmCancelId = null;
                this.loadCancellations();
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

        /** Saves a file the browser received (the proof log or withdrawals CSV). */
        saveFile(blob, name) {
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = name;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(link.href);
        },

        exportWithdrawals() {
            this.exporting = true;
            this.exportFailed = false;
            const lang = String(Shopware.State?.get('session')?.currentLocale || 'en-GB').startsWith('de') ? 'de' : 'en';
            return this.shopproofRulesService
                .downloadWithdrawals(lang)
                .then((blob) => this.saveFile(blob, `shopproof-withdrawals-${new Date().toISOString().slice(0, 10)}.csv`))
                .catch(() => {
                    this.exportFailed = true;
                })
                .finally(() => {
                    this.exporting = false;
                });
        },

        loadShopDetails() {
            return this.shopproofRulesService
                .getShopDetails()
                .then((result) => {
                    this.shopDetails = result;
                })
                .catch(() => {
                    this.shopDetails = null;
                });
        },

        useShopDetails() {
            this.usingShopDetails = true;
            this.shopDetailsResult = '';
            return this.shopproofRulesService
                .useShopDetails()
                .then((result) => {
                    this.shopDetails = result;
                    this.shopDetailsResult = 'saved';
                    // The safety step and card at the top count the manufacturers just filled in.
                    if (this.$refs.setup) {
                        this.$refs.setup.load();
                    }
                    return this.loadCheck();
                })
                .catch(() => {
                    this.shopDetailsResult = 'failed';
                })
                .finally(() => {
                    this.usingShopDetails = false;
                });
        },

        downloadProof() {
            return this.shopproofRulesService
                .downloadProofLog()
                .then((blob) => this.saveFile(blob, `shopproof-proof-log-${new Date().toISOString().slice(0, 10)}.csv`));
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

        /** The shortcut to the order while a refund is still to do (refundLink in refund-line.mjs). */
        showRefundLink(item) {
            return refundLink(item);
        },

        /** A finished placement check changes the setup steps and the withdrawal button card. */
        onPlacementChecked() {
            if (this.$refs.setup) {
                this.$refs.setup.load();
            }
        },

        countryName(code) {
            return this.$t(`shopproof.countries.${code}`);
        },
    },
});
