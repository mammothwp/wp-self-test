'use strict';

/**
 * REST helpers for the Node side: permalink-independent URLs and defensive
 * response parsing. Sites in plugin CI use plain permalinks (no `/wp-json/`)
 * and may not have the self-test routes at all.
 *
 * @module rest
 */

const ROUTE_BASE = '/mmwp/v1/self-test/';

/**
 * URL of a self-test REST route. Uses `?rest_route=` (works with any
 * permalink setting) unless `config.rest_url` is an explicit non-`/wp-json/`
 * base (the CLI's `--rest-url`, or a `?rest_route=` URL from rest_url()).
 *
 * @param {{site_url: string, rest_url?: string}} config Config.
 * @param {string} route Route below mmwp/v1/self-test/, e.g. `admin-menu`.
 * @returns {string}
 */
function restEndpoint(config, route) {
  const custom = config.rest_url || '';

  if (custom !== '' && !/\/wp-json\//.test(custom)) {
    return custom.replace(/\/?$/, '/') + route;
  }

  return `${config.site_url.replace(/\/+$/, '')}/?rest_route=${ROUTE_BASE}${route}`;
}

/**
 * Describe a response for error messages: URL, status, content-type, body start.
 *
 * @param {{url: Function, status: Function, headers: Function}} response Playwright APIResponse.
 * @param {string} body Response body.
 * @returns {string}
 */
function describeResponse(response, body) {
  const type = response.headers()['content-type'] || 'none';

  return `${response.url()} (HTTP ${response.status()}, content-type: ${type}): ${String(body).replace(/\s+/g, ' ').slice(0, 120)}`;
}

/**
 * Read a response as JSON without throwing on HTML or empty bodies.
 *
 * @param {object} response Playwright APIResponse.
 * @returns {Promise<{json: ?object, body: string, isJson: boolean, missing: boolean, description: string}>}
 *   `missing` is true for a 404 or a `rest_no_route` error (the route does not exist).
 */
async function readJson(response) {
  const body = await response.text();
  const type = response.headers()['content-type'] || '';
  let json = null;

  if (/json/i.test(type)) {
    try {
      json = JSON.parse(body);
    } catch (error) {
      json = null;
    }
  }

  const missing = response.status() === 404 || (json !== null && json.code === 'rest_no_route');

  return { json, body, isJson: json !== null, missing, description: describeResponse(response, body) };
}

module.exports = { restEndpoint, describeResponse, readJson };
