// SPDX-License-Identifier: MIT
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createHash, randomUUID } from 'node:crypto';
import { closeSync, existsSync, fstatSync, lstatSync, openSync, readFileSync, renameSync, rmdirSync, unlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { performance } from 'node:perf_hooks';

const SOURCE_SHA256 = '77a3786bded94c13135d6346efe65f17beee91255a2723f6574421fad4806913';
const SOURCE_PATH = fileURLToPath(new URL('../data/oauth-windows-acl.cs', import.meta.url));
const BOOTSTRAP = [
	"$ErrorActionPreference = 'Stop'",
	"try {",
	"  $request = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($env:STONEWRIGHT_OAUTH_ACL_BOOTSTRAP)) | ConvertFrom-Json",
	"  $root = [IO.Path]::GetPathRoot($request.directory)",
	"  if ($root.Length -ne 3 -or $root[1] -ne ':' -or $request.directory.IndexOf(':', 2) -ge 0) { exit 1 }",
	"  $drive = New-Object IO.DriveInfo($root)",
	"  if ($drive.DriveType -ne 'Fixed' -or $drive.DriveFormat -notin @('NTFS', 'ReFS')) { exit 1 }",
	"  $user = [Security.Principal.WindowsIdentity]::GetCurrent().User",
	"  $trusted = @($user.Value, 'S-1-5-18', 'S-1-5-32-544')",
	"  $servicing = (New-Object Security.Principal.NTAccount('NT SERVICE', 'TrustedInstaller')).Translate([Security.Principal.SecurityIdentifier]).Value",
	"  $ancestorTrusted = $trusted + @($servicing)",
	"  $replacement = [Security.AccessControl.FileSystemRights]'Delete, DeleteSubdirectoriesAndFiles, ChangePermissions, TakeOwnership'",
	"  $current = [IO.Path]::GetDirectoryName($request.directory)",
	"  while ($current) {",
	"    if (([IO.File]::GetAttributes($current) -band [IO.FileAttributes]::ReparsePoint) -ne 0) { exit 1 }",
	"    $parentAcl = [IO.Directory]::GetAccessControl($current)",
	"    if ($parentAcl.GetOwner([Security.Principal.SecurityIdentifier]).Value -notin $ancestorTrusted) { exit 1 }",
	"    foreach ($rule in $parentAcl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])) {",
	"      if ($rule.AccessControlType -eq 'Allow' -and $rule.IdentityReference.Value -notin $ancestorTrusted -and ($rule.PropagationFlags -band [Security.AccessControl.PropagationFlags]::InheritOnly) -eq 0 -and ($rule.FileSystemRights -band $replacement) -ne 0) { exit 1 }",
	"    }",
	"    $current = [IO.Path]::GetDirectoryName($current)",
	"  }",
	"  if ([IO.Directory]::Exists($request.directory) -or [IO.File]::Exists($request.directory)) { exit 1 }",
	"  $acl = New-Object Security.AccessControl.DirectorySecurity",
	"  $acl.SetOwner($user)",
	"  $acl.SetAccessRuleProtection($true, $false)",
	"  foreach ($sid in @($user, [Security.Principal.SecurityIdentifier]'S-1-5-18', [Security.Principal.SecurityIdentifier]'S-1-5-32-544')) {",
	"    $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($sid, [Security.AccessControl.FileSystemRights]::FullControl, [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit', [Security.AccessControl.PropagationFlags]::None, [Security.AccessControl.AccessControlType]::Allow)))",
	"  }",
	"  [void][IO.Directory]::CreateDirectory($request.directory, $acl)",
	"  if (([IO.File]::GetAttributes($request.directory) -band [IO.FileAttributes]::ReparsePoint) -ne 0) { exit 1 }",
	"  $createdAcl = [IO.Directory]::GetAccessControl($request.directory)",
	"  if ($createdAcl.GetOwner([Security.Principal.SecurityIdentifier]).Value -ne $user.Value) { exit 1 }",
	"  $full = $false",
	"  foreach ($rule in $createdAcl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])) {",
	"    if ($rule.AccessControlType -ne 'Allow' -or $rule.IdentityReference.Value -notin $trusted) { exit 1 }",
	"    if ($rule.IdentityReference.Value -eq $user.Value -and ($rule.FileSystemRights -band [Security.AccessControl.FileSystemRights]::FullControl) -eq [Security.AccessControl.FileSystemRights]::FullControl) { $full = $true }",
	"  }",
	"  if (-not $full) { exit 1 }",
	"  $source = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($request.source))",
	"  $provider = New-Object Microsoft.CSharp.CSharpCodeProvider",
	"  $parameters = New-Object CodeDom.Compiler.CompilerParameters",
	"  $parameters.GenerateExecutable = $true",
	"  $parameters.GenerateInMemory = $false",
	"  $parameters.IncludeDebugInformation = $false",
	"  $parameters.OutputAssembly = [IO.Path]::Combine($request.directory, 'oauth-acl.exe')",
	"  $parameters.CompilerOptions = '/optimize+'",
	"  $parameters.TempFiles = New-Object CodeDom.Compiler.TempFileCollection($request.directory, $false)",
	"  [void]$parameters.ReferencedAssemblies.Add('System.dll')",
	"  [void]$parameters.ReferencedAssemblies.Add('System.Web.Extensions.dll')",
	"  $result = $provider.CompileAssemblyFromSource($parameters, $source)",
	"  if ($result.Errors.HasErrors) { exit 1 }",
	"  exit 0",
	"} catch { exit 1 }",
].join('\n');

export class OAuthStoragePrivacyError extends Error {
	constructor(reason = 'unavailable') {
		super(reason === 'reparse'
			? 'OAuth storage refuses symlinks and Windows reparse points.'
			: reason === 'location'
				? 'OAuth storage requires a local Windows NTFS or ReFS directory.'
				: 'OAuth storage privacy could not be verified. Use a private local Windows storage directory and a protected temporary-directory location restricted to the current user, SYSTEM and Administrators. Their ancestors must prevent other principals from replacing paths or changing permissions.');
		this.name = 'OAuthStoragePrivacyError';
	}
}

interface NativeHelper { directory: string; temporaryRoot: string; executable: string; hash: string; child: ChildProcess; identity: { dev: number; ino: number } }
let helper: NativeHelper | null = null;
const pause = new Int32Array(new SharedArrayBuffer(4));

function noLinkedPath(path: string): void {
	let current = resolve(path);
	for (;;) {
		if (existsSync(current) && lstatSync(current).isSymbolicLink()) throw new OAuthStoragePrivacyError('reparse');
		const parent = dirname(current);
		if (parent === current) return;
		current = parent;
	}
}

function regularBytes(path: string): Buffer {
	noLinkedPath(path);
	const before = lstatSync(path);
	if (!before.isFile() || before.isSymbolicLink() || before.nlink !== 1) throw new OAuthStoragePrivacyError();
	const fd = openSync(path, 'r');
	try {
		const opened = fstatSync(fd);
		if (opened.dev !== before.dev || opened.ino !== before.ino || opened.nlink !== 1) throw new OAuthStoragePrivacyError();
		return readFileSync(fd);
	} finally { closeSync(fd); }
}

function hash(bytes: Buffer): string { return createHash('sha256').update(bytes).digest('hex'); }

function cleanupOwnedHelper(owned: NativeHelper): void {
	try {
		owned.child.kill();
		if (dirname(owned.directory) !== owned.temporaryRoot || !lstatSync(owned.directory).isDirectory() || lstatSync(owned.directory).isSymbolicLink()) return;
		const directory = lstatSync(owned.directory);
		if (directory.dev !== owned.identity.dev || directory.ino !== owned.identity.ino) return;
		if (existsSync(owned.executable)) {
			if (hash(regularBytes(owned.executable)) !== owned.hash) return;
			const deadline = Date.now() + 250;
			for (;;) {
				try { unlinkSync(owned.executable); break; }
				catch (error) { if ((error as NodeJS.ErrnoException).code !== 'EPERM' || Date.now() >= deadline) throw error; Atomics.wait(pause, 0, 0, 5); }
			}
		}
		rmdirSync(owned.directory); // Refuse unexpected contents; never recursively delete a review directory.
	} catch { /* The owned helper could not be safely removed. */ }
}

function nativeHelper(): NativeHelper {
	if (helper) return helper;
	const source = regularBytes(SOURCE_PATH);
	// Git text checkouts may use CRLF; the authenticated source is canonical LF text.
	const canonical = Buffer.from(source.toString('utf8').replace(/\r\n/g, '\n'));
	if (hash(canonical) !== SOURCE_SHA256) throw new OAuthStoragePrivacyError();
	const temporaryRoot = resolve(tmpdir());
	const directory = join(temporaryRoot, `stonewright-oauth-acl-${randomUUID()}`);
	const executable = join(directory, 'oauth-acl.exe');
	try {
		noLinkedPath(directory);
		execFileSync(join(process.env['SystemRoot'] ?? 'C:\\Windows', 'System32', 'WindowsPowerShell', 'v1.0', 'powershell.exe'), [
			'-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand', Buffer.from(BOOTSTRAP, 'utf16le').toString('base64'),
		], {
			windowsHide: true,
			stdio: 'pipe',
			timeout: 15_000,
			env: {
				...process.env,
				PSModulePath: join(process.env['SystemRoot'] ?? 'C:\\Windows', 'System32', 'WindowsPowerShell', 'v1.0', 'Modules'),
				STONEWRIGHT_OAUTH_ACL_BOOTSTRAP: Buffer.from(JSON.stringify({ directory, source: canonical.toString('base64') })).toString('base64'),
			},
		});
		const executableHash = hash(regularBytes(executable));
		const identity = lstatSync(directory);
		const child = spawn(executable, ['--serve', String(process.pid)], { windowsHide: true, stdio: 'ignore' });
		child.on('error', () => { /* Requests fail closed on the bounded response deadline. */ });
		child.unref();
		const generated = { directory, temporaryRoot, executable, hash: executableHash, child, identity };
		helper = generated;
		process.prependOnceListener('exit', () => cleanupOwnedHelper(generated));
		return generated;
	} catch {
		// No token bytes have been handled. An unverified generated file is retained rather than deleted blindly.
		try { if (!existsSync(executable)) rmdirSync(directory); } catch { /* Refuse nonempty directories. */ }
		throw new OAuthStoragePrivacyError();
	}
}

function inspect(path: string, action: 'prepare' | 'verify'): void {
	if (process.platform !== 'win32') return;
	try {
		const native = nativeHelper();
		if (hash(regularBytes(native.executable)) !== native.hash) throw new OAuthStoragePrivacyError();
		const directory = lstatSync(native.directory);
		if (directory.isSymbolicLink() || directory.dev !== native.identity.dev || directory.ino !== native.identity.ino) throw new OAuthStoragePrivacyError();
		const request = join(native.directory, 'request.json');
		const response = join(native.directory, 'response.json');
		if (existsSync(request) || existsSync(response)) throw new OAuthStoragePrivacyError();
		const temporaryRequest = join(native.directory, 'request.tmp');
		const fd = openSync(temporaryRequest, 'wx', 0o600);
		try { writeFileSync(fd, JSON.stringify({ action, path }), 'utf8'); } finally { closeSync(fd); }
		renameSync(temporaryRequest, request);
		const deadline = Date.now() + 5_000;
		const spinUntil = performance.now() + 2;
		while (!existsSync(response)) {
			if (Date.now() >= deadline) throw new OAuthStoragePrivacyError();
			// Windows waits may round to a 15 ms tick. Bound the hot-path spin, then yield.
			if (performance.now() >= spinUntil) Atomics.wait(pause, 0, 0, 1);
		}
		const result: unknown = JSON.parse(regularBytes(response).toString('utf8').replace(/^\uFEFF/, ''));
		unlinkSync(response);
		if (!result || typeof result !== 'object' || (result as { ok?: unknown }).ok !== true) {
			throw new OAuthStoragePrivacyError(typeof (result as { reason?: unknown })?.reason === 'string' ? (result as { reason: string }).reason : 'unavailable');
		}
	} catch (error) {
		if (error instanceof OAuthStoragePrivacyError) throw error;
		throw new OAuthStoragePrivacyError();
	}
}

export function prepareWindowsOAuthStorage(path: string): void { inspect(path, 'prepare'); }
export function verifyWindowsOAuthStorage(path: string): void { inspect(path, 'verify'); }
