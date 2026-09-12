'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const {
  extractClubsArray,
  normalizeClubs,
  buildArtifact,
  runPipeline,
  writeArtifact,
} = require('../convert.js');

const fixture = (name) => path.join(__dirname, '..', 'fixtures', name);

test('extractClubsArray resolves const, let and var declarations', () => {
  // Regression: upstream declares `const CLUBS`, which is NOT a property of
  // the vm context object, so naive `sandbox.CLUBS` returns undefined.
  // Spread into a host array: a vm-realm array has a different prototype,
  // which strict deep-equal rejects even when the contents match.
  assert.deepEqual([...extractClubsArray('const CLUBS = ["A"];')], ['A']);
  assert.deepEqual([...extractClubsArray('let CLUBS = ["B"];')], ['B']);
  assert.deepEqual([...extractClubsArray('var CLUBS = ["C"];')], ['C']);
});

test('extractClubsArray fails loudly on unrepairable source', () => {
  assert.throws(() => extractClubsArray('const OTHER = [];'), /Expected a CLUBS array/);
  assert.throws(() => extractClubsArray('const CLUBS = { a: 1 };'), /Expected CLUBS to be an array/);
  assert.throws(() => extractClubsArray('const CLUBS = [;'), /did not evaluate/);
});

test('a trailing-backslash entry round-trips byte for byte', async () => {
  const { artifact } = await runPipeline({
    sourceFile: fixture('good.js'),
    generatedAt: '2026-01-01T00:00:00.000Z',
  });

  const entry = artifact.clubs.find((club) => club.startsWith('Ararat'));
  assert.equal(entry, 'Ararat Shrine \\', 'the literal trailing backslash must survive');

  const reparsed = JSON.parse(JSON.stringify(artifact));
  assert.equal(
    reparsed.clubs.find((club) => club.startsWith('Ararat')),
    'Ararat Shrine \\',
    'the backslash must survive a JSON round trip'
  );
});

test('normalizeClubs trims and collapses case-insensitive duplicates', () => {
  const { clubs, errors, trimmed, duplicates } = normalizeClubs(['  Foo  ', 'foo', 'Bar', 'Baz ']);

  assert.deepEqual(errors, []);
  assert.equal(trimmed, 2, 'both whitespace-padded entries are counted');
  assert.deepEqual(duplicates, ['foo'], 'the later spelling is the one collapsed');
  assert.deepEqual(clubs, ['Bar', 'Baz', 'Foo'], 'first spelling kept, then sorted A–Z');
});

test('normalizeClubs rejects unrepairable entries', () => {
  assert.match(normalizeClubs(['ok', 42]).errors.join('\n'), /Non-string entry/);
  assert.match(normalizeClubs(['ok', '   ']).errors.join('\n'), /Empty or whitespace-only/);
  assert.match(normalizeClubs([]).errors.join('\n'), /refusing to publish an empty list/);
});

test('buildArtifact version is content-derived and stable', () => {
  const a = buildArtifact(['Foo', 'Bar'], { generatedAt: 't1' });
  const b = buildArtifact(['Foo', 'Bar'], { generatedAt: 't2' });
  const c = buildArtifact(['Foo', 'Baz'], { generatedAt: 't1' });

  assert.equal(a.version, b.version, 'same content => same version');
  assert.notEqual(a.version, c.version, 'changed content => changed version');
});

test('two runs over unchanged input differ only in generatedAt', async () => {
  const one = await runPipeline({ sourceFile: fixture('good.js'), generatedAt: 't1' });
  const two = await runPipeline({ sourceFile: fixture('good.js'), generatedAt: 't2' });

  assert.equal(
    JSON.stringify({ ...one.artifact, generatedAt: null }),
    JSON.stringify({ ...two.artifact, generatedAt: null })
  );
});

test('the real-world quirks normalize rather than block publishing', async () => {
  const { artifact, stats } = await runPipeline({ sourceFile: fixture('good.js') });

  assert.equal(stats.inputCount, 6);
  assert.equal(stats.trimmed, 1);
  assert.deepEqual(stats.duplicates, ['dup club']);
  assert.equal(artifact.count, 5);
  assert.equal(artifact.clubs.includes('Trailing'), true, 'trimmed entry is published clean');
  assert.equal(artifact.clubs.includes('DUP Club'), true, 'first duplicate spelling survives');
});

test('runPipeline throws on malformed source without writing output', async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'clubs-'));
  const out = path.join(dir, 'dist', 'clubs.json');

  await assert.rejects(() => runPipeline({ sourceFile: fixture('no-clubs.js') }), /Expected a CLUBS array/);
  await assert.rejects(() => runPipeline({ sourceFile: fixture('non-array.js') }), /Expected CLUBS to be an array/);
  await assert.rejects(() => runPipeline({ sourceFile: fixture('syntax-error.js') }), /did not evaluate/);

  assert.equal(fs.existsSync(out), false, 'no artifact is written on failure');
});

test('writeArtifact writes atomically and leaves no temp file', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'clubs-'));
  const out = path.join(dir, 'dist', 'clubs.json');
  const artifact = buildArtifact(['A'], { generatedAt: 't' });

  writeArtifact(out, artifact);

  assert.deepEqual(JSON.parse(fs.readFileSync(out, 'utf8')), artifact);
  assert.equal(fs.existsSync(`${out}.tmp`), false);
});
