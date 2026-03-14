#!/bin/bash

# Define directories that need shared access
TARGET_DIRS=("storage" "bootstrap/cache")
WEB_GROUP="www-data"

echo "Applying deep permissions fix for Laravel (Scalable ACL version)..."

# Ensure we are in the project root
cd "$(dirname "$0")/.."

for DIR in "${TARGET_DIRS[@]}"; do
    if [ -d "$DIR" ]; then
        echo "Processing $DIR..."
        
        # 1. Change group ownership to www-data recursively
        sudo chgrp -R "$WEB_GROUP" "$DIR"
        
        # 2. Ensure both owner and group have full permissions
        sudo chmod -R ug+rwx "$DIR"
        
        # 3. Set setgid bit on directories so new files inherit the 'www-data' group
        sudo find "$DIR" -type d -exec chmod g+s {} +
        
        # 4. Clear any existing ACLs to start clean
        sudo setfacl -b -R "$DIR"
        
        # 5. Apply ACLs: Grant the group 'www-data' full access (for existing files)
        sudo setfacl -R -m "g:$WEB_GROUP:rwx" "$DIR"
        
        # 6. Set DEFAULT ACLs: Ensure future files/dirs inherit full group access
        # This is the key to scalability.
        sudo setfacl -dR -m "g:$WEB_GROUP:rwx" "$DIR"
        
        echo "Successfully updated $DIR"
    else
        echo "Warning: $DIR does not exist."
    fi
done

echo "Permissions fix complete."
echo "New files created by either the web server (www-data) or you (eric) will now be accessible to both."
