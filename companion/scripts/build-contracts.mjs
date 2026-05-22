/**
 * Regenerates companion/src/contracts/generated.ts from the JSON Schema files.
 *
 * Usage: node scripts/build-contracts.mjs
 * Or via npm: npm run build:contracts
 *
 * The generated file is committed so consumers don't need the code-gen tool
 * at runtime.
 *
 * Strategy: json-schema-to-typescript uses $ref resolution via draft-07
 * `definitions` (not draft-2020-12 `$defs`). For each endpoint schema we:
 *   1. Replace `$defs` with `definitions` so refs resolve.
 *   2. Compile each def entry individually (root is a pointer to that entry).
 *   3. Deduplicate by interface name across all endpoints.
 */

import { compile } from 'json-schema-to-typescript';
import { readFileSync, writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const contractsDir = join(__dirname, '..', 'src', 'contracts');
const outputFile = join(contractsDir, 'generated.ts');

const endpoints = ['screenshot', 'diff', 'axe', 'layout', 'lighthouse', 'health'];

const compileOptions = {
  bannerComment: '',
  additionalProperties: false,
  enableConstEnums: true,
  strictIndexSignatures: true,
  unknownAny: false,
  declareExternallyReferenced: true,
};

/** Replace `$defs` keys with `definitions` so draft-07 ref resolution works */
function normalizeDefs(schema) {
  const raw = JSON.stringify(schema)
    .replace(/"\$defs"/g, '"definitions"')
    .replace(/#\/\$defs\//g, '#/definitions/');
  return JSON.parse(raw);
}

const allParts = [
  '/* eslint-disable @typescript-eslint/no-explicit-any, @typescript-eslint/no-redundant-type-constituents */',
  '// GENERATED FILE — do not edit manually.',
  '// Regenerate with: npm run build:contracts',
  '//',
  '// Sources: companion/src/contracts/*.schema.json',
  '// Tool: json-schema-to-typescript',
  '',
];

/** Track interface names we have already emitted to prevent duplicates */
const emitted = new Set();

for (const endpoint of endpoints) {
  const schemaPath = join(contractsDir, `${endpoint}.schema.json`);
  const rawSchema = JSON.parse(readFileSync(schemaPath, 'utf8'));
  const schema = normalizeDefs(rawSchema);

  const defs = schema.definitions ?? {};

  for (const [defName, defSchema] of Object.entries(defs)) {
    if (emitted.has(defName)) continue;

    // Build a schema that compiles just this definition, with all sibling
    // definitions available for $ref resolution.
    const rootSchema = {
      $schema: 'http://json-schema.org/draft-07/schema#',
      title: defName,
      ...defSchema,
      definitions: defs,
    };

    // eslint-disable-next-line no-await-in-loop
    const ts = await compile(rootSchema, defName, compileOptions);

    // Strip the eslint-disable banner compile() prepends
    const cleaned = ts
      .replace(/\/\* eslint-disable \*\/\n?/, '')
      .trim();

    // Collect the declarations for this compile run and emit only new ones.
    // compile() may generate referenced types alongside the requested root.
    const declarations = cleaned.split(/\n(?=export (?:interface|type) )/);
    for (const decl of declarations) {
      const nameMatch = decl.match(/export (?:interface|type) (\w+)/);
      const name = nameMatch ? nameMatch[1] : null;
      if (name && emitted.has(name)) continue;
      if (name) emitted.add(name);
      allParts.push(decl.trim());
      allParts.push('');
    }
  }
}

const output = allParts.join('\n');
writeFileSync(outputFile, output, 'utf8');
console.log(`Written ${outputFile} (${output.length} bytes, ${emitted.size} types)`);
