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
 * Performs recursive chown(USER:GROUP) and chmod(775/664).
 *
 * All security-critical values must be set at compile time. The binary is
 * locked to a specific user, group, and directory tree — runtime configuration
 * is intentionally not supported.
 *
 * Compile with:
 *   gcc -O2 \
 *     -DALLOWED_ROOT_PATH='"/path/to/books"' \
 *     -DTARGET_USER='"username"' \
 *     -DTARGET_GROUP='"groupname"' \
 *     fix_perms.c -o fix_perms
 *
 * Setup:
 *   sudo chown root:<group> fix_perms && sudo chmod 4750 fix_perms
 */

#ifndef ALLOWED_ROOT_PATH
#error "ALLOWED_ROOT_PATH must be set at compile time: -DALLOWED_ROOT_PATH='\"/path/to/books\"'"
#endif

#ifndef TARGET_USER
#error "TARGET_USER must be set at compile time: -DTARGET_USER='\"username\"'"
#endif

#ifndef TARGET_GROUP
#error "TARGET_GROUP must be set at compile time: -DTARGET_GROUP='\"groupname\"'"
#endif

uid_t target_uid = (uid_t)-1;
gid_t target_gid = (gid_t)-1;

int process_item(const char *fpath, const struct stat *sb, int tflag, struct FTW *ftwbuf) {
    if (chown(fpath, target_uid, target_gid) != 0) {
        fprintf(stderr, "Warning: Failed to chown %s: %s\n", fpath, strerror(errno));
    }

    mode_t mode = S_ISDIR(sb->st_mode) ? 0775 : 0664;
    if (chmod(fpath, mode) != 0) {
        fprintf(stderr, "Warning: Failed to chmod %s: %s\n", fpath, strerror(errno));
    }

    return 0;
}

int main(int argc, char *argv[]) {
    if (argc < 2) {
        fprintf(stderr, "Usage: %s <path1> [path2] ... [pathN]\n", argv[0]);
        return 1;
    }

    struct passwd *pwd = getpwnam(TARGET_USER);
    if (pwd == NULL) {
        fprintf(stderr, "Error: User '%s' not found on system\n", TARGET_USER);
        return 1;
    }
    target_uid = pwd->pw_uid;

    struct group *grp = getgrnam(TARGET_GROUP);
    if (grp == NULL) {
        fprintf(stderr, "Error: Group '%s' not found on system\n", TARGET_GROUP);
        return 1;
    }
    target_gid = grp->gr_gid;

    // Resolve ALLOWED_ROOT_PATH to handle symlinks
    char resolved_allowed_root[PATH_MAX];
    if (realpath(ALLOWED_ROOT_PATH, resolved_allowed_root) == NULL) {
        fprintf(stderr, "Error: Failed to resolve allowed root '%s': %s\n", ALLOWED_ROOT_PATH, strerror(errno));
        return 1;
    }

    if (setuid(0) != 0) {
        perror("setuid");
        return 1;
    }

    int overall_success = 0;

    for (int i = 1; i < argc; i++) {
        char *input_path = argv[i];
        char resolved_path[PATH_MAX];
        char absolute_target[PATH_MAX];

        if (input_path[0] != '/') {
            snprintf(absolute_target, sizeof(absolute_target), "%s/%s", ALLOWED_ROOT_PATH, input_path);
        } else {
            strncpy(absolute_target, input_path, sizeof(absolute_target));
            absolute_target[sizeof(absolute_target) - 1] = '\0';
        }

        if (realpath(absolute_target, resolved_path) == NULL) {
            fprintf(stderr, "Warning: Failed to resolve path '%s': %s\n", input_path, strerror(errno));
            overall_success = 1;
            continue;
        }

        size_t root_len = strlen(resolved_allowed_root);
        if (strncmp(resolved_path, resolved_allowed_root, root_len) != 0 ||
            (resolved_path[root_len] != '\0' && resolved_path[root_len] != '/')) {
            fprintf(stderr, "Security Error: Path '%s' is outside allowed root '%s'\n", resolved_path, resolved_allowed_root);
            overall_success = 1;
            continue;
        }

        printf("Fixing permissions recursively: %s\n", resolved_path);

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
