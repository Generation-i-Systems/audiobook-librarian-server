#!/usr/bin/env bash
# Decodes the output of the audit-secrets workflow into a human-readable table
# and optionally writes a secrets file for re-import.
#
# Usage:
#   # Pipe raw workflow log output (timestamps are stripped automatically):
#   ./decode-audit-secrets.sh < audit-output.txt
#
#   # Write decoded values to a file (for gh secret set re-import):
#   ./decode-audit-secrets.sh --export secrets.env < audit-output.txt
#
# Capturing the log:
#   gh run view <run-id> --log --repo <owner/repo> > audit-raw.txt
#   ./decode-audit-secrets.sh --export secrets.env < audit-raw.txt
#
# Exit codes:
#   0  all set secrets decoded successfully
#   1  one or more secrets were fully redacted by GitHub and cannot be migrated

set -euo pipefail

EXPORT_FILE=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --export)
      EXPORT_FILE="$2"
      shift 2
      ;;
    *)
      echo "Unknown argument: $1" >&2
      exit 1
      ;;
  esac
done

decode_value() {
  local encoded="$1"
  echo -n "$encoded" | base64 -d | gunzip 2>/dev/null
}

printf "%-45s %-34s %8s  %s\n" "SECRET NAME" "MD5 FINGERPRINT" "LENGTH" "VALUE"
printf "%-45s %-34s %8s  %s\n" "-----------" "---------------" "------" "-----"

if [[ -n "$EXPORT_FILE" ]]; then
  > "$EXPORT_FILE"
fi

redacted_secrets=()
not_set_secrets=()

while IFS= read -r raw_line; do
  # Strip leading GitHub Actions timestamp (e.g. "2026-05-26T23:49:58.876Z ")
  line="${raw_line#*Z }"
  # Normal or partially-redacted fingerprint line
  if [[ "$line" =~ ^([A-Z0-9_]+):\ ([a-f0-9]{32}|\*+)\ \(([0-9]+|\*+)\ chars\)\ (.+)$ ]]; then
    name="${BASH_REMATCH[1]}"
    md5="${BASH_REMATCH[2]}"
    length="${BASH_REMATCH[3]}"
    encoded="${BASH_REMATCH[4]}"

    if [[ "$encoded" == *"***"* ]]; then
      # Base64 stream is corrupted — GitHub redacted part of the encoded payload
      printf "%-45s %-34s %8s  %s\n" "$name" "$md5" "$length" "*** REDACTED — must be set manually ***"
      redacted_secrets+=("$name")
    else
      value="$(decode_value "$encoded")"
      printf "%-45s %-34s %8s  %s\n" "$name" "$md5" "$length" "$value"
      if [[ -n "$EXPORT_FILE" ]]; then
        printf "%s=%s\n" "$name" "$value" >> "$EXPORT_FILE"
      fi
    fi
  elif [[ "$line" =~ ^([A-Z0-9_]+):\ NOT\ SET ]]; then
    name="${BASH_REMATCH[1]}"
    printf "%-45s %-34s %8s\n" "$name" "(not set)" ""
    not_set_secrets+=("$name")
  fi
done

echo ""

if [[ ${#not_set_secrets[@]} -gt 0 ]]; then
  echo "━━━  NOT SET (${#not_set_secrets[@]})  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  for s in "${not_set_secrets[@]}"; do
    echo "  $s"
  done
  echo ""
fi

if [[ ${#redacted_secrets[@]} -gt 0 ]]; then
  echo "━━━  MIGRATION BLOCKERS — GitHub redacted these values (${#redacted_secrets[@]})  ━━━━━━━━━━━━━━━━"
  echo "  These secrets are SET but their values could not be extracted."
  echo "  You must copy them manually before migrating."
  echo ""
  for s in "${redacted_secrets[@]}"; do
    echo "  !! $s"
  done
  echo ""
  if [[ -n "$EXPORT_FILE" ]]; then
    echo "  secrets.env was written but is INCOMPLETE — do not use it until the above are resolved."
  fi
  exit 1
fi

if [[ -n "$EXPORT_FILE" ]]; then
  echo "All secrets decoded successfully."
  echo ""
  echo "Secrets written to: $EXPORT_FILE"
  echo "To bulk-import into a new repo:"
  echo "  gh secret set --env-file $EXPORT_FILE --repo <owner/repo>"
  echo ""
  echo "Delete the file after import:"
  echo "  rm $EXPORT_FILE"
fi
