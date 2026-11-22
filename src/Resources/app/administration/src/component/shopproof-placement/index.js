import template from './shopproof-placement.html.twig';
import './shopproof-placement.scss';
import { createRunner } from '../../placement/placement-runner.mjs';
import { placementCounts, sweepFinished } from '../../placement/placement-counts.mjs';

/** Keys of the check sentences (PLACEMENT_TEXT in packages/widgets). */
const TEXT_KEYS = [
    'and', 'area.footer', 'area.header', 'area.main', 'area.account', 'area.other',
    'found.pass', 'found.fail', 'found.skip', 'visible.pass', 'visible.warn',
    'clear.pass', 'clear.warn', 'clear.fail', 'contrast.pass', 'contrast.warn', 'contrast.skip',
    'floating.pass', 'floating.warn', 'others.pass', 'others.warn', 'a11y.pass', 'a11y.info',
];

const ICON = { pass: '✓', warn: '!', fail: '✕', skip: '–', info: 'i' };


/**
 * Placement check (decision 0024): shows the shop's own pages in a frame at desktop and phone
 * width, outlines the withdrawal link and checks that shoppers can see and reach it and that
 * nothing floats over the theme's content. Runs in the merchant's browser on the shop's own
 * pages; only the pass, warn and fail counts are saved, in the shop's settings.
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    inject: ['shopproofRulesService'],

    emits: ['checked'],

    data() {
        return {
            loaded: false,
            failed: false,
            pages: [],
            labels: [],
            paths: [],
            current: '',
            width: 'desktop',
            state: null,
            runner: null,
            last: null,
            gone: false,
            otherOrigin: [],
        };
    },

    computed: {
        rows() {
            return this.state ? this.state.pages : [];
        },

        openUrl() {
            const page = this.pages.find((p) => p.key === this.current);
            return page ? page.url : '';
        },
    },

    created() {
        this.load();
    },

    beforeUnmount() {
        this.gone = true;
        window.removeEventListener('resize', this.fit);
        if (this.runner) {
            this.runner.destroy();
        }
    },

    methods: {
        /** Loads the shop's pages with fresh preview keys (they last 30 minutes), keeping the chosen page. */
        load() {
            const keep = this.current;
            return this.shopproofRulesService
                .getPlacement()
                .then((result) => {
                    if (this.gone) {
                        return;
                    }
                    const several = result.channels.length > 1;
                    this.pages = result.channels.flatMap((channel) => channel.pages.map((page) => ({
                        ...page,
                        key: `${channel.id}:${page.key}`,
                        label: several
                            ? `${channel.name}: ${this.$t(`shopproof.placement.page.${page.key}`)}`
                            : this.$t(`shopproof.placement.page.${page.key}`),
                    })));
                    this.labels = result.labels;
                    this.otherOrigin = result.channels
                        .filter((channel) => channel.sameOrigin === false)
                        .map((channel) => channel.name || channel.base);
                    this.paths = [...new Set(result.channels.flatMap((channel) => channel.paths))];
                    this.last = result.last;
                    this.loaded = true;
                    if (this.pages.length) {
                        this.current = this.pages.some((p) => p.key === keep) ? keep : this.pages[0].key;
                        this.$nextTick(() => this.start());
                    }
                })
                .catch(() => {
                    this.failed = true;
                });
        },

        /** Check sentences in the admin language. {areas}, {items} and {value} stay for the check to fill in. */
        texts() {
            const out = {};
            for (const key of TEXT_KEYS) {
                out[key] = this.$t(`shopproof.placement.text.${key}`, { areas: '{areas}', items: '{items}', value: '{value}' });
            }
            return out;
        },

        start() {
            const stage = this.$refs.stage;
            if (!stage || this.runner) {
                return;
            }
            const key = (url) => {
                try {
                    return new URL(url).searchParams.get('shopproofPreview');
                } catch {
                    return null;
                }
            };
            this.runner = createRunner({
                stage,
                pages: this.pages,
                labels: this.labels,
                paths: this.paths,
                text: this.texts(),
                title: this.$t('shopproof.placement.frameTitle'),
                load: (frame, page) => {
                    frame.src = page.url;
                },
                // A click in the preview opens the next page of the same shop with the preview key,
                // because Shopware does not let any other page into a frame.
                onNavigate: (href) => {
                    const page = this.pages.find((p) => p.key === this.current);
                    const preview = page ? key(page.url) : null;
                    let next;
                    try {
                        next = new URL(href);
                    } catch {
                        return;
                    }
                    if (!page || !preview || next.origin !== new URL(page.url).origin) {
                        return;
                    }
                    next.searchParams.set('shopproofPreview', preview);
                    this.runner.show({ ...page, key: `${page.key}:link`, url: next.toString(), expectLink: true });
                },
                onUpdate: (state) => {
                    this.state = state;
                    this.$nextTick(this.fit);
                },
            });
            window.addEventListener('resize', this.fit);
            this.runner.show(this.current);
            this.sweep();
        },

        sweep() {
            if (!this.runner || this.runner.state.sweeping) {
                return Promise.resolve();
            }
            const runner = this.runner;
            return runner.sweep().then(() => {
                // Leaving the page or starting again ends a sweep early; a part result would
                // replace the last full one, so only a finished sweep is stored.
                if (!this.gone && this.runner === runner && sweepFinished(runner.state.pages)) {
                    return this.report(runner.state.pages);
                }
                return undefined;
            });
        },

        /** After a full check only the counts are stored in the shop, for the overview. */
        report(pages) {
            const counts = placementCounts(pages);
            return this.shopproofRulesService
                .savePlacementResult(counts)
                .then(() => {
                    this.last = { ...counts, time: Math.floor(Date.now() / 1000) };
                    this.$emit('checked', this.last);
                })
                .catch(() => {});
        },

        /** A new check with fresh preview keys, so pages opened more than 30 minutes ago still open. */
        again() {
            if (this.runner && this.runner.state.sweeping) {
                return Promise.resolve();
            }
            if (this.runner) {
                this.runner.destroy();
                this.runner = null;
            }
            this.state = null;
            return this.load();
        },

        choose(key) {
            this.current = key;
            if (this.runner) {
                this.runner.show(key);
            }
        },

        setWidth(width) {
            this.width = width;
            if (this.runner) {
                this.runner.setWidth(width).then(this.fit);
            }
        },

        /** The preview shows the page at its real width, scaled down to fit the card. */
        fit() {
            const stage = this.$refs.stage;
            const frame = stage ? stage.querySelector('iframe') : null;
            if (!frame) {
                return;
            }
            const w = Number.parseInt(frame.style.width, 10) || 1280;
            const h = Number.parseInt(frame.style.height, 10) || 800;
            const scale = Math.min(1, stage.clientWidth / w);
            frame.style.transform = `scale(${scale})`;
            frame.style.transformOrigin = '0 0';
            stage.style.height = `${Math.ceil(h * scale)}px`;
        },

        icon(status) {
            return ICON[status] || '';
        },

        cell(row, width) {
            const result = row[width];
            if (!result) {
                const key = { checking: 'checking', blocked: 'blockedShort', error: 'blockedShort' }[row.status];
                return key ? this.$t(`shopproof.placement.${key}`) : '';
            }
            const where = result.areas.map((area) => this.$t(`shopproof.placement.text.area.${area}`)).join(', ');
            return where || this.$t(row.expectLink === false ? 'shopproof.placement.notNeeded' : 'shopproof.placement.missing');
        },

        cellTitle(row, width) {
            const result = row[width];
            return result
                ? result.messages.filter((m) => m.status === 'warn' || m.status === 'fail').map((m) => m.message).join('\n')
                : '';
        },

        lastText() {
            if (!this.last) {
                return '';
            }
            return this.$t('shopproof.placement.last', {
                date: Shopware.Utils.format.date(new Date(this.last.time * 1000).toISOString()),
                pass: this.last.pass,
                warn: this.last.warn,
                fail: this.last.fail,
                skipped: this.last.skipped,
            });
        },
    },
});
