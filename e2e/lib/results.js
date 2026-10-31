'use strict';

/**
 * Turns a Playwright JSON report into the results file the PHP runner reads:
 * `{"checks": [{"label", "status": pass|fail|skip, "detail"}], "pages": n}`.
 *
 * @module results
 */

const ANSI = /\u001b\[[0-9;]*m/g;

/**
 * Flatten the suites of a Playwright JSON report into tests.
 *
 * @param {object[]} suites Report suites.
 * @returns {{title: string, project: string, status: string, error: string}[]}
 */
function flattenTests(suites) {
  const tests = [];

  const walk = (suite) => {
    (suite.specs || []).forEach((spec) => {
      spec.tests.forEach((t) => {
        const last = t.results[t.results.length - 1] || {};
        tests.push({
          title: spec.title,
          project: t.projectName,
          status: t.status === 'skipped' ? 'skip' : (last.status === 'passed' ? 'pass' : 'fail'),
          error: last.error && last.error.message ? last.error.message.replace(ANSI, '').trim().slice(0, 600) : '',
        });
      });
    });
    (suite.suites || []).forEach(walk);
  };

  suites.forEach(walk);

  return tests;
}

/**
 * Build the checks: one summary check for the crawl (plus one failing check
 * per bad page) and one check per flow test.
 *
 * @param {object} report Playwright JSON report.
 * @returns {{checks: object[], pages: number}}
 */
function buildResults(report) {
  const tests = flattenTests(report.suites || []);
  const crawl = tests.filter((t) => t.project === 'crawl');
  const checks = [];

  if (crawl.length) {
    const bad = crawl.filter((t) => t.status === 'fail');
    checks.push({
      label: `Admin crawl: ${crawl.length} page(s)`,
      status: bad.length ? 'fail' : 'pass',
      detail: bad.length ? `${bad.length} page(s) with problems` : '',
    });
    bad.forEach((t) => checks.push({ label: t.title, status: 'fail', detail: t.error }));
  }

  tests.filter((t) => t.project !== 'crawl').forEach((t) => checks.push({ label: t.title, status: t.status, detail: t.error }));

  (report.errors || []).forEach((e) => checks.push({ label: 'Playwright', status: 'fail', detail: String(e.message || e).replace(ANSI, '').slice(0, 600) }));

  return { checks, pages: crawl.length };
}

module.exports = { buildResults, flattenTests };
