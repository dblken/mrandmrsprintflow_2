const assert = require('node:assert/strict');
const { readJsonResponse } = require('../public/assets/js/pos_api_response.js');

function response(status, body) {
    return { status, ok: status >= 200 && status < 300, text: async () => body };
}

(async () => {
    assert.deepEqual(await readJsonResponse(response(200, '{"success":true,"order_id":42}'), 'Set Price'), { success: true, order_id: 42 });

    for (const [res, expectedStatus] of [
        [response(403, '{"success":false,"message":"Invalid security token."}'), 403],
        [response(500, '<html>PHP Fatal error</html>'), 500],
        [response(200, 'null'), 200],
    ]) {
        await assert.rejects(readJsonResponse(res, 'Set Price'), error => {
            assert.equal(error.kind, 'api_response');
            assert.equal(error.status, expectedStatus);
            return true;
        });
    }

    console.log('POS API response handling tests passed: successful JSON, HTTP errors, invalid JSON, and unexpected JSON shapes.');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
