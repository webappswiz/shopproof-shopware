const { ApiService } = Shopware.Classes;

/**
 * The plugin's admin API: the rules overview, the withdrawal button setup check and the record of
 * withdrawal requests.
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
                params: { page, limit: 25, term },
            })
            .then((response) => ApiService.handleResponse(response));
    }

    deleteWithdrawal(id) {
        return this.httpClient.delete(`${this.getApiBasePath()}/withdrawals/${id}`, {
            headers: this.getBasicHeaders(),
        });
    }
}

Shopware.Service().register('shopproofRulesService', (container) => {
    const init = Shopware.Application.getContainer('init');
    return new ShopproofRulesService(init.httpClient, container.loginService);
});
