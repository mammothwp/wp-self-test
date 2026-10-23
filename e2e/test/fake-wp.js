'use strict';

/**
 * A tiny stand-in for a WordPress site, enough for the crawler: token login,
 * rest-nonce, the admin-menu inventory and a handful of admin pages with
 * known problems. Used by the integration test only.
 */

const http = require('http');

const PAGES = {
  'clean.php': '<h1>Clean</h1>',
  'warn.php': '<h1>Warn</h1><br /><b>Warning</b>:  Undefined variable $x in <b>/srv/plugin.php</b> on line <b>7</b><br />',
  'console.php': '<h1>Console</h1><script>console.error("boom from plugin")</script>',
  'noisy.php': '<h1>Noisy</h1><script>console.error("known noise")</script>',
  'rest.php': '<h1>Rest</h1><script>fetch("/wp-json/demo/v1/missing")</script>',
};

/**
 * Start the fake site.
 *
 * @param {{token: string, extraPages?: string[]}} options Accepted token and extra inventory paths.
 * @returns {Promise<{url: string, close: Function, logins: number}>}
 */
function start(options) {
  const state = { logins: 0 };
  const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    const pages = Object.assign({}, PAGES, options.pages || {});
    const authed = /mmwp_session=ok/.test(req.headers.cookie || '');
    const send = (status, body, type = 'text/html', headers = {}) => {
      res.writeHead(status, Object.assign({ 'Content-Type': type }, headers));
      res.end(body);
    };

    if (url.pathname === '/wp-json/mmwp/v1/self-test/browser-login' && req.method === 'POST') {
      let body = '';
      req.on('data', (chunk) => { body += chunk; });
      req.on('end', () => {
        const token = new URLSearchParams(body).get('token');
        if (token !== options.token || state.logins > 0) {
          return send(403, '{"code":"mmwp_self_test_login_refused"}', 'application/json');
        }
        state.logins += 1;
        return send(200, '{"ok":true}', 'application/json', { 'Set-Cookie': 'mmwp_session=ok; Path=/' });
      });
      return;
    }

    if (url.pathname === '/wp-login.php') {
      if (req.method === 'GET') {
        return send(200, '<form></form>', 'text/html', { 'Set-Cookie': 'wordpress_test_cookie=WP%20Cookie%20check; Path=/' });
      }
      let body = '';
      req.on('data', (chunk) => { body += chunk; });
      req.on('end', () => {
        const form = new URLSearchParams(body);
        if (form.get('log') === 'admin' && form.get('pwd') === 'secret') {
          return send(302, '', 'text/html', { Location: '/wp-admin/', 'Set-Cookie': ['wordpress_logged_in_x=1; Path=/', 'mmwp_session=ok; Path=/'] });
        }
        return send(200, 'Error: incorrect password');
      });
      return;
    }

    if (url.pathname === '/') {
      return send(200, options.front || '<html><body><h1>Front</h1></body></html>');
    }

    if (url.pathname === '/api/boom') {
      return send(500, 'boom', 'text/plain');
    }

    if (url.pathname === '/wp-json/demo/v1/missing') {
      return send(404, '{}', 'application/json');
    }

    if (!authed) {
      return send(401, 'login required');
    }

    if (url.pathname === '/wp-admin/admin-ajax.php') {
      return send(200, 'nonce123', 'text/plain');
    }

    if (url.pathname === '/wp-json/mmwp/v1/self-test/admin-menu') {
      if (options.noRest) {
        return send(404, '{"code":"rest_no_route"}', 'application/json');
      }
      if (req.headers['x-wp-nonce'] !== 'nonce123') {
        return send(401, '{}', 'application/json');
      }
      const paths = Object.keys(pages).concat(options.extraPages || []);
      const inventory = paths.map((path) => ({ title: path, path, url: `${server.url}/wp-admin/${path}`, parent: 'index.php' }));
      return send(200, JSON.stringify({ admin_url: `${server.url}/wp-admin/`, pages: inventory, excluded: [], source: 'snapshot' }), 'application/json');
    }

    const page = pages[url.pathname.replace('/wp-admin/', '')];
    if (page) {
      return send(200, `<html><body>${page}</body></html>`);
    }
    if (url.pathname === '/wp-admin/index.php') {
      const links = Object.keys(pages).map((path) => `<li><a href="${path}" class="menu-top"><div class="wp-menu-name">${path}</div></a></li>`).join('');
      const extras = '<li><a href="plugins.php?action=activate&#038;_wpnonce=1">Activate</a></li><li><a href="https://example.org/docs">Docs</a></li>';
      return send(200, `<html><body><ul id="adminmenu">${links}${extras}<li id="collapse-menu"><button>Collapse</button></li></ul></body></html>`);
    }

    return send(404, 'not found');
  });

  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      server.url = `http://127.0.0.1:${server.address().port}`;
      resolve({ url: server.url, close: () => server.close(), state });
    });
  });
}

module.exports = { start };
