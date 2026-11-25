const { ApiService } = Shopware.Classes;

/**
 * The plugin's admin API: the rules overview, the withdrawal button setup check, the record of
 * withdrawal requests and the proof log.
 */
class ShopproofRulesService extends ApiService {
    constructor(httpClient, loginService) {
        super(httpClient, loginService, '_action/shopproof');
        this.name = 'shopproofRulesService';
    }

    getRules() {
        return this.httpClient
            .get(`${this.getApiBasePath()}/rules`, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    refreshRules() {
        return this.httpClient
            .post(`${this.getApiBasePath()}/rules/refresh`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    getGreenClaims() {
        return this.httpClient
            .get(`${this.getApiBasePath()}/green-claims`, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    fillEbayFile(csv) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/marketplace/ebay`, { csv }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    makeAmazonFiles() {
        return this.httpClient
            .post(`${this.getApiBasePath()}/marketplace/amazon`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    getWithdrawalCheck() {
        return this.httpClient
            .get(`${this.getApiBasePath()}/withdrawal-check`, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    getWithdrawals(page = 1, term = '') {
        return this.httpClient
            .get(`${this.getApiBasePath()}/withdrawals`, {
                headers: this.getBasicHeaders(),
                params: { page, limit: 25, term, timezone: ShopproofRulesService.timeZone() },
            })
            .then((response) => ApiService.handleResponse(response));
    }

    clearWithdrawalFailures() {
        return this.httpClient.delete(`${this.getApiBasePath()}/withdrawal-failures`, {
            headers: this.getBasicHeaders(),
        });
    }

    getProofLog(page = 1) {
        return this.httpClient
            .get(`${this.getApiBasePath()}/proof-log`, {
                headers: this.getBasicHeaders(),
                params: { page, limit: 25 },
            })
            .then((response) => ApiService.handleResponse(response));
    }

    verifyProofLog() {
        return this.httpClient
            .post(`${this.getApiBasePath()}/proof-log/verify`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    recordProofSnapshot() {
        return this.httpClient
            .post(`${this.getApiBasePath()}/proof-log/snapshot`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    downloadProofLog() {
        return this.httpClient
            .get(`${this.getApiBasePath()}/proof-log/csv`, { headers: this.getBasicHeaders(), responseType: 'blob' })
            .then((response) => response.data);
    }

    /** The admin user's time zone, so deadline days match the merchant's calendar. */
    static timeZone() {
        const user = Shopware.State?.get('session')?.currentUser;
        return user?.timeZone || Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    }

    setWithdrawalStatus(id, status, notify = true) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/withdrawals/${id}/status`, { status, notify }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    setWithdrawalDelivered(id, deliveredOn) {
        return this.httpClient.post(`${this.getApiBasePath()}/withdrawals/${id}/delivered`, { deliveredOn }, {
            headers: this.getBasicHeaders(),
        });
    }

    deleteWithdrawal(id) {
        return this.httpClient.delete(`${this.getApiBasePath()}/withdrawals/${id}`, {
            headers: this.getBasicHeaders(),
        });
    }

    getCancellations(page = 1) {
        return this.httpClient
            .get(`${this.getApiBasePath()}/cancellations`, {
                headers: this.getBasicHeaders(),
                params: { page, limit: 25 },
            })
            .then((response) => ApiService.handleResponse(response));
    }

    markCancellationDone(id) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/cancellations/${id}/done`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    deleteCancellation(id) {
        return this.httpClient.delete(`${this.getApiBasePath()}/cancellations/${id}`, {
            headers: this.getBasicHeaders(),
        });
    }

    getOverview(locale = '') {
        return this.httpClient
            .get(`${this.getApiBasePath()}/overview`, {
                headers: this.getBasicHeaders(),
                params: { timezone: ShopproofRulesService.timeZone(), locale },
            })
            .then((response) => ApiService.handleResponse(response));
    }

    applyRecommended() {
        return this.httpClient
            .post(`${this.getApiBasePath()}/recommended/apply`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    /** Ready-made answers: saves the ticked ones that are still offered. */
    applyAnswers(keys) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/answers/apply`, { keys }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    /** "Keep rules up to date": turns on the daily rule check and checks once now. */
    keepRulesCurrent() {
        return this.httpClient
            .post(`${this.getApiBasePath()}/rules/keep-current`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    /** The quiet email to the owner: on or off. */
    setQuietEmail(on) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/quiet-email`, { on: on === true }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    /** Suggested withdrawal exclusions: excludes the ticked ones that are still offered. */
    applyExclusionHints(keys) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/exclusion-hints/apply`, { keys }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    hideExclusionHints() {
        return this.httpClient
            .post(`${this.getApiBasePath()}/exclusion-hints/hide`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    undoRecommended() {
        return this.httpClient
            .post(`${this.getApiBasePath()}/recommended/undo`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    getPlacement() {
        return this.httpClient
            .get(`${this.getApiBasePath()}/placement`, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    savePlacementResult(counts) {
        return this.httpClient.post(`${this.getApiBasePath()}/placement/result`, counts, {
            headers: this.getBasicHeaders(),
        });
    }

    getReturns() {
        return this.httpClient
            .get(`${this.getApiBasePath()}/returns`, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    renewReturnsSecret() {
        return this.httpClient
            .post(`${this.getApiBasePath()}/returns/secret`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    /** Every withdrawal as a CSV file, headings in the admin's language (English or German). */
    downloadWithdrawals(lang) {
        return this.httpClient
            .get(`${this.getApiBasePath()}/withdrawals/csv`, {
                headers: this.getBasicHeaders(),
                params: { lang },
                responseType: 'blob',
            })
            .then((response) => response.data);
    }

    /** The shop's own details, offered as the default manufacturer for goods it makes itself. */
    getShopDetails() {
        return this.httpClient
            .get(`${this.getApiBasePath()}/safety/shop-details`, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    useShopDetails() {
        return this.httpClient
            .post(`${this.getApiBasePath()}/safety/use-shop-details`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    getContactForm() {
        return this.httpClient
            .get(`${this.getApiBasePath()}/contact`, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    sendContact(data) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/contact`, data, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    sendReturnsTest() {
        return this.httpClient
            .post(`${this.getApiBasePath()}/returns/test`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    getAssistant() {
        return this.httpClient
            .get(`${this.getApiBasePath()}/assistant`, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    saveAssistantSwitches(switches) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/assistant/switches`, switches, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    createAssistantKey() {
        return this.httpClient
            .post(`${this.getApiBasePath()}/assistant/keys`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    removeAssistantKey(id) {
        return this.httpClient
            .delete(`${this.getApiBasePath()}/assistant/keys/${encodeURIComponent(id)}`, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    undoAssistantChange(id) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/assistant/changes/${encodeURIComponent(id)}/undo`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }
}

Shopware.Service().register('shopproofRulesService', (container) => {
    const init = Shopware.Application.getContainer('init');
    return new ShopproofRulesService(init.httpClient, container.loginService);
});
