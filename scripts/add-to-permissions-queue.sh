#!/bin/bash
#
# Add paths to the permissions queue
#
# Usage:
#   ./add-to-permissions-queue.sh /path/to/file-or-directory
#   ./add-to-permissions-queue.sh /path/one /path/two /path/three
#

QUEUE_FILE="/home/eric-shared/PhpstormProjects/librarian/storage/app/permissions-queue.txt"

if [ $# -eq 0 ]; then
    echo "Usage: $0 <path> [path2] [path3] ..."
    echo "Add one or more paths to the permissions queue"
    exit 1
fi

# Ensure queue file exists
if [ ! -f "$QUEUE_FILE" ]; then
    echo "ERROR: Queue file not found: $QUEUE_FILE"
    exit 1
fi

# Add each path to the queue
for path in "$@"; do
    # Convert to absolute path if relative
    if [[ "$path" != /* ]]; then
        path="$(realpath "$path" 2>/dev/null || echo "$path")"
    fi

    # Check if path already in queue
    if grep -qxF "$path" "$QUEUE_FILE"; then
        echo "Already in queue: $path"
    else
        echo "$path" >> "$QUEUE_FILE"
        echo "Added to queue: $path"
    fi
done

echo "Queue file: $QUEUE_FILE"
