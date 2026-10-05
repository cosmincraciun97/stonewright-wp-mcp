import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { mkdtempSync, rmdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, parse } from 'node:path';
import { homedir } from 'node:os';

/** Native ACL fixture setup; never use this helper for user-owned directories. */
export function setSyntheticOAuthAcl(path: string, foreignRights: boolean | 'Delete' | 'FullControl' = false, createDirectory = false, inheritForeign = true): void {
	if (process.platform !== 'win32') return;
	const script = [
		"$ErrorActionPreference = 'Stop'",
		"$fixture = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($env:STONEWRIGHT_ACL_FIXTURE)) | ConvertFrom-Json",
		"$isDirectory = $fixture.createDirectory -or [IO.Directory]::Exists($fixture.path)",
		"$user = [Security.Principal.WindowsIdentity]::GetCurrent().User",
		"$acl = if ($isDirectory) { New-Object Security.AccessControl.DirectorySecurity } else { New-Object Security.AccessControl.FileSecurity }",
		"$acl.SetOwner($user)",
		"$acl.SetAccessRuleProtection($true, $false)",
		"$inherit = if ($isDirectory) { [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit' } else { [Security.AccessControl.InheritanceFlags]::None }",
		"foreach ($sid in @($user, [Security.Principal.SecurityIdentifier]'S-1-5-18', [Security.Principal.SecurityIdentifier]'S-1-5-32-544')) {",
		"  $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($sid, [Security.AccessControl.FileSystemRights]::FullControl, $inherit, [Security.AccessControl.PropagationFlags]::None, [Security.AccessControl.AccessControlType]::Allow)))",
		"}",
		"if ($fixture.foreignRights) {",
		"  $rights = if ($fixture.foreignRights -eq $true) { [Security.AccessControl.FileSystemRights]::Read } else { [Security.AccessControl.FileSystemRights]$fixture.foreignRights }",
		"  $foreignInherit = if ($fixture.inheritForeign) { $inherit } else { [Security.AccessControl.InheritanceFlags]::None }",
		"  $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule([Security.Principal.SecurityIdentifier]'S-1-1-0', $rights, $foreignInherit, [Security.AccessControl.PropagationFlags]::None, [Security.AccessControl.AccessControlType]::Allow)))",
		"}",
		"if ($fixture.createDirectory) { [void][IO.Directory]::CreateDirectory($fixture.path, $acl) } elseif ($isDirectory) { [IO.Directory]::SetAccessControl($fixture.path, $acl) } else { [IO.File]::SetAccessControl($fixture.path, $acl) }",
	].join('\n');
	execFileSync(join(process.env['SystemRoot'] ?? 'C:\\Windows', 'System32', 'WindowsPowerShell', 'v1.0', 'powershell.exe'), [
		'-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand', Buffer.from(script, 'utf16le').toString('base64'),
	], {
		windowsHide: true,
		stdio: 'pipe',
		env: { ...process.env, STONEWRIGHT_ACL_FIXTURE: Buffer.from(JSON.stringify({ path, foreignRights, createDirectory, inheritForeign })).toString('base64') },
	});
}

let fixtureRoot: string | null = null;
export function createOAuthTestDirectory(prefix = 'stonewright-oauth-'): string {
	if (process.platform !== 'win32') return mkdtempSync(join(tmpdir(), prefix));
	if (!fixtureRoot) {
		// A dedicated synthetic root avoids permissive user-profile or AppContainer temp ACLs.
		fixtureRoot = join(parse(homedir()).root, `stonewright-oauth-fixtures-${randomUUID()}`);
		setSyntheticOAuthAcl(fixtureRoot, false, true);
		process.env['TEMP'] = fixtureRoot;
		process.env['TMP'] = fixtureRoot;
		const ownedRoot = fixtureRoot;
		process.once('exit', () => { try { rmdirSync(ownedRoot); } catch { /* Refuse remaining fixture contents. */ } });
	}
	return mkdtempSync(join(fixtureRoot, prefix)); // Inherit the independently established native test ACL.
}
