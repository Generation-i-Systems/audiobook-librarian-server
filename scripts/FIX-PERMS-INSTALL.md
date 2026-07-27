# fix_perms — Install Guide

`fix_perms` is a SUID root binary that corrects ownership and permissions on
audiobook library files after the web server creates or modifies them.  The
web server (Apache/Nginx running as `www-data`) cannot `chown` files to the
library owner, so a trusted, locked-down binary runs as root to bridge the gap.

All security-critical values — book root, target user, and target group — are
burned into the binary at compile time.  There is no runtime configuration.

---

## Bare-Metal Install

### 1. Compile

Replace the `-D` values to match your deployment:

```bash
gcc -O2 \
  -DALLOWED_ROOT_PATH='"/path/to/your/audiobooks"' \
  -DTARGET_USER='"your-unix-user"' \
  -DTARGET_GROUP='"audio"' \
  scripts/fix_perms.c -o scripts/fix_perms.new
```

The compile will fail with a clear `#error` if any of the three defines are
omitted — this is intentional.

### 2. Install with SUID

```bash
sudo chown root:audio scripts/fix_perms.new
sudo chmod 4750      scripts/fix_perms.new
sudo mv scripts/fix_perms.new scripts/fix_perms
```

`4750` means:
- `4` — SUID bit (runs as root regardless of caller)
- `7` — owner (root): read + write + execute
- `5` — group (audio): read + execute
- `0` — others: no access

Only members of the `audio` group can invoke the binary, which prevents
arbitrary users from using it as a privilege escalation vector.

### 3. Add the web server to the audio group

```bash
sudo usermod -aG audio www-data
# Restart the web server to pick up the new group
sudo systemctl restart apache2   # or nginx / php-fpm
```

### 4. Verify

```bash
ls -la scripts/fix_perms
# Expected: -rwsr-x--- 1 root audio ...

scripts/fix_perms /path/to/your/audiobooks/some-book
```

---

## Re-compiling After Path Changes

If `BOOK_STORAGE_PATH` changes, recompile and reinstall:

```bash
gcc -O2 \
  -DALLOWED_ROOT_PATH='"/new/path"' \
  -DTARGET_USER='"your-unix-user"' \
  -DTARGET_GROUP='"audio"' \
  scripts/fix_perms.c -o scripts/fix_perms.new

sudo chown root:audio scripts/fix_perms.new
sudo chmod 4750      scripts/fix_perms.new
sudo mv scripts/fix_perms.new scripts/fix_perms
```

`setup-demo.sh` handles this automatically when re-run.

---

## Docker Deployments

**SUID binaries are not suitable for Docker containers.**  Docker commonly
disables the SUID bit and `setuid(0)` via `--security-opt=no-new-privileges`,
and process isolation makes host-level `chown` meaningless from inside the
container anyway.  The compiled binary is excluded from Docker images via
`.dockerignore`.

The application falls back to PHP `chmod()` inside the container, which fixes
permissions but cannot change ownership.

### The ownership problem

The web server inside the container creates files owned by its UID (typically
`33` / `www-data`).  On the host, those files appear owned by UID `33` rather
than the library owner (`eric`), making them inaccessible to host-side tools
and the library user.

### Option A — Run the container as the library owner (recommended)

Pass the host user's UID and GID into the container so the web server writes
files with the correct ownership from the start:

```yaml
# docker-compose.yml
services:
  app:
    image: audiobook-librarian
    user: "${PUID}:${PGID}"
    environment:
      - PUID=${PUID}
      - PGID=${PGID}
    volumes:
      - /path/to/audiobooks:/audiobooks
```

Set `PUID` and `PGID` in `.env` or your shell before starting:

```bash
PUID=$(id -u eric) PGID=$(getent group audio | cut -d: -f3) docker compose up -d
```

The entrypoint should ensure `www-data` (or the app process) runs as that UID.
Many base images (e.g., `linuxserver.io`) already support `PUID`/`PGID`.

### Option B — Host cron job

A cron job on the host (outside Docker) periodically corrects ownership on the
mounted volume:

```cron
# /etc/cron.d/audiobook-librarian-perms
*/5 * * * * root find /path/to/audiobooks -not \( -user eric -group audio \) -exec chown eric:audio {} +
```

This runs every 5 minutes as root.  Files are correctable within 5 minutes of
creation rather than immediately, which is acceptable for most workflows.

To install:

```bash
sudo tee /etc/cron.d/audiobook-librarian-perms <<'EOF'
*/5 * * * * root find /path/to/audiobooks -not \( -user eric -group audio \) -exec chown eric:audio {} +
EOF
sudo chmod 644 /etc/cron.d/audiobook-librarian-perms
```

### Option C — Privileged Docker capability

Grant the container only `CAP_CHOWN` and `CAP_FOWNER` instead of running it
fully privileged.  The application can then call `chown()` directly via a
dedicated PHP process or artisan command:

```yaml
# docker-compose.yml
services:
  app:
    cap_add:
      - CHOWN
      - FOWNER
```

This is more targeted than `--privileged` but still grants elevated capability
to the whole container.  Prefer Option A when possible.

---

## Security Notes

- The binary is excluded from Docker images via `.dockerignore`
- It is not executable by users outside the `audio` group (`chmod 4750`)
- Path traversal is prevented by `realpath()` + prefix-with-separator check
- All parameters are compile-time constants — no environment variables or
  config files are read at runtime
