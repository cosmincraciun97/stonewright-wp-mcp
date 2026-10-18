#!/usr/bin/env node
/**
 * Ed25519 signature of the release checksum manifest.
 *
 * The release workflow signs the exact bytes of SHA256SUMS.txt and publishes
 * the detached signature as SHA256SUMS.txt.sig, one base64 signature per line.
 * Signing is on exactly when plugin/includes/Core/ReleaseSignature.php lists
 * public keys: the plugin version that ships them refuses an update whose
 * manifest no listed key signed.
 *
 *   node scripts/release-signature.mjs keygen --out <file outside the repository>
 *   node scripts/release-signature.mjs public-key --key <private key file>
 *   node scripts/release-signature.mjs sign --manifest dist/SHA256SUMS.txt --out dist/SHA256SUMS.txt.sig
 *   node scripts/release-signature.mjs verify --manifest dist/SHA256SUMS.txt --signature dist/SHA256SUMS.txt.sig
 *
 * `sign` reads the PKCS#8 PEM private key from STONEWRIGHT_RELEASE_SIGNING_KEY.
 * No command prints a private key.
 */

import { createPrivateKey, createPublicKey, generateKeyPairSync, sign, verify } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

export const SIGNATURE_ASSET = 'SHA256SUMS.txt.sig';
export const SIGNING_KEY_ENV = 'STONEWRIGHT_RELEASE_SIGNING_KEY';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const PUBLIC_KEYS_FILE = path.join(repoRoot, 'plugin', 'includes', 'Core', 'ReleaseSignature.php');

// Kept equal to ReleaseSignature::MAX_SIGNATURE_FILE_BYTES and its line limit.
const MAX_SIGNATURE_FILE_BYTES = 4096;
const MAX_SIGNATURES = 4;
// The active key and a backup, plus at most two more during a key change.
const MAX_PUBLIC_KEYS = 4;
const MIN_PUBLIC_KEYS_WHEN_SIGNING = 2;
const PUBLIC_KEY_PATTERN = /^[A-Za-z0-9+/]{43}=$/;
const SIGNATURE_PATTERN = /^[A-Za-z0-9+/]{86}==$/;

/**
 * Read ReleaseSignature::PUBLIC_KEYS. Inside the brackets only one quoted key
 * per line, blank lines and `//` comment lines are accepted.
 */
export function parsePublicKeys(phpSource) {
	const match = /^[ \t]*public const PUBLIC_KEYS = \[(.*?)\];\r?$/ms.exec(phpSource);
	if (!match) {
		throw new Error('ReleaseSignature.php has no `public const PUBLIC_KEYS = [ ... ];` list.');
	}
	const keys = [];
	for (const raw of match[1].split('\n')) {
		const line = raw.trim();
		if (line === '' || line.startsWith('//')) {
			continue;
		}
		const entry = /^'([^']*)',?(?:\s*\/\/.*)?$/.exec(line);
		if (!entry) {
			throw new Error(`PUBLIC_KEYS holds a line that is not one quoted key: ${line}`);
		}
		const key = entry[1];
		if (!PUBLIC_KEY_PATTERN.test(key) || Buffer.from(key, 'base64').length !== 32) {
			throw new Error(`PUBLIC_KEYS entry is not a base64 Ed25519 public key: ${key}`);
		}
		keys.push(key);
	}
	if (new Set(keys).size !== keys.length) {
		throw new Error('PUBLIC_KEYS lists the same key twice.');
	}
	if (keys.length > MAX_PUBLIC_KEYS) {
		throw new Error(`PUBLIC_KEYS lists ${keys.length} keys; keep at most ${MAX_PUBLIC_KEYS}.`);
	}
	return keys;
}

export function readEmbeddedPublicKeys(file = PUBLIC_KEYS_FILE) {
	return parsePublicKeys(fs.readFileSync(file, 'utf8'));
}

/**
 * Signing needs the active key and a backup: a site that trusts one lost key
 * could never take an update through WordPress again.
 */
export function assertSigningKeySet(publicKeys) {
	if (publicKeys.length > 0 && publicKeys.length < MIN_PUBLIC_KEYS_WHEN_SIGNING) {
		throw new Error('ReleaseSignature::PUBLIC_KEYS must list the active key and a backup key, or no key at all.');
	}
}

function rawBase64(buffer) {
	return Buffer.from(buffer).toString('base64');
}

function publicKeyObject(base64Key) {
	return createPublicKey({
		key: { kty: 'OKP', crv: 'Ed25519', x: Buffer.from(base64Key, 'base64').toString('base64url') },
		format: 'jwk',
	});
}

function loadPrivateKey(pem) {
	let key;
	try {
		key = createPrivateKey(pem);
	} catch {
		// Never echo the input: it may be most of a private key.
		throw new Error('The release signing key is not a readable PEM private key.');
	}
	if (key.asymmetricKeyType !== 'ed25519') {
		throw new Error('The release signing key is not an Ed25519 key.');
	}
	return key;
}

export function generateSigningKey() {
	const { privateKey, publicKey } = generateKeyPairSync('ed25519');
	return {
		privateKeyPem: privateKey.export({ type: 'pkcs8', format: 'pem' }),
		publicKey: rawBase64(Buffer.from(publicKey.export({ format: 'jwk' }).x, 'base64url')),
	};
}

/** Base64 raw Ed25519 public key of a PEM private key, as PUBLIC_KEYS lists it. */
export function publicKeyOf(privateKeyPem) {
	const jwk = createPublicKey(loadPrivateKey(privateKeyPem)).export({ format: 'jwk' });
	return rawBase64(Buffer.from(jwk.x, 'base64url'));
}

/** One signature line for the exact manifest bytes. */
export function signManifest(manifest, privateKeyPem) {
	return `${sign(null, Buffer.from(manifest), loadPrivateKey(privateKeyPem)).toString('base64')}\n`;
}

/** Raw signatures from the lines that hold one; other lines are skipped, as the updater does. */
export function signatureLines(signatureText) {
	const signatures = [];
	for (const raw of String(signatureText).split(/\r?\n/)) {
		const line = raw.trim();
		if (!SIGNATURE_PATTERN.test(line)) {
			continue;
		}
		const signature = Buffer.from(line, 'base64');
		if (signature.length !== 64) {
			continue;
		}
		signatures.push(signature);
		if (signatures.length >= MAX_SIGNATURES) {
			break;
		}
	}
	return signatures;
}

/**
 * Check the signature file the way the updater does and return the position
 * of the public key that verified it. Throws when none does.
 */
export function verifyManifestSignature(manifest, signatureText, publicKeys) {
	if (publicKeys.length === 0) {
		throw new Error('There is no release public key to check SHA256SUMS.txt.sig against.');
	}
	if (Buffer.byteLength(signatureText) > MAX_SIGNATURE_FILE_BYTES) {
		throw new Error(`${SIGNATURE_ASSET} is larger than ${MAX_SIGNATURE_FILE_BYTES} bytes.`);
	}
	const signatures = signatureLines(signatureText);
	if (signatures.length === 0) {
		throw new Error(`${SIGNATURE_ASSET} holds no well-formed signature.`);
	}
	const keys = publicKeys.map(publicKeyObject);
	const data = Buffer.from(manifest);
	for (const signature of signatures) {
		for (const [index, key] of keys.entries()) {
			if (verify(null, data, key, signature)) {
				return index;
			}
		}
	}
	throw new Error(`${SIGNATURE_ASSET} does not verify against the public keys in ReleaseSignature.php.`);
}

/**
 * Sign the manifest for a release, or report that signing is off because the
 * plugin ships no public key. Returns the signature text, or null when off.
 */
export function signRelease(manifest, publicKeys, privateKeyPem) {
	if (publicKeys.length === 0) {
		return null;
	}
	assertSigningKeySet(publicKeys);
	if (typeof privateKeyPem !== 'string' || privateKeyPem.trim() === '') {
		throw new Error(
			`${SIGNING_KEY_ENV} is not set. The plugin ships release public keys, so every release must be signed.`,
		);
	}
	if (!publicKeys.includes(publicKeyOf(privateKeyPem))) {
		throw new Error(`The key in ${SIGNING_KEY_ENV} is not one of the public keys in ReleaseSignature.php.`);
	}
	const signature = signManifest(manifest, privateKeyPem);
	verifyManifestSignature(manifest, signature, publicKeys);
	return signature;
}

function isInside(parent, child) {
	const relative = path.relative(parent, child);
	return relative === '' || (!relative.startsWith('..') && !path.isAbsolute(relative));
}

function option(args, name) {
	const index = args.indexOf(name);
	const value = index >= 0 ? args[index + 1] : undefined;
	if (!value || value.startsWith('--')) {
		throw new Error(`Expected ${name} <path>.`);
	}
	return value;
}

function keygen(args) {
	const out = path.resolve(option(args, '--out'));
	if (isInside(repoRoot, out)) {
		throw new Error('Write the private key outside the repository so it can never be committed.');
	}
	const { privateKeyPem, publicKey } = generateSigningKey();
	fs.mkdirSync(path.dirname(out), { recursive: true });
	fs.writeFileSync(out, privateKeyPem, { flag: 'wx', mode: 0o600 });
	process.stdout.write(
		[
			`Private key written to ${out}. Keep it offline; it is never printed.`,
			'Public key for ReleaseSignature::PUBLIC_KEYS:',
			publicKey,
			'',
		].join('\n'),
	);
}

function main(args) {
	const command = args[0];
	if (command === 'keygen') {
		keygen(args);
	} else if (command === 'public-key') {
		process.stdout.write(`${publicKeyOf(fs.readFileSync(option(args, '--key'), 'utf8'))}\n`);
	} else if (command === 'sign') {
		const manifestPath = option(args, '--manifest');
		const out = option(args, '--out');
		const publicKeys = readEmbeddedPublicKeys();
		const signature = signRelease(fs.readFileSync(manifestPath), publicKeys, process.env[SIGNING_KEY_ENV]);
		if (signature === null) {
			if (fs.existsSync(out)) {
				throw new Error(`${out} exists although ReleaseSignature::PUBLIC_KEYS is empty.`);
			}
			process.stdout.write(`Release signing is off: ReleaseSignature::PUBLIC_KEYS is empty, so no ${SIGNATURE_ASSET} is written.\n`);
			return;
		}
		fs.writeFileSync(out, signature, { flag: 'wx' });
		process.stdout.write(`Signed ${path.basename(manifestPath)}; the signature verifies against ReleaseSignature::PUBLIC_KEYS.\n`);
	} else if (command === 'verify') {
		const manifest = fs.readFileSync(option(args, '--manifest'));
		const signatureText = fs.readFileSync(option(args, '--signature'), 'utf8');
		const publicKeys = args.includes('--keys-file') ? readEmbeddedPublicKeys(option(args, '--keys-file')) : readEmbeddedPublicKeys();
		const index = verifyManifestSignature(manifest, signatureText, publicKeys);
		process.stdout.write(`${SIGNATURE_ASSET} verifies with public key ${index + 1} of ${publicKeys.length}.\n`);
	} else {
		process.stderr.write(
			'Usage: node scripts/release-signature.mjs (keygen --out <file> | public-key --key <file> | sign --manifest <file> --out <file> | verify --manifest <file> --signature <file> [--keys-file <ReleaseSignature.php>])\n',
		);
		throw new Error('Unknown command.');
	}
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
	try {
		main(process.argv.slice(2));
	} catch (error) {
		process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
		process.exitCode = 1;
	}
}
