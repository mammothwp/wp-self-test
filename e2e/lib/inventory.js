'use strict';

/**
 * Page inventory helpers that need no PHP: scraping the admin menu HTML
 * (used when the site has no `admin-menu` route), the plugin filter and the
 * front page entry. Pure functions.
 *
 * @module inventory
 */

/** Substrings that keep an admin URL out of the crawl (mirror of Menu_Inventory::DEFAULT_EXCLUSIONS). */
const DEFAULT_EXCLUSIONS = ['action=', 'nonce', 'customize.php', 'update.php', 'update-core.php', 'plugin-install.php', 'theme-install.php', 'post-new.php', 'logout'];

/**
 * Whether a path contains an exclusion needle (case-insensitive).
 *
 * @param {string}   path       Admin path.
 * @param {string[]} exclusions Needles.
 * @returns {boolean}
 */
function isExcluded(path, exclusions) {
  const lower = path.toLowerCase();

  return exclusions.some((needle) => needle !== '' && lower.includes(needle.toLowerCase()));
}

/**
 * Pages linked from the `#adminmenu` list of an admin page.
 *
 * @param {string}   html       HTML of any wp-admin page.
 * @param {string}   adminUrl   Admin URL with trailing slash.
 * @param {string[]} exclusions Exclusion needles.
 * @returns {{title: string, path: string, url: string, parent: string}[]}
 */
function pagesFromAdminMenuHtml(html, adminUrl, exclusions) {
  const start = html.indexOf('id="adminmenu"');
  const end = start === -1 ? -1 : html.indexOf('id="collapse-menu"', start);
  const menu = start === -1 ? '' : html.slice(start, end === -1 ? undefined : end);
  const seen = new Map();
  const link = /<a\b[^>]*\bhref=["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi;
  let match;

  while ((match = link.exec(menu)) !== null) {
    const url = new URL(match[1].replace(/&#038;|&amp;/g, '&').replace(/#.*$/, ''), adminUrl).href;
    const path = url.startsWith(adminUrl) ? url.slice(adminUrl.length) : '';

    if (path === '' || isExcluded(path, exclusions) || seen.has(path)) {
      continue;
    }

    seen.set(path, { title: match[2].replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim(), path, url, parent: '' });
  }

  return [...seen.values()];
}

/**
 * Keep the pages that belong to a plugin: its slug appears in the URL or in
 * the parent menu slug (case-insensitive, `-` and `_` interchangeable).
 *
 * @param {{path: string, parent?: string}[]} pages Inventory pages.
 * @param {string}                            slug  Plugin slug.
 * @returns {object[]}
 */
function filterByPlugin(pages, slug) {
  if (!slug) {
    return pages;
  }

  const norm = (value) => decodeURIComponent(String(value || '')).toLowerCase().replace(/_/g, '-');
  const needle = norm(slug);

  return pages.filter((page) => norm(page.path).includes(needle) || norm(page.parent).includes(needle));
}

/**
 * The site's front page, crawled once with the admin pages.
 *
 * @param {string} siteUrl Site URL.
 * @returns {{title: string, path: string, url: string, parent: string}}
 */
function frontPage(siteUrl) {
  return { title: 'Front page', path: '(front page)', url: siteUrl.replace(/\/?$/, '/'), parent: '' };
}

module.exports = { DEFAULT_EXCLUSIONS, isExcluded, pagesFromAdminMenuHtml, filterByPlugin, frontPage };
