import enGB from '../../snippet/en-GB.json';
import deDE from '../../snippet/de-DE.json';

Shopware.Locale.extend('en-GB', enGB);
Shopware.Locale.extend('de-DE', deDE);

Shopware.Component.register('shopproof-overview-index', () => import('./page/shopproof-overview-index'));

Shopware.Module.register('shopproof-overview', {
    type: 'plugin',
    name: 'shopproof-overview',
    title: 'shopproof.general.title',
    description: 'shopproof.general.description',
    color: '#2340C8',
    icon: 'regular-shield',

    routes: {
        index: {
            component: 'shopproof-overview-index',
            path: 'index',
            meta: {
                parentPath: 'sw.settings.index.plugins',
                privilege: 'system.system_config',
            },
        },
    },

    settingsItem: {
        group: 'plugins',
        to: 'shopproof.overview.index',
        icon: 'regular-shield',
        privilege: 'system.system_config',
    },
});
