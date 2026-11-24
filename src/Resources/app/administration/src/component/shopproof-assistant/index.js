import template from './shopproof-assistant.html.twig';
import './shopproof-assistant.scss';

/**
 * The "AI assistant" card on the Shopproof page (decision 0047), as the WooCommerce plugin's AI
 * assistant tab: three switches (connect, allow changes, include customer details), connection
 * keys shown once, and the list of changes made through an assistant, each with an Undo.
 * The assistant talks to this shop directly; nothing is sent to Shopproof.
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    inject: ['shopproofRulesService'],

    data() {
        return {
            state: null,
            loadFailed: false,
            on: false,
            changes: false,
            details: false,
            saving: false,
            saved: false,
            error: '',
            newKey: null,
            copied: false,
            undoMessage: '',
        };
    },

    computed: {
        canCopy() {
            return typeof navigator !== 'undefined' && !!navigator.clipboard;
        },
    },

    created() {
        this.load();
    },

    methods: {
        load() {
            return this.shopproofRulesService.getAssistant().then((state) => {
                this.state = state;
                this.on = state.switches.on;
                this.changes = state.switches.changes;
                this.details = state.switches.details;
            }).catch(() => {
                this.loadFailed = true;
            });
        },

        save() {
            this.saving = true;
            this.saved = false;
            this.error = '';
            return this.shopproofRulesService.saveAssistantSwitches({
                on: this.on,
                changes: this.on && this.changes,
                details: this.on && this.details,
            }).then(() => {
                this.saved = true;
                if (!this.on) {
                    this.newKey = null;
                }
                return this.load();
            }).catch(() => {
                this.error = 'save';
            }).finally(() => {
                this.saving = false;
            });
        },

        createKey() {
            this.error = '';
            this.copied = false;
            return this.shopproofRulesService.createAssistantKey().then((made) => {
                this.newKey = made;
                return this.load();
            }).catch(() => {
                this.error = 'key';
            });
        },

        removeKey(id) {
            this.error = '';
            return this.shopproofRulesService.removeAssistantKey(id).then(() => this.load()).catch(() => {
                this.error = 'remove';
            });
        },

        undo(id) {
            this.undoMessage = '';
            return this.shopproofRulesService.undoAssistantChange(id).then((done) => {
                const notes = done && done.data && Array.isArray(done.data.notes) ? done.data.notes : [];
                this.undoMessage = notes.length > 0 ? this.$t('shopproof.assistant.undoneKept') : this.$t('shopproof.assistant.undone');
                return this.load();
            }).catch(() => {
                this.undoMessage = this.$t('shopproof.assistant.undoFailed');
            });
        },

        copyConfig() {
            if (!this.newKey) {
                return Promise.resolve();
            }
            return navigator.clipboard.writeText(this.newKey.clientConfig).then(() => {
                this.copied = true;
            }, () => {
                this.copied = false;
            });
        },

        day(iso) {
            if (!iso) {
                return '';
            }
            const date = new Date(iso);
            return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString();
        },

        dayTime(iso) {
            if (!iso) {
                return '';
            }
            const date = new Date(iso);
            return Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
        },

        lines(change) {
            const lines = change && change.data && Array.isArray(change.data.lines) ? change.data.lines : [];
            return lines.filter((line) => line && typeof line.text === 'string').map((line) => line.text);
        },
    },
});
