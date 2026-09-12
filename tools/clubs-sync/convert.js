'use strict';

/**
 * Convert the upstream homebrew-clubs-list `clubs.js` into a clean,
 * versioned JSON artifact that the BCOE&M Laravel app can consume.
 *
 * Why this is a separate Node tool (issue #22, Part A): the upstream file is
 * hand-maintained JavaScript in a repo we do not control. Parsing it inside
 * the PHP sync job would make every future upstream edit a potential silent
 * production failure. This pipeline turns arbitrary JS into JSON once, in
 * isolation, and publishes a stable artifact that Part B only has to trust.
 *
 * The source declares `const CLUBS = [...]`. A top-level `const`/`let` in a
 * `vm` context is NOT a property of the context object, so the obvious
 * `sandbox.CLUBS` reads `undefined`. We resolve the binding with a second
 * evaluation in the same context instead — that works for `const`, `let`
 * and `var`, so the tool does not silently break if upstream changes style.
 *
 * Normalization (documented divergence from the issue's literal validation
 * rules, agreed with the maintainer): the real upstream list currently has
 * duplicate entries and trailing whitespace. Rather than refuse to publish
 * at all, entries are trimmed and case-insensitive duplicates are collapsed
 * (first occurrence wins). The published artifact is clean by construction.
 * Genuinely unrepairable input still fails loudly with a non-zero exit.
 */

const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const DEFAULT_SOURCE_URL =
  'https://raw.githubusercontent.com/geoffhumphrey/homebrew-clubs-list/main/clubs.js';

const FETCH_TIMEOUT_MS = 30000;
const VM_TIMEOUT_MS = 5000;

async function fetchSource(url, timeoutMs = FETCH_TIMEOUT_MS) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetch(url, { signal: controller.signal });
    if (!response.ok) {
      throw new Error(`Failed to fetch clubs.js: HTTP ${response.status}`);
    }
    return await response.text();
  } finally {
    clearTimeout(timer);
  }
}

/**
 * Evaluate the source in an isolated context and return the CLUBS array.
 *
 * The context has no `require`, `process`, `module` or network globals, so
 * the upstream file cannot reach the host even though it is real JS.
 *
 * @param {string} sourceCode
 * @returns {unknown[]}
 */
function extractClubsArray(sourceCode) {
  const sandbox = {};
  vm.createContext(sandbox);

  try {
    vm.runInContext(sourceCode, sandbox, { timeout: VM_TIMEOUT_MS });
  } catch (error) {
    throw new Error(`clubs.js did not evaluate: ${error.message}`);
  }

  const clubs = vm.runInContext(
    'typeof CLUBS === "undefined" ? undefined : CLUBS',
    sandbox,
    { timeout: VM_TIMEOUT_MS }
  );

  if (clubs === undefined) {
    throw new Error('Expected a CLUBS array in the source file, none found.');
  }
  if (!Array.isArray(clubs)) {
    throw new Error(`Expected CLUBS to be an array, got ${typeof clubs}.`);
  }

  return clubs;
}

/**
 * Trim entries, collapse case-insensitive duplicates (first spelling seen
 * wins) and sort A–Z. Returns the cleaned list plus the normalization stats
 * and any unrepairable errors.
 *
 * @param {unknown[]} clubs
 * @returns {{clubs: string[], errors: string[], trimmed: number, duplicates: string[]}}
 */
function normalizeClubs(clubs) {
  const errors = [];
  const duplicates = [];
  const byKey = new Map();
  let trimmed = 0;

  for (const entry of clubs) {
    if (typeof entry !== 'string') {
      errors.push(`Non-string entry found: ${JSON.stringify(entry)}`);
      continue;
    }

    const clean = entry.trim();
    if (clean === '') {
      errors.push(`Empty or whitespace-only entry found: ${JSON.stringify(entry)}`);
      continue;
    }
    if (clean !== entry) {
      trimmed += 1;
    }

    const key = clean.toLowerCase();
    if (byKey.has(key)) {
      duplicates.push(clean);
      continue;
    }
    byKey.set(key, clean);
  }

  const cleaned = [...byKey.values()].sort((a, b) =>
    a.localeCompare(b, undefined, { sensitivity: 'base' })
  );

  if (cleaned.length === 0) {
    errors.push('Resulting array is empty — refusing to publish an empty list.');
  }

  return { clubs: cleaned, errors, trimmed, duplicates };
}

/**
 * Build the published artifact. `version` is derived from the club content
 * only, so it is stable across runs and changes precisely when the list
 * changes — Part B uses it to skip no-op syncs.
 *
 * @param {string[]} clubs
 * @param {{sourceUrl?: string, generatedAt?: string}} [options]
 */
function buildArtifact(clubs, options = {}) {
  const sourceUrl = options.sourceUrl ?? DEFAULT_SOURCE_URL;
  const generatedAt = options.generatedAt ?? new Date().toISOString();
  const checksum = crypto.createHash('sha256').update(JSON.stringify(clubs)).digest('hex');

  return {
    version: checksum.slice(0, 12),
    generatedAt,
    sourceUrl,
    count: clubs.length,
    clubs,
  };
}

/**
 * Fetch/read, extract, normalize and build in one step. Throws before any
 * file is written when the input cannot be repaired.
 *
 * @param {{sourceUrl?: string, sourceFile?: string|null, timeoutMs?: number, generatedAt?: string}} [options]
 */
async function runPipeline(options = {}) {
  const sourceUrl = options.sourceUrl ?? DEFAULT_SOURCE_URL;
  const sourceCode = options.sourceFile
    ? fs.readFileSync(options.sourceFile, 'utf8')
    : await fetchSource(sourceUrl, options.timeoutMs);

  const raw = extractClubsArray(sourceCode);
  const normalized = normalizeClubs(raw);

  if (normalized.errors.length > 0) {
    const error = new Error(
      `Validation failed:\n${normalized.errors.map((e) => `  - ${e}`).join('\n')}`
    );
    error.validationErrors = normalized.errors;
    throw error;
  }

  const artifact = buildArtifact(normalized.clubs, {
    sourceUrl,
    generatedAt: options.generatedAt,
  });

  return {
    artifact,
    stats: {
      inputCount: raw.length,
      count: normalized.clubs.length,
      trimmed: normalized.trimmed,
      duplicates: normalized.duplicates,
    },
  };
}

/**
 * Write atomically (temp file + rename) so a crash mid-write can never leave
 * a partial clubs.json on disk.
 */
function writeArtifact(outPath, artifact) {
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  const tmp = `${outPath}.tmp`;
  fs.writeFileSync(tmp, `${JSON.stringify(artifact, null, 2)}\n`);
  fs.renameSync(tmp, outPath);
}

async function main() {
  const sourceUrl = process.env.CLUBS_SOURCE_URL || DEFAULT_SOURCE_URL;
  const sourceFile = process.env.CLUBS_SOURCE_FILE || null;
  const outPath = process.env.CLUBS_OUTPUT || path.join(__dirname, 'dist', 'clubs.json');

  const { artifact, stats } = await runPipeline({ sourceUrl, sourceFile });
  writeArtifact(outPath, artifact);

  console.log(`Wrote ${outPath} — ${artifact.count} clubs, version ${artifact.version}`);
  console.log(`Source: ${sourceFile ?? sourceUrl}`);

  if (stats.trimmed > 0 || stats.duplicates.length > 0) {
    console.log(
      `Normalized: trimmed ${stats.trimmed} entr${stats.trimmed === 1 ? 'y' : 'ies'}; ` +
        `collapsed ${stats.duplicates.length} case-insensitive duplicate(s)` +
        (stats.duplicates.length ? `: ${stats.duplicates.join(', ')}` : '')
    );
  }
}

module.exports = {
  DEFAULT_SOURCE_URL,
  fetchSource,
  extractClubsArray,
  normalizeClubs,
  buildArtifact,
  runPipeline,
  writeArtifact,
};

if (require.main === module) {
  main().catch((error) => {
    console.error(error.message);
    process.exit(1);
  });
}
