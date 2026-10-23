'use strict';

/**
 * JUnit XML for CI systems, from the same checks the PHP runner reads.
 *
 * @module junit
 */

/**
 * Escape text for XML.
 *
 * @param {string} value Text.
 * @returns {string}
 */
function esc(value) {
  return String(value).replace(/[<>&"']/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&apos;' }[c])).replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g, '');
}

/**
 * Build a JUnit report.
 *
 * @param {{label: string, status: string, detail?: string}[]} checks Checks.
 * @param {string} name Suite name.
 * @param {number} [durationMs] Duration.
 * @returns {string}
 */
function toJunit(checks, name, durationMs = 0) {
  const failures = checks.filter((c) => c.status === 'fail').length;
  const skipped = checks.filter((c) => c.status === 'skip').length;
  const cases = checks.map((c) => {
    const detail = esc(c.detail || '');
    const inner = c.status === 'fail' ? `<failure message="${detail.split('\n')[0]}">${detail}</failure>` : (c.status === 'skip' ? `<skipped message="${detail}"/>` : '');

    return `    <testcase classname="${esc(name)}" name="${esc(c.label)}">${inner}</testcase>`;
  });

  return `<?xml version="1.0" encoding="UTF-8"?>\n<testsuites>\n  <testsuite name="${esc(name)}" tests="${checks.length}" failures="${failures}" skipped="${skipped}" time="${(durationMs / 1000).toFixed(3)}">\n${cases.join('\n')}\n  </testsuite>\n</testsuites>\n`;
}

module.exports = { toJunit };
