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

    sendReturnsTest() {
        return this.httpClient
            .post(`${this.getApiBasePath()}/returns/test`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }
}

Shopware.Service().register('shopproofRulesService', (container) => {
    const init = Shopware.Application.getContainer('init');
    return new ShopproofRulesService(init.httpClient, container.loginService);
});
