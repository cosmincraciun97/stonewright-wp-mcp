# Companion OAuth storage

The companion stores OAuth tokens using an exclusive, randomly named temporary
file in the destination directory. It writes and flushes a writable handle,
closes it, then replaces the destination. A failed write, flush, or replacement
preserves the previous file and removes only the temporary file whose identity
still belongs to that operation. Token validation, refresh rotation, replay
rejection, resource binding, and reauthentication rules remain in the
[OAuth failure contract](permanent-remediation-contracts.md#oauth-failure-and-rate-limit-contract).

## Permissions

On POSIX systems, the directory uses `0700` and the token file uses `0600`.
Files with group or world permissions are rejected on load. Symbolic links,
nonregular files, and hard-linked token files are refused.

Native Windows support requires a local fixed NTFS or ReFS drive, Windows
PowerShell 5.1, and the .NET Framework C# compiler and ACL APIs. Network paths,
alternate data streams, and reparse points anywhere in the path are refused.
Windows `chmod` bits do not establish privacy: the native owner and DACL are
checked before token reads or writes and before replacement.

The token directory, token file, and native helper directory must have:

- an owner equal to the current Windows user, SYSTEM, or Administrators;
- only Allow entries for those same three principals;
- an effective Full Control entry for the current user;
- directory entries that inherit to both child files and directories.

Ancestors must have a trusted owner and must deny other principals permission
to delete or rename the ancestor, delete its children, change permissions, or
take ownership. The Windows TrustedInstaller servicing principal is trusted
for ancestors only. Ancestors may allow other users to read or create siblings
when those replacement rights are absent. Protection assumes the current user,
SYSTEM, Administrators, and the operating system remain trusted.

New dedicated directories receive their private DACL during native creation,
before token or lock bytes exist. Existing directories and files are verified
without changing their permissions. A privacy rejection preserves existing
private state. Refresh locks use the same protected directory policy.

## Windows prerequisites and recovery

Both the configured token location and the process temporary directory must
have safe ancestors. Some hosts grant additional principals replacement rights
in user-profile or temporary directories; those locations fail closed even
when a child directory has a private DACL. Choose protected local storage and
configure `TEMP` and `TMP` to a protected temporary location before starting the
companion. Have the operator review the host permissions rather than changing
existing user or system directories automatically. Reauthorization alone does
not fix a storage-permission failure.

The original MIT helper source ships as `data/oauth-windows-acl.cs` in the
companion package. Its authenticated source is compiled once per companion
process in a newly created private temporary directory. Compiler intermediates
stay there. The generated helper is never shared through a stable executable
cache. IPC contains paths and operation names, never token values. Each request
checks live permissions and ancestry; no ACL result is cached. Executable
integrity and file identity are checked before requests.

Native request reads use a 250 millisecond retry budget for Windows sharing or
lock violations, rechecking live privacy before each attempt. Permission,
ownership, reparse, and other errors still fail closed. The helper request
deadline remains five seconds.

A missing compiler, failed inspection, changed helper, exited helper, or bounded
request timeout produces a generic privacy failure and stops storage access.
After repairing the host prerequisite, restart the companion to create a fresh
helper. Cleanup removes only verified, newly owned helper files and uses no
recursive removal; unexpected contents or changed identities are retained.

Microsoft documents [writable handles for file flushing](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-flushfilebuffers)
and [DACLs supplied during directory creation](https://learn.microsoft.com/en-us/dotnet/api/system.io.directory.createdirectory?view=netframework-4.8.1).
Node documents the [limits of Windows chmod](https://nodejs.org/api/fs.html#fschmodpath-mode-callback).
