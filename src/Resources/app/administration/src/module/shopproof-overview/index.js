import enGB from '../../snippet/en-GB.json';
import deDE from '../../snippet/de-DE.json';
import frFR from '../../snippet/fr-FR.json';
import itIT from '../../snippet/it-IT.json';
import nlNL from '../../snippet/nl-NL.json';
import esES from '../../snippet/es-ES.json';
import plPL from '../../snippet/pl-PL.json';

// English and German always exist. The other admin languages exist once Shopware's language
// pack (or the merchant) adds them; register the locale if nobody has yet, so the texts are
// there whatever loads first (decision 0044).
const snippets = { 'en-GB': enGB, 'de-DE': deDE, 'fr-FR': frFR, 'it-IT': itIT, 'nl-NL': nlNL, 'es-ES': esES, 'pl-PL': plPL };
Object.entries(snippets).forEach(([locale, messages]) => {
    if (Shopware.Locale.getByName(locale)) {
        Shopware.Locale.extend(locale, messages);
    } else {
        Shopware.Locale.register(locale, messages);
    }
});

Shopware.Component.register('shopproof-overview-index', () => import('./page/shopproof-overview-index'));
Shopware.Component.register('shopproof-setup-overview', () => import('../../component/shopproof-setup-overview'));
Shopware.Component.register('shopproof-placement', () => import('../../component/shopproof-placement'));
Shopware.Component.register('shopproof-contact', () => import('../../component/shopproof-contact'));
Shopware.Component.register('shopproof-assistant', () => import('../../component/shopproof-assistant'));
Shopware.Component.register('shopproof-green-claims', () => import('../../component/shopproof-green-claims'));
Shopware.Component.register('shopproof-marketplaces', () => import('../../component/shopproof-marketplaces'));
Shopware.Component.register('shopproof-a11y-statement', () => import('../../component/shopproof-a11y-statement'));

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
