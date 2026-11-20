import template from './shopproof-contact.html.twig';
import './shopproof-contact.scss';

const HELP_URL = 'https://shopproof.app/help/';
const PRIVACY_URL = 'https://shopproof.app/privacy/#contact';

/**
 * The "Help and contact" card at the end of the Shopproof page, as in the WooCommerce plugin and
 * the Shopify app: guides, a message form and our address. Nothing leaves the shop until the
 * merchant presses Send; then the shop's server posts the message to shopproof.app, which emails
 * it to support@shopproof.app and keeps no copy.
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    inject: ['shopproofRulesService'],

    data() {
        return {
            form: null,
            loadFailed: false,
            name: '',
            email: '',
            message: '',
            includeShop: true,
            sending: false,
            field: '',
            error: '',
            sent: false,
            reason: '',
            sentTo: '',
            copied: false,
            helpUrl: HELP_URL,
            privacyUrl: PRIVACY_URL,
        };
    },

    computed: {
        canCopy() {
            return typeof navigator !== 'undefined' && !!navigator.clipboard;
        },

        mailto() {
            const address = this.form ? this.form.address : 'support@shopproof.app';
            const subject = this.form && this.form.pro ? 'Shopproof Pro for Shopware: question' : 'Shopproof for Shopware: question';
            const versions = this.form ? this.form.versions.join('\n') : '';
            const body = `${this.message || ''}\n\n${versions}\n`;
            return `mailto:${address}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
        },
    },

    created() {
        this.load();
    },

    methods: {
        load() {
            return this.shopproofRulesService.getContactForm().then((form) => {
                this.form = form;
                this.name = form.name || '';
                this.email = form.email || '';
            }).catch(() => {
                this.loadFailed = true;
            });
        },

        validate() {
            const email = this.email.trim();
            const length = this.message.trim().length;
            if (!/^[^\s@<>",;]+@[^\s@<>",;]+\.[^\s@<>",;]{2,}$/.test(email)) {
                return { field: 'email', error: 'email' };
            }
            if (length < 10) {
                return { field: 'message', error: 'short' };
            }
            if (length > 4000) {
                return { field: 'message', error: 'long' };
            }
            return null;
        },

        send() {
            const problem = this.validate();
            this.sent = false;
            if (problem) {
                this.field = problem.field;
                this.error = problem.error;
                this.$nextTick(() => {
                    const el = this.$refs[problem.field];
                    if (el && el.focus) {
                        el.focus();
                    }
                });
                return Promise.resolve();
            }
            this.field = '';
            this.error = '';
            this.sending = true;
            return this.shopproofRulesService.sendContact({
                name: this.name,
                email: this.email.trim(),
                message: this.message,
                includeShop: this.includeShop,
            }).then(() => {
                this.sent = true;
                this.sentTo = this.email.trim();
                this.message = '';
            }).catch((err) => {
                const data = err && err.response && err.response.data ? err.response.data : {};
                this.field = typeof data.field === 'string' ? data.field : '';
                this.error = typeof data.error === 'string' && data.error !== '' ? data.error : 'send';
                this.reason = typeof data.reason === 'string' ? data.reason : '';
            }).finally(() => {
                this.sending = false;
            });
        },

        copy() {
            navigator.clipboard.writeText(this.form ? this.form.address : 'support@shopproof.app').then(() => {
                this.copied = true;
            }, () => {
                this.copied = false;
            });
        },
    },
});
