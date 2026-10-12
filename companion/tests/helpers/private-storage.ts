import { statSync } from 'node:fs';
import { dirname } from 'node:path';
import { expect } from 'vitest';
import { verifyWindowsOAuthStorage } from '../../src/oauth-windows-privacy.js';

/**
 * Asserts that a stored file is private to the current user, and, when a directory is given, that the
 * directory holding it is too.
 *
 * POSIX: the file mode is 0600 and the directory mode 0700.
 * Windows: Node does not map those modes to NTFS, so the real access control list is read instead, through
 * the same native check that protects the OAuth token files (current user, SYSTEM and Administrators only,
 * no reparse points, no foreign principal on the path). It always covers the file and its parent directory.
 * The file must sit under a directory that carries a private ACL, for example one from
 * createOAuthTestDirectory().
 */
export function expectPrivateStorage(file: string, directory?: string): void {
	if (directory !== undefined) expect(dirname(file)).toBe(directory);
	if (process.platform === 'win32') {
		verifyWindowsOAuthStorage(file);
		return;
	}
	expect(statSync(file).mode & 0o777).toBe(0o600);
	if (directory !== undefined) expect(statSync(directory).mode & 0o777).toBe(0o700);
}
