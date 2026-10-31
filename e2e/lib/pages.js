'use strict';

/**
 * Parsing, validation and variable substitution of `browser-pages.json`, the
 * explicit page list of `mmwp-e2e pages`. Pure functions: no browser, no
 * network.
 *
 * @module pages
 */

const AREAS = ['admin', 'frontend'];
const ACTORS = ['admin', 'guest'];
const PAGE_KEYS = ['area', 'name', 'path', 'as', 'expect', 'expect_url', 'expect_text', 'status', 'note'];
const FILE_KEYS = ['version', 'pages', 'setup', 'about'];
const VAR = /\$\{([A-Za-z_][A-Za-z0-9_]*)\}/g;

/**
 * Warnings for keys of an object that are not in the allowed list.
 *
 * @param {object}   object  Object to inspect.
 * @param {string[]} allowed Known keys.
 * @param {string}   where   Location used in the message.
 * @returns {string[]}
 */
function unknownKeys(object, allowed, where) {
  return Object.keys(object).filter((k) => !allowed.includes(k)).map((k) => `${where}: unknown key "${k}" ignored.`);
}

/**
 * Validate one page entry.
 *
 * @param {*}      raw   Entry from the file.
 * @param {number} index Position in `pages`.
 * @returns {{page: object|null, errors: string[], warnings: string[]}}
 */
function validatePage(raw, index) {
  const where = `pages[${index}]`;
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
    return { page: null, errors: [`${where}: must be an object.`], warnings: [] };
  }

  const errors = [];
  const label = typeof raw.name === 'string' && raw.name ? `${where} "${raw.name}"` : where;
  const text = (key) => raw[key] === undefined || typeof raw[key] === 'string';

  if (typeof raw.path !== 'string' || !raw.path.startsWith('/')) {
    errors.push(`${label}: "path" is required and must start with "/".`);
  }
  if (!ACTORS.includes(raw.as)) {
    errors.push(`${label}: "as" is required and must be one of ${ACTORS.join('|')}.`);
  }
  if (raw.area !== undefined && !AREAS.includes(raw.area)) {
    errors.push(`${label}: "area" must be one of ${AREAS.join('|')}.`);
  }
  if (!['name', 'expect', 'expect_url', 'expect_text'].every(text)) {
    errors.push(`${label}: "name", "expect", "expect_url" and "expect_text" must be strings.`);
  }
  if (raw.status !== undefined && !(Number.isInteger(raw.status) && raw.status >= 100 && raw.status <= 599)) {
    errors.push(`${label}: "status" must be an HTTP status code.`);
  }
  if (errors.length) {
    return { page: null, errors, warnings: unknownKeys(raw, PAGE_KEYS, label) };
  }

  const area = raw.area || (raw.path.startsWith('/wp-admin') ? 'admin' : 'frontend');
  const page = {
    area, name: raw.name || raw.path, path: raw.path, as: raw.as, expect: raw.expect || '', expect_url: raw.expect_url || '', expect_text: raw.expect_text || '', status: raw.status === undefined ? 200 : raw.status,
  };

  return { page, errors, warnings: unknownKeys(raw, PAGE_KEYS, label) };
}

/**
 * Parse and validate the contents of a pages file.
 *
 * @param {string} json File contents.
 * @returns {{pages: object[], errors: string[], warnings: string[]}} Errors are fatal.
 */
function parsePagesFile(json) {
  let data;
  try {
    data = JSON.parse(json);
  } catch (error) {
    return { pages: [], errors: [`Invalid JSON: ${error.message}`], warnings: [] };
  }

  if (!data || typeof data !== 'object' || Array.isArray(data)) {
    return { pages: [], errors: ['The file must contain a JSON object.'], warnings: [] };
  }
  if (data.version !== 1) {
    return { pages: [], errors: [`Unsupported "version" ${JSON.stringify(data.version)}; expected 1.`], warnings: [] };
  }
  if (!Array.isArray(data.pages) || !data.pages.length) {
    return { pages: [], errors: ['"pages" must be a non-empty array.'], warnings: [] };
  }

  const warnings = unknownKeys(data, FILE_KEYS, 'file');
  const errors = [];
  const pages = [];

  data.pages.forEach((raw, index) => {
    const result = validatePage(raw, index);
    errors.push(...result.errors);
    warnings.push(...result.warnings);
    if (result.page) {
      pages.push(result.page);
    }
  });

  return { pages: errors.length ? [] : pages, errors, warnings };
}

/**
 * Replace `${VAR}` in a string.
 *
 * @param {string} value Text.
 * @param {Object<string, *>} vars Resolved variables.
 * @returns {{value: string, missing: string[]}}
 */
function substitute(value, vars) {
  const missing = [];
  const out = String(value).replace(VAR, (match, name) => {
    if (!Object.prototype.hasOwnProperty.call(vars, name) || vars[name] === null || vars[name] === undefined) {
      missing.push(name);
      return match;
    }

    return String(vars[name]);
  });

  return { value: out, missing };
}

/**
 * Resolve the variables in path, expect, expect_url and expect_text of a page.
 *
 * @param {object} page Validated page.
 * @param {Object<string, *>} vars Resolved variables.
 * @returns {{page: object, error: string}} `error` is set when a variable is unknown.
 */
function resolvePage(page, vars) {
  const resolved = { ...page };
  const missing = [];

  ['path', 'expect', 'expect_url', 'expect_text'].forEach((key) => {
    const result = substitute(page[key], vars);
    resolved[key] = result.value;
    missing.push(...result.missing);
  });

  const unique = [...new Set(missing)];

  return { page: resolved, error: unique.length ? `Unknown variable(s) ${unique.map((n) => `\${${n}}`).join(', ')}: not in --vars.` : '' };
}

/**
 * Parse the contents of a `--vars` file.
 *
 * @param {string} json File contents; empty means no variables.
 * @returns {{vars: Object<string, *>, errors: string[]}}
 */
function parseVars(json) {
  if (!json) {
    return { vars: {}, errors: [] };
  }
  try {
    const vars = JSON.parse(json);
    if (!vars || typeof vars !== 'object' || Array.isArray(vars)) {
      return { vars: {}, errors: ['--vars must contain a JSON object.'] };
    }

    return { vars, errors: [] };
  } catch (error) {
    return { vars: {}, errors: [`--vars is not valid JSON: ${error.message}`] };
  }
}

module.exports = { parsePagesFile, parseVars, resolvePage, substitute, validatePage };
