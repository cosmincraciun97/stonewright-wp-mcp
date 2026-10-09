// SPDX-License-Identifier: MIT
// Native Windows ACL boundary for Stonewright OAuth storage. Outputs contain no paths or identities.
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Security.AccessControl;
using System.Security.Principal;
using System.Web.Script.Serialization;
using System.Threading;

internal sealed class PrivacyFailure : Exception
{
    internal readonly string Reason;
    internal PrivacyFailure(string reason) { Reason = reason; }
}

internal static class OAuthWindowsAcl
{
    private static readonly SecurityIdentifier User = WindowsIdentity.GetCurrent().User;
    private static readonly SecurityIdentifier SystemSid = new SecurityIdentifier(WellKnownSidType.LocalSystemSid, null);
    private static readonly SecurityIdentifier AdminSid = new SecurityIdentifier(WellKnownSidType.BuiltinAdministratorsSid, null);
    private static readonly SecurityIdentifier ServicingSid = (SecurityIdentifier)new NTAccount("NT SERVICE", "TrustedInstaller").Translate(typeof(SecurityIdentifier));
    private static readonly FileSystemRights MutationRights = FileSystemRights.Delete |
        FileSystemRights.DeleteSubdirectoriesAndFiles | FileSystemRights.ChangePermissions | FileSystemRights.TakeOwnership;

    private static bool Trusted(IdentityReference sid)
    {
        return sid.Equals(User) || sid.Equals(SystemSid) || sid.Equals(AdminSid);
    }

    private static FileSystemSecurity Acl(string path, bool directory)
    {
        return directory ? (FileSystemSecurity)Directory.GetAccessControl(path, AccessControlSections.Access | AccessControlSections.Owner)
            : File.GetAccessControl(path, AccessControlSections.Access | AccessControlSections.Owner);
    }

    private static void PrivateAcl(string path, bool directory)
    {
        FileSystemSecurity acl = Acl(path, directory);
        if (!Trusted(acl.GetOwner(typeof(SecurityIdentifier)))) throw new PrivacyFailure("privacy");
        bool userFullControl = false;
        foreach (FileSystemAccessRule rule in acl.GetAccessRules(true, true, typeof(SecurityIdentifier)))
        {
            if (rule.AccessControlType != AccessControlType.Allow || !Trusted(rule.IdentityReference))
                throw new PrivacyFailure("privacy");
            if (rule.IdentityReference.Equals(User) && (rule.PropagationFlags & PropagationFlags.InheritOnly) == 0 &&
                (rule.FileSystemRights & FileSystemRights.FullControl) == FileSystemRights.FullControl)
            {
                if (!directory || (rule.InheritanceFlags & (InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit)) ==
                    (InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit)) userFullControl = true;
            }
        }
        if (!userFullControl) throw new PrivacyFailure("privacy");
    }

    private static void SafeCreationParent(string path)
    {
        FileSystemSecurity acl = Acl(path, true);
        IdentityReference owner = acl.GetOwner(typeof(SecurityIdentifier));
        if (!Trusted(owner) && !owner.Equals(ServicingSid)) throw new PrivacyFailure("privacy");
        foreach (FileSystemAccessRule rule in acl.GetAccessRules(true, true, typeof(SecurityIdentifier)))
        {
            if (rule.AccessControlType == AccessControlType.Allow && !Trusted(rule.IdentityReference) && !rule.IdentityReference.Equals(ServicingSid) &&
                (rule.PropagationFlags & PropagationFlags.InheritOnly) == 0 && (rule.FileSystemRights & MutationRights) != 0)
                throw new PrivacyFailure("privacy");
        }
    }

    private static DirectorySecurity NewDirectoryAcl()
    {
        DirectorySecurity acl = new DirectorySecurity();
        acl.SetOwner(User);
        acl.SetAccessRuleProtection(true, false);
        foreach (SecurityIdentifier sid in new SecurityIdentifier[] { User, SystemSid, AdminSid })
            acl.AddAccessRule(new FileSystemAccessRule(sid, FileSystemRights.FullControl,
                InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit, PropagationFlags.None, AccessControlType.Allow));
        return acl;
    }

    private static bool TryAttributes(string path, out FileAttributes attributes)
    {
        try { attributes = File.GetAttributes(path); return true; }
        catch (FileNotFoundException) { attributes = 0; return false; }
        catch (DirectoryNotFoundException) { attributes = 0; return false; }
    }

    private static void NoReparse(string path)
    {
        string current = path;
        while (!String.IsNullOrEmpty(current))
        {
            FileAttributes attributes;
            if (TryAttributes(current, out attributes) && (attributes & FileAttributes.ReparsePoint) != 0)
                throw new PrivacyFailure("reparse");
            string parent = Path.GetDirectoryName(current);
            if (parent == current) break;
            current = parent;
        }
    }

    private static void SafeAncestors(string path)
    {
        string current = Path.GetDirectoryName(path);
        FileSystemRights replacement = FileSystemRights.Delete | FileSystemRights.DeleteSubdirectoriesAndFiles |
            FileSystemRights.ChangePermissions | FileSystemRights.TakeOwnership;
        while (!String.IsNullOrEmpty(current))
        {
            if (Directory.Exists(current))
            {
                FileSystemSecurity acl = Acl(current, true);
                IdentityReference owner = acl.GetOwner(typeof(SecurityIdentifier));
                if (!Trusted(owner) && !owner.Equals(ServicingSid)) throw new PrivacyFailure("privacy");
                foreach (FileSystemAccessRule rule in acl.GetAccessRules(true, true, typeof(SecurityIdentifier)))
                    if (rule.AccessControlType == AccessControlType.Allow && !Trusted(rule.IdentityReference) && !rule.IdentityReference.Equals(ServicingSid) &&
                        (rule.PropagationFlags & PropagationFlags.InheritOnly) == 0 && (rule.FileSystemRights & replacement) != 0)
                        throw new PrivacyFailure("privacy");
            }
            string parent = Path.GetDirectoryName(current);
            if (parent == current) break;
            current = parent;
        }
    }

    private static string LocalPath(string path)
    {
        string full = Path.GetFullPath(path);
        string root = Path.GetPathRoot(full);
        if (root.Length != 3 || root[1] != ':' || full.IndexOf(':', 2) >= 0)
            throw new PrivacyFailure("location");
        DriveInfo drive = new DriveInfo(root);
        if (drive.DriveType != DriveType.Fixed || (drive.DriveFormat != "NTFS" && drive.DriveFormat != "ReFS"))
            throw new PrivacyFailure("location");
        return full;
    }

    private static void PrepareDirectory(string directory)
    {
        if (Directory.Exists(directory)) { PrivateAcl(directory, true); return; }
        Stack<string> missing = new Stack<string>();
        string existing = directory;
        while (!Directory.Exists(existing))
        {
            missing.Push(existing);
            existing = Path.GetDirectoryName(existing);
            if (String.IsNullOrEmpty(existing)) throw new PrivacyFailure("location");
        }
        SafeCreationParent(existing);
        while (missing.Count > 0)
        {
            string next = missing.Pop();
            // .NET passes this DACL to directory creation; existing directories are not re-permissioned.
            Directory.CreateDirectory(next, NewDirectoryAcl());
            NoReparse(next);
            PrivateAcl(next, true);
        }
    }

    private static void Check(string path, bool prepare)
    {
        path = LocalPath(path);
        NoReparse(path);
        SafeAncestors(path);
        string directory = Path.GetDirectoryName(path);
        if (prepare) PrepareDirectory(directory);
        else PrivateAcl(directory, true);
        FileAttributes attributes;
        if (TryAttributes(path, out attributes))
        {
            if ((attributes & FileAttributes.Directory) != 0) throw new PrivacyFailure("regular_file");
            PrivateAcl(path, false);
        }
    }

    private static string Handle(string input, string helperDirectory)
    {
        try
        {
            // Every invocation checks its private executable directory before handling caller data.
            NoReparse(helperDirectory);
            SafeAncestors(helperDirectory);
            PrivateAcl(helperDirectory, true);
            if (input.Length > 32768) throw new PrivacyFailure("request");
            Dictionary<string, object> request = new JavaScriptSerializer().Deserialize<Dictionary<string, object>>(input);
            string action = request["action"] as string;
            string path = request["path"] as string;
            if (String.IsNullOrEmpty(path) || (action != "prepare" && action != "verify")) throw new PrivacyFailure("request");
            Check(path, action == "prepare");
            return "{\"ok\":true}";
        }
        catch (PrivacyFailure failure)
        {
            return "{\"ok\":false,\"reason\":\"" + failure.Reason + "\"}";
        }
        catch
        {
            return "{\"ok\":false,\"reason\":\"unavailable\"}";
        }
    }

    private static int Main(string[] args)
    {
        string directory = AppDomain.CurrentDomain.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar);
        if (args.Length == 0) { Console.WriteLine(Handle(Console.In.ReadToEnd(), directory)); return 0; }
        int parentId;
        if (args.Length != 2 || args[0] != "--serve" || !Int32.TryParse(args[1], out parentId) || parentId <= 0) return 1;
        try
        {
            Process parent = Process.GetProcessById(parentId);
            DateTime parentStarted = parent.StartTime;
            string requestPath = Path.Combine(directory, "request.json");
            string responsePath = Path.Combine(directory, "response.json");
            using (AutoResetEvent changed = new AutoResetEvent(false))
            using (FileSystemWatcher watcher = new FileSystemWatcher(directory, "request.json"))
            {
                watcher.Created += delegate { changed.Set(); };
                watcher.Renamed += delegate { changed.Set(); };
                watcher.EnableRaisingEvents = true;
                while (!parent.HasExited && parent.StartTime == parentStarted)
                {
                    if (!File.Exists(requestPath)) { changed.WaitOne(1000); continue; }
                    string result = Handle(ReadRequest(requestPath, directory), directory);
                    File.Delete(requestPath);
                    string temporaryResponse = responsePath + ".tmp";
                    using (FileStream output = new FileStream(temporaryResponse, FileMode.CreateNew, FileAccess.Write, FileShare.None))
                    using (StreamWriter writer = new StreamWriter(output)) { writer.Write(result); }
                    File.Move(temporaryResponse, responsePath);
                }
            }
            return 0;
        }
        catch { return 1; }
    }

    // A request file can stay locked for a short time after its rename, for example while a scanner or indexer
    // holds it. One second covers such holds and stays well inside the five second response deadline.
    private const int SharingConflictBudgetMs = 1000;

    private static string ReadRequest(string path, string directory)
    {
        Stopwatch deadline = Stopwatch.StartNew();
        IOException lastConflict = null;
        for (;;)
        {
            if (lastConflict != null && deadline.ElapsedMilliseconds >= SharingConflictBudgetMs) throw lastConflict;
            try
            {
                // Revalidate live privacy before every attempt, including after a sharing conflict.
                NoReparse(path);
                SafeAncestors(path);
                PrivateAcl(directory, true);
                PrivateAcl(path, false);
                return File.ReadAllText(path);
            }
            catch (IOException failure)
            {
                int code = failure.HResult & 0xffff;
                if ((code != 32 && code != 33) || deadline.ElapsedMilliseconds >= SharingConflictBudgetMs) throw;
                lastConflict = failure;
                Thread.Sleep(2);
            }
        }
    }
}
