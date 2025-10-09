#!/bin/bash
# Smart mv alias - Add this to your ~/.bashrc or ~/.zshrc
# Cascades: book-mv -> mkdmv -> mv

mv() {
    local book_mv_script="/home/eric-22/PhpstormProjects/ab5/scripts/book-mv.sh"
    local mkdmv_script="$HOME/tools/mkdmv"
    
    # Try book-mv first (for book directories with DB updates)
    if [ -x "$book_mv_script" ]; then
        "$book_mv_script" "$@"
        local exit_code=$?
        
        # Exit code 2 means "not a book, use fallback"
        if [ $exit_code -ne 2 ]; then
            return $exit_code
        fi
    fi
    
    # Fall back to mkdmv if available (creates parent dirs)
    if [ -x "$mkdmv_script" ]; then
        "$mkdmv_script" "$@"
        return $?
    fi
    
    # Final fallback to regular mv
    command mv "$@"
}
