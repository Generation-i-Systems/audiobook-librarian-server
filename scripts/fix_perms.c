#define _XOPEN_SOURCE 500
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <sys/types.h>
#include <sys/stat.h>
#include <ftw.h>
#include <limits.h>
#include <errno.h>
#include <pwd.h>
#include <grp.h>

/**
 * Targeted SUID tool to fix ownership and permissions for audiobook files.
 * Performs recursive chown(eric:audio) and chmod(775/664).
 * 
 * Compile with: gcc -O2 fix_perms.c -o fix_perms
 * Setup: sudo chown root:audio fix_perms && sudo chmod 4750 fix_perms
 */

// Configuration
const char *ALLOWED_ROOT = "/media/audiobooks/books";
const char *TARGET_USER = "eric";
const char *TARGET_GROUP = "audio";

// Globals to store looked-up IDs
uid_t target_uid = -1;
gid_t target_gid = -1;

int process_item(const char *fpath, const struct stat *sb, int tflag, struct FTW *ftwbuf) {
    // 1. Set ownership
    if (chown(fpath, target_uid, target_gid) != 0) {
        fprintf(stderr, "Warning: Failed to chown %s: %s\n", fpath, strerror(errno));
    }

    // 2. Set permissions
    mode_t mode = S_ISDIR(sb->st_mode) ? 0775 : 0664;
    if (chmod(fpath, mode) != 0) {
        fprintf(stderr, "Warning: Failed to chmod %s: %s\n", fpath, strerror(errno));
    }

    return 0; // Continue traversal
}

int main(int argc, char *argv[]) {
    if (argc < 2) {
        fprintf(stderr, "Usage: %s <path1> [path2] ... [pathN]\n", argv[0]);
        return 1;
    }

    // Lookup UID
    struct passwd *pwd = getpwnam(TARGET_USER);
    if (pwd == NULL) {
        fprintf(stderr, "Error: User '%s' not found on system\n", TARGET_USER);
        return 1;
    }
    target_uid = pwd->pw_uid;

    // Lookup GID
    struct group *grp = getgrnam(TARGET_GROUP);
    if (grp == NULL) {
        fprintf(stderr, "Error: Group '%s' not found on system\n", TARGET_GROUP);
        return 1;
    }
    target_gid = grp->gr_gid;

    // Resolve ALLOWED_ROOT to handle symlinks (e.g. /media/audiobooks -> /media/lyra_data1)
    char resolved_allowed_root[PATH_MAX];
    if (realpath(ALLOWED_ROOT, resolved_allowed_root) == NULL) {
        fprintf(stderr, "Error: Failed to resolve allowed root '%s': %s\n", ALLOWED_ROOT, strerror(errno));
        return 1;
    }

    // Drop privileges to root (set real UID to 0)
    // SUID bit will make effective UID 0, but we want real UID 0 for children if any
    if (setuid(0) != 0) {
        perror("setuid");
        return 1;
    }

    int overall_success = 0;

    for (int i = 1; i < argc; i++) {
        char *input_path = argv[i];
        char resolved_path[PATH_MAX];
        char absolute_target[PATH_MAX];

        // Build absolute path if input is relative
        if (input_path[0] != '/') {
            snprintf(absolute_target, sizeof(absolute_target), "%s/%s", ALLOWED_ROOT, input_path);
        } else {
            strncpy(absolute_target, input_path, sizeof(absolute_target));
        }

        // Resolve realpath to prevent ".." traversal attacks
        if (realpath(absolute_target, resolved_path) == NULL) {
            fprintf(stderr, "Warning: Failed to resolve path '%s': %s\n", input_path, strerror(errno));
            overall_success = 1;
            continue;
        }

        // Security: Ensure resolved path is inside resolved_allowed_root
        if (strncmp(resolved_path, resolved_allowed_root, strlen(resolved_allowed_root)) != 0) {
            fprintf(stderr, "Security Error: Path '%s' is outside allowed root '%s' (resolved: %s)\n", resolved_path, ALLOWED_ROOT, resolved_allowed_root);
            overall_success = 1;
            continue;
        }

        printf("Fixing permissions recursively: %s\n", resolved_path);

        // Recursive traversal (using 16 file descriptors max)
        if (nftw(resolved_path, process_item, 16, FTW_PHYS) != 0) {
            fprintf(stderr, "Error traversing path '%s': %s\n", resolved_path, strerror(errno));
            overall_success = 1;
        }
    }

    if (overall_success == 0) {
        printf("Successfully updated ownership and permissions for all paths.\n");
    } else {
        printf("Completed with some errors.\n");
    }

    return overall_success;
}
