/**
 * "Shopproof product information" for the page designer: shows the durability label, product
 * safety information and the EU legal guarantee notice on product page layouts that leave out the
 * standard buy box or description, or wherever the merchant wants them. The storefront shows each
 * block only once per page.
 */
const preview = `
<div class="shopproof-cms-preview">
    <strong>Shopproof</strong>
    <span>{{ $t('shopproof.cms.previewText') }}</span>
</div>`;

Shopware.Component.register('sw-cms-el-preview-shopproof-product-info', { template: preview });
Shopware.Component.register('sw-cms-preview-shopproof-product-info', { template: preview });

Shopware.Component.register('sw-cms-el-shopproof-product-info', {
    template: `
<div class="shopproof-cms-element">
    <strong>{{ $t('shopproof.cms.label') }}</strong>
    <p>{{ $t('shopproof.cms.elementText') }}</p>
</div>`,
    mixins: [Shopware.Mixin.getByName('cms-element')],
    created() {
        this.initElementConfig('shopproof-product-info');
    },
});

Shopware.Component.register('sw-cms-el-config-shopproof-product-info', {
    template: `<p class="shopproof-cms-config">{{ $t('shopproof.cms.configText') }}</p>`,
    mixins: [Shopware.Mixin.getByName('cms-element')],
    created() {
        this.initElementConfig('shopproof-product-info');
    },
});

Shopware.Component.register('sw-cms-block-shopproof-product-info', {
    template: `<div class="sw-cms-block-shopproof-product-info"><slot name="content"></slot></div>`,
});

Shopware.Service('cmsService').registerCmsElement({
    name: 'shopproof-product-info',
    label: 'shopproof.cms.label',
    component: 'sw-cms-el-shopproof-product-info',
    configComponent: 'sw-cms-el-config-shopproof-product-info',
    previewComponent: 'sw-cms-el-preview-shopproof-product-info',
    defaultConfig: {},
});

Shopware.Service('cmsService').registerCmsBlock({
    name: 'shopproof-product-info',
    label: 'shopproof.cms.label',
    category: 'commerce',
    component: 'sw-cms-block-shopproof-product-info',
    previewComponent: 'sw-cms-preview-shopproof-product-info',
    defaultConfig: {
        marginBottom: '20px',
        marginTop: '20px',
        marginLeft: null,
        marginRight: null,
        sizingMode: 'boxed',
    },
    slots: {
        content: 'shopproof-product-info',
    },
});
