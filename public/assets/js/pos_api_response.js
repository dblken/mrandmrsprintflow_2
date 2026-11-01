(function (root) {
    'use strict';

    async function readJsonResponse(response, label = 'Request') {
        const status = Number(response && response.status) || 0;
        let body;
        try {
            body = await response.text();
        } catch (cause) {
            const error = new Error(`${label} failed: could not read the server response (HTTP ${status || 'unknown'}).`);
            error.kind = 'api_response';
            error.status = status;
            throw error;
        }

        let payload;
        try {
            payload = JSON.parse(body);
        } catch (cause) {
            const error = new Error(`${label} failed: the server returned an invalid JSON response (HTTP ${status || 'unknown'}).`);
            error.kind = 'api_response';
            error.status = status;
            throw error;
        }

        if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
            const error = new Error(`${label} failed: the server returned an unexpected response (HTTP ${status || 'unknown'}).`);
            error.kind = 'api_response';
            error.status = status;
            throw error;
        }
        if (!response.ok || payload.success === false) {
            const error = new Error(String(payload.message || `${label} failed (HTTP ${status || 'unknown'}).`));
            error.kind = 'api_response';
            error.status = status;
            error.payload = payload;
            throw error;
        }
        return payload;
    }

    const api = { readJsonResponse };
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.PrintFlowPOSApiResponse = api;
})(typeof globalThis !== 'undefined' ? globalThis : this);
