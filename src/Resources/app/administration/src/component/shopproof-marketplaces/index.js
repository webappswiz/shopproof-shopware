import template from './shopproof-marketplaces.html.twig';

/** Largest eBay file read, the same limit as the WooCommerce plugin and the shop's server. */
const MAX_SIZE = 8 * 1024 * 1024;

/** Rows listed in the table; the downloads always hold everything. */
const SHOWN = 200;

/** Codes with a sentence of their own; other party codes are the party plus a reason. */
const WHOLE = ['manufacturer.missing', 'responsible.missing', 'responsible.cleared'];

const camel = (code) => code.replace(/-([a-z])/g, (m, c) => c.toUpperCase());

/**
 * The "Sell on eBay or Amazon" card (decision 0046), as in the WooCommerce plugin and the Shopify
 * app: fills eBay's own Seller Hub file with the product safety details and makes the Amazon
 * contact and listing files. The shop's server reads its own products and hands the files back;
 * nothing is stored and nothing is sent to eBay or Amazon.
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    inject: ['shopproofRulesService'],

    data() {
        return {
            file: null,
            busy: '',
            failed: false,
            result: null,
            shown: SHOWN,
        };
    },

    computed: {
        notes() {
            const rows = (this.result && this.result.rows) || [];
            return rows.filter((row) => row.status !== 'filled' || row.warnings.length);
        },

        summary() {
            const r = this.result;
            if (!r || r.error) {
                return '';
            }
            const total = r.rows.length;
            if (r.kind === 'ebay') {
                return this.$t('shopproof.marketplaces.ebayFilled', { filled: r.filled, total }, total);
            }
            return this.$t('shopproof.marketplaces.amazonFilled', { filled: r.filled, total }, total);
        },
    },

    methods: {
        pick(event) {
            const files = event && event.target ? event.target.files : null;
            this.file = files && files.length ? files[0] : null;
        },

        fillEbay() {
            if (!this.file) {
                this.show({ kind: 'ebay', error: 'no-file' });
                return Promise.resolve();
            }
            if (this.file.size > MAX_SIZE) {
                this.show({ kind: 'ebay', error: 'too-big' });
                return Promise.resolve();
            }
            this.busy = 'ebay';
            this.failed = false;
            return this.file
                .text()
                .then((csv) => this.shopproofRulesService.fillEbayFile(csv))
                .then((result) => this.show({ ...result, kind: 'ebay' }))
                .catch(() => {
                    this.failed = true;
                })
                .finally(() => {
                    this.busy = '';
                });
        },

        makeAmazon() {
            this.busy = 'amazon';
            this.failed = false;
            return this.shopproofRulesService
                .makeAmazonFiles()
                .then((result) => this.show({ ...result, kind: 'amazon' }))
                .catch(() => {
                    this.failed = true;
                })
                .finally(() => {
                    this.busy = '';
                });
        },

        show(result) {
            this.result = result;
        },

        /** Text for a problem or warning code from the shared export logic. */
        message(code) {
            const [who, reason] = code.split('.');
            if (reason && !WHOLE.includes(code)) {
                return this.$t('shopproof.marketplaces.party', {
                    who: this.$t(`shopproof.marketplaces.who.${who}`),
                    reason: this.$t(`shopproof.marketplaces.reason.${camel(reason)}`),
                });
            }
            return this.$t(`shopproof.marketplaces.problem.${camel(code.replace('.', '-'))}`);
        },

        lines(row) {
            return [...row.problems, ...row.warnings].map((code) => this.message(code));
        },

        download(which) {
            const r = this.result;
            const day = new Date().toISOString().slice(0, 10);
            const files = {
                ebay: [r.csv, `ebay-safety-details-${day}.csv`],
                contacts: [r.contacts, `amazon-safety-contacts-${day}.csv`],
                listings: [r.listings, `amazon-safety-listings-${day}.csv`],
            };
            const [text, name] = files[which];
            const link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
            link.download = name;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(link.href);
        },
    },
});
