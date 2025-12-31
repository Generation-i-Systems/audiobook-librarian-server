#!/usr/bin/env python3
"""
Fix File Permissions and Ownership

Reads paths from a queue file and fixes their permissions and ownership.
Removes successfully processed paths from the queue.

Target ownership: eric:audio
Target permissions: 0775 for directories, 0664 for files

Usage:
    python3 fix-permissions.py [--queue-file PATH] [--dry-run] [--verbose]

Cron example (run every 5 minutes):
    */5 * * * * /usr/bin/python3 /path/to/fix-permissions.py >> /path/to/fix-permissions.log 2>&1
"""

import os
import sys
import pwd
import grp
import stat
import argparse
from datetime import datetime
from pathlib import Path
from typing import List, Tuple, Set

# Configuration
DEFAULT_QUEUE_FILE = '/home/eric-shared/PhpstormProjects/librarian/storage/app/permissions-queue.txt'
TARGET_USER = 'eric'
TARGET_GROUP = 'audio'
DIR_MODE = 0o775
FILE_MODE = 0o664


def log(message: str, verbose: bool = True):
    """Print timestamped log message"""
    if verbose:
        timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        print(f"[{timestamp}] {message}")


def get_uid_gid() -> Tuple[int, int]:
    """Get UID and GID for target user and group"""
    try:
        uid = pwd.getpwnam(TARGET_USER).pw_uid
        gid = grp.getgrnam(TARGET_GROUP).gr_gid
        return uid, gid
    except KeyError as e:
        raise RuntimeError(f"User '{TARGET_USER}' or group '{TARGET_GROUP}' not found: {e}")


def read_queue(queue_file: Path) -> List[str]:
    """Read paths from queue file, ignoring comments and empty lines"""
    if not queue_file.exists():
        return []

    paths = []
    with open(queue_file, 'r') as f:
        for line in f:
            line = line.strip()
            # Skip comments and empty lines
            if line and not line.startswith('#'):
                paths.append(line)

    return paths


def write_queue(queue_file: Path, paths: List[str]):
    """Write remaining paths back to queue file"""
    with open(queue_file, 'w') as f:
        f.write("# File Permissions and Ownership Queue\n")
        f.write("# Add file/directory paths here (one per line) to be fixed by the cron job\n")
        f.write("# Format: /absolute/path/to/file-or-directory\n")
        f.write("# Lines starting with # are ignored\n")
        f.write("# Paths are automatically removed once successfully fixed\n")
        f.write("#\n")
        f.write(f"# Target ownership: {TARGET_USER}:{TARGET_GROUP}\n")
        f.write(f"# Target permissions: {oct(DIR_MODE)} for directories, {oct(FILE_MODE)} for files\n")
        f.write("#\n")
        for path in paths:
            f.write(f"{path}\n")


def check_needs_fix(path: Path, uid: int, gid: int) -> Tuple[bool, bool]:
    """
    Check if path needs ownership or permission fixes
    Returns (needs_chown, needs_chmod)
    """
    try:
        st = path.stat()
        current_uid = st.st_uid
        current_gid = st.st_gid
        current_mode = stat.S_IMODE(st.st_mode)

        needs_chown = (current_uid != uid) or (current_gid != gid)

        if path.is_dir():
            needs_chmod = current_mode != DIR_MODE
        else:
            needs_chmod = current_mode != FILE_MODE

        return needs_chown, needs_chmod
    except (FileNotFoundError, PermissionError) as e:
        return False, False


def fix_permissions(path: Path, uid: int, gid: int, dry_run: bool, verbose: bool) -> bool:
    """
    Fix ownership and permissions for a single path
    Returns True if successful or path doesn't exist, False on error
    """
    if not path.exists():
        log(f"SKIP (not found): {path}", verbose)
        return True  # Remove from queue since it doesn't exist

    try:
        needs_chown, needs_chmod = check_needs_fix(path, uid, gid)

        if not needs_chown and not needs_chmod:
            log(f"OK (already correct): {path}", verbose)
            return True

        actions = []
        if needs_chown:
            actions.append(f"chown {TARGET_USER}:{TARGET_GROUP}")
        if needs_chmod:
            target_mode = DIR_MODE if path.is_dir() else FILE_MODE
            actions.append(f"chmod {oct(target_mode)}")

        action_str = " + ".join(actions)

        if dry_run:
            log(f"DRY-RUN: {action_str} → {path}", verbose)
            return False  # Don't remove from queue in dry-run mode

        # Apply fixes
        if needs_chown:
            os.chown(path, uid, gid)

        if needs_chmod:
            if path.is_dir():
                path.chmod(DIR_MODE)
            else:
                path.chmod(FILE_MODE)

        log(f"FIXED ({action_str}): {path}", verbose)
        return True

    except PermissionError as e:
        log(f"ERROR (permission denied): {path} - {e}", verbose)
        return False  # Keep in queue to retry
    except Exception as e:
        log(f"ERROR: {path} - {e}", verbose)
        return False  # Keep in queue to retry


def fix_directory_recursive(path: Path, uid: int, gid: int, dry_run: bool, verbose: bool) -> bool:
    """
    Fix permissions for directory and all its contents recursively
    Returns True if all successful
    """
    all_success = True

    try:
        # Fix the directory itself
        if not fix_permissions(path, uid, gid, dry_run, verbose):
            all_success = False

        # Fix all contents recursively
        for item in path.rglob('*'):
            if not fix_permissions(item, uid, gid, dry_run, verbose):
                all_success = False
    except Exception as e:
        log(f"ERROR (recursive): {path} - {e}", verbose)
        all_success = False

    return all_success


def main():
    parser = argparse.ArgumentParser(description='Fix file permissions and ownership from queue')
    parser.add_argument('--queue-file', type=Path, default=DEFAULT_QUEUE_FILE,
                        help=f'Path to queue file (default: {DEFAULT_QUEUE_FILE})')
    parser.add_argument('--dry-run', action='store_true',
                        help='Show what would be done without making changes')
    parser.add_argument('--verbose', action='store_true', default=True,
                        help='Enable verbose output (default: True)')
    parser.add_argument('--quiet', action='store_true',
                        help='Suppress output except errors')
    parser.add_argument('--recursive', action='store_true',
                        help='Fix directories recursively (all contents)')

    args = parser.parse_args()
    verbose = args.verbose and not args.quiet

    # Get target UID/GID
    try:
        uid, gid = get_uid_gid()
    except RuntimeError as e:
        log(f"ERROR: {e}", True)
        return 1

    # Read queue
    queue_file = args.queue_file
    paths = read_queue(queue_file)

    if not paths:
        log("Queue is empty, nothing to do", verbose)
        return 0

    log(f"Processing {len(paths)} path(s) from {queue_file}", verbose)
    if args.dry_run:
        log("DRY RUN MODE - no changes will be made", verbose)

    # Process each path
    remaining_paths = []
    fixed_count = 0
    error_count = 0

    for path_str in paths:
        path = Path(path_str)

        if args.recursive and path.is_dir():
            success = fix_directory_recursive(path, uid, gid, args.dry_run, verbose)
        else:
            success = fix_permissions(path, uid, gid, args.dry_run, verbose)

        if success:
            fixed_count += 1
        else:
            remaining_paths.append(path_str)
            error_count += 1

    # Write back remaining paths (those that had errors or in dry-run mode)
    if args.dry_run:
        log(f"DRY RUN: Would have removed {fixed_count} path(s) from queue", verbose)
    else:
        write_queue(queue_file, remaining_paths)
        log(f"Removed {fixed_count} fixed path(s) from queue", verbose)

    if error_count > 0:
        log(f"WARNING: {error_count} path(s) had errors and remain in queue", verbose)

    log("Done", verbose)
    return 0


if __name__ == '__main__':
    sys.exit(main())
