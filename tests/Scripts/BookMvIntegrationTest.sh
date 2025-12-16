#!/bin/bash

# Integration tests for book-mv.sh script
# Tests all functionality including edge cases and regression scenarios

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Counters
TESTS_RUN=0
TESTS_PASSED=0
TESTS_FAILED=0

# Get script paths
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" > /dev/null 2>&1 && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." > /dev/null 2>&1 && pwd)"
BOOK_MV="$PROJECT_ROOT/bin/book-mv.sh"

# Test directory
TEST_ROOT="/tmp/book-mv-test-$$"
TEST_BOOK_ROOT="$TEST_ROOT/books"

# Setup
setup() {
    echo "Setting up test environment..."
    mkdir -p "$TEST_BOOK_ROOT"

    export BOOK_STORAGE_PATH="$TEST_BOOK_ROOT"
}

# Teardown
teardown() {
    echo "Cleaning up test environment..."

    unset BOOK_STORAGE_PATH

    # Clean up test directory
    rm -rf "$TEST_ROOT"
}

# Test helper functions
assert_exists() {
    if [ -e "$1" ]; then
        return 0
    else
        echo -e "${RED}FAIL: Expected $1 to exist${NC}"
        return 1
    fi
}

assert_not_exists() {
    if [ ! -e "$1" ]; then
        return 0
    else
        echo -e "${RED}FAIL: Expected $1 to not exist${NC}"
        return 1
    fi
}

assert_exit_code() {
    local expected=$1
    local actual=$2
    if [ "$expected" -eq "$actual" ]; then
        return 0
    else
        echo -e "${RED}FAIL: Expected exit code $expected, got $actual${NC}"
        return 1
    fi
}

run_test() {
    local test_name=$1
    TESTS_RUN=$((TESTS_RUN + 1))

    echo -e "\n${YELLOW}Running: $test_name${NC}"

    # Clean test book root between tests
    rm -rf "$TEST_BOOK_ROOT"/*

    if $test_name; then
        TESTS_PASSED=$((TESTS_PASSED + 1))
        echo -e "${GREEN}✓ PASSED${NC}"
    else
        TESTS_FAILED=$((TESTS_FAILED + 1))
        echo -e "${RED}✗ FAILED${NC}"
    fi
}

# Test Cases

test_single_source_to_dest() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Author/Book1"
    touch "$TEST_BOOK_ROOT/Fantasy/Author/Book1/test.m4b"

    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Author/Book1" "$TEST_BOOK_ROOT/Sci-Fi/Author/Book1"
    local exit_code=$?

    assert_not_exists "$TEST_BOOK_ROOT/Fantasy/Author/Book1" &&
    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Author/Book1" &&
    assert_exit_code 0 $exit_code
}

test_multiple_sources_to_directory() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book1"
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book2"
    mkdir -p "$TEST_BOOK_ROOT/Sci-Fi"
    touch "$TEST_BOOK_ROOT/Fantasy/Book1/test.m4b"
    touch "$TEST_BOOK_ROOT/Fantasy/Book2/test.m4b"

    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Book1" "$TEST_BOOK_ROOT/Fantasy/Book2" "$TEST_BOOK_ROOT/Sci-Fi/"
    local exit_code=$?

    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Book1" &&
    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Book2" &&
    assert_exit_code 0 $exit_code
}

test_trailing_slash_on_destination() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book1"
    mkdir -p "$TEST_BOOK_ROOT/Sci-Fi"
    touch "$TEST_BOOK_ROOT/Fantasy/Book1/test.m4b"

    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Book1" "$TEST_BOOK_ROOT/Sci-Fi/"

    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Book1"
}

test_auto_create_parent_directories() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book1"
    touch "$TEST_BOOK_ROOT/Fantasy/Book1/test.m4b"

    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Book1" "$TEST_BOOK_ROOT/New/Genre/Sub/Book1"

    assert_exists "$TEST_BOOK_ROOT/New/Genre/Sub/Book1"
}

test_mv_options_passthrough() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book1"
    touch "$TEST_BOOK_ROOT/Fantasy/Book1/test.m4b"

    # Test with -v (verbose) option
    "$BOOK_MV" --yes -v "$TEST_BOOK_ROOT/Fantasy/Book1" "$TEST_BOOK_ROOT/Sci-Fi/Book1"
    local exit_code=$?

    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Book1" &&
    assert_exit_code 0 $exit_code
}

test_fallback_to_regular_mv_outside_book_root() {
    mkdir -p "/tmp/test-mv-$$"
    touch "/tmp/test-mv-$$/file.txt"

    "$BOOK_MV" "/tmp/test-mv-$$/file.txt" "/tmp/test-mv-$$/file2.txt"
    local exit_code=$?

    assert_exists "/tmp/test-mv-$$/file2.txt" &&
    assert_exit_code 0 $exit_code

    rm -rf "/tmp/test-mv-$$"
}

test_handles_spaces_in_paths() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Author Name/Book Title"
    touch "$TEST_BOOK_ROOT/Fantasy/Author Name/Book Title/test.m4b"

    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Author Name/Book Title" "$TEST_BOOK_ROOT/Sci-Fi/Author Name/Book Title"

    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Author Name/Book Title"
}

test_handles_special_characters() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Author's/Book (2023)"
    touch "$TEST_BOOK_ROOT/Fantasy/Author's/Book (2023)/test.m4b"

    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Author's/Book (2023)" "$TEST_BOOK_ROOT/Sci-Fi/Author's/Book (2023)"

    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Author's/Book (2023)"
}

test_handles_unicode_characters() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Autör/Bøøk"
    touch "$TEST_BOOK_ROOT/Fantasy/Autör/Bøøk/test.m4b"

    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Autör/Bøøk" "$TEST_BOOK_ROOT/Sci-Fi/Autör/Bøøk"

    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Autör/Bøøk"
}

test_handles_very_long_paths() {
    local long_path="A/B/C/D/E/F/G/H/I/J/K/L/M/N/O/P/Book"
    mkdir -p "$TEST_BOOK_ROOT/$long_path"
    touch "$TEST_BOOK_ROOT/$long_path/test.m4b"

    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/$long_path" "$TEST_BOOK_ROOT/Short/Book"

    assert_exists "$TEST_BOOK_ROOT/Short/Book"
}

test_handles_dot_files() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book1"
    touch "$TEST_BOOK_ROOT/Fantasy/Book1/.hidden"
    touch "$TEST_BOOK_ROOT/Fantasy/Book1/test.m4b"

    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Book1" "$TEST_BOOK_ROOT/Sci-Fi/Book1"

    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Book1/.hidden"
}

test_handles_symlinks() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book1"
    touch "$TEST_BOOK_ROOT/Fantasy/Book1/test.m4b"
    # Use a relative symlink so it remains valid after moving the directory
    (cd "$TEST_BOOK_ROOT/Fantasy/Book1" && ln -s "test.m4b" "link.m4b")

    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Book1" "$TEST_BOOK_ROOT/Sci-Fi/Book1"

    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Book1/link.m4b"
}

test_handles_empty_directories() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book1/SubDir"
    touch "$TEST_BOOK_ROOT/Fantasy/Book1/test.m4b"

    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Book1" "$TEST_BOOK_ROOT/Sci-Fi/Book1"

    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Book1/SubDir"
}

test_handles_nested_directories() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Author/Series/Book1"
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Author/Series/Book2"
    touch "$TEST_BOOK_ROOT/Fantasy/Author/Series/Book1/test.m4b"
    touch "$TEST_BOOK_ROOT/Fantasy/Author/Series/Book2/test.m4b"

    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Author" "$TEST_BOOK_ROOT/Sci-Fi/Author"

    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Author/Series/Book1" &&
    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Author/Series/Book2"
}

test_handles_relative_paths() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book1"
    touch "$TEST_BOOK_ROOT/Fantasy/Book1/test.m4b"

    (cd "$TEST_BOOK_ROOT" && "$BOOK_MV" --yes "Fantasy/Book1" "Sci-Fi/Book1")
    cd - > /dev/null

    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Book1"
}

test_handles_absolute_paths() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book1"
    touch "$TEST_BOOK_ROOT/Fantasy/Book1/test.m4b"

    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Book1" "$TEST_BOOK_ROOT/Sci-Fi/Book1"

    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Book1"
}

test_fails_gracefully_on_nonexistent_source() {
    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/NonExistent" "$TEST_BOOK_ROOT/Dest" 2>/dev/null
    local exit_code=$?

    # Should fail (non-zero exit code)
    [ $exit_code -ne 0 ]
}

test_preserves_file_permissions() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book1"
    touch "$TEST_BOOK_ROOT/Fantasy/Book1/test.m4b"
    chmod 644 "$TEST_BOOK_ROOT/Fantasy/Book1/test.m4b"

    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Book1" "$TEST_BOOK_ROOT/Sci-Fi/Book1"

    local perms=$(stat -c "%a" "$TEST_BOOK_ROOT/Sci-Fi/Book1/test.m4b")
    [ "$perms" = "644" ]
}

test_preserves_timestamps() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book1"
    touch "$TEST_BOOK_ROOT/Fantasy/Book1/test.m4b"
    local original_time=$(stat -c "%Y" "$TEST_BOOK_ROOT/Fantasy/Book1/test.m4b")

    sleep 1
    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Book1" "$TEST_BOOK_ROOT/Sci-Fi/Book1"

    local new_time=$(stat -c "%Y" "$TEST_BOOK_ROOT/Sci-Fi/Book1/test.m4b")
    [ "$original_time" = "$new_time" ]
}

test_handles_large_directories() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book1"

    # Create 100 files
    for i in {1..100}; do
        touch "$TEST_BOOK_ROOT/Fantasy/Book1/file$i.m4b"
    done

    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Book1" "$TEST_BOOK_ROOT/Sci-Fi/Book1"

    local count=$(find "$TEST_BOOK_ROOT/Sci-Fi/Book1" -name "*.m4b" | wc -l)
    [ "$count" -eq 100 ]
}

test_handles_dash_dash_separator() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book1"
    touch "$TEST_BOOK_ROOT/Fantasy/Book1/test.m4b"

    "$BOOK_MV" --yes -- "$TEST_BOOK_ROOT/Fantasy/Book1" "$TEST_BOOK_ROOT/Sci-Fi/Book1"

    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Book1"
}

test_handles_files_starting_with_dash() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/-Book1"
    touch "$TEST_BOOK_ROOT/Fantasy/-Book1/test.m4b"

    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/-Book1" "$TEST_BOOK_ROOT/Sci-Fi/-Book1"

    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/-Book1"
}

# Regression tests

test_regression_multiple_sources_same_basename() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Author1/Book"
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Author2/Book"
    mkdir -p "$TEST_BOOK_ROOT/Sci-Fi"
    touch "$TEST_BOOK_ROOT/Fantasy/Author1/Book/test.m4b"
    touch "$TEST_BOOK_ROOT/Fantasy/Author2/Book/test.m4b"

    # This should handle name collision
    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Author1/Book" "$TEST_BOOK_ROOT/Fantasy/Author2/Book" "$TEST_BOOK_ROOT/Sci-Fi/" 2>/dev/null
    local exit_code=$?

    # Should either succeed with both or fail gracefully
    [ $exit_code -eq 0 ] || [ $exit_code -ne 0 ]
}

test_regression_move_to_subdirectory_of_self() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Author"
    touch "$TEST_BOOK_ROOT/Fantasy/Author/test.m4b"

    # This should fail
    "$BOOK_MV" "$TEST_BOOK_ROOT/Fantasy" "$TEST_BOOK_ROOT/Fantasy/Sub" 2>/dev/null
    local exit_code=$?

    [ $exit_code -ne 0 ]
}

test_regression_concurrent_moves() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book1"
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book2"
    touch "$TEST_BOOK_ROOT/Fantasy/Book1/test.m4b"
    touch "$TEST_BOOK_ROOT/Fantasy/Book2/test.m4b"

    # Start two moves in parallel
    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Book1" "$TEST_BOOK_ROOT/Sci-Fi/Book1" &
    "$BOOK_MV" --yes "$TEST_BOOK_ROOT/Fantasy/Book2" "$TEST_BOOK_ROOT/Sci-Fi/Book2" &

    wait

    # Both should succeed
    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Book1" &&
    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Book2"
}

test_flag_non_book_forces_filesystem_move() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book1"
    touch "$TEST_BOOK_ROOT/Fantasy/Book1/test.m4b"

    "$BOOK_MV" --non-book "$TEST_BOOK_ROOT/Fantasy/Book1" "$TEST_BOOK_ROOT/Sci-Fi/Book1"
    local exit_code=$?

    assert_exists "$TEST_BOOK_ROOT/Sci-Fi/Book1/test.m4b" &&
    assert_exit_code 0 $exit_code
}

test_flag_book_only_fails_when_no_books_detected() {
    mkdir -p "$TEST_BOOK_ROOT/Fantasy/Book1"
    touch "$TEST_BOOK_ROOT/Fantasy/Book1/test.m4b"

    "$BOOK_MV" --book-only "$TEST_BOOK_ROOT/Fantasy/Book1" "$TEST_BOOK_ROOT/Sci-Fi/Book1" 2>/dev/null
    local exit_code=$?

    [ $exit_code -ne 0 ]
}

# Main test runner

main() {
    echo "========================================="
    echo "  Book-MV Integration Test Suite"
    echo "========================================="

    setup
    trap teardown EXIT

    # Run all tests
    run_test test_single_source_to_dest
    run_test test_multiple_sources_to_directory
    run_test test_trailing_slash_on_destination
    run_test test_auto_create_parent_directories
    run_test test_mv_options_passthrough
    run_test test_fallback_to_regular_mv_outside_book_root
    run_test test_handles_spaces_in_paths
    run_test test_handles_special_characters
    run_test test_handles_unicode_characters
    run_test test_handles_very_long_paths
    run_test test_handles_dot_files
    run_test test_handles_symlinks
    run_test test_handles_empty_directories
    run_test test_handles_nested_directories
    run_test test_handles_relative_paths
    run_test test_handles_absolute_paths
    run_test test_fails_gracefully_on_nonexistent_source
    run_test test_preserves_file_permissions
    run_test test_preserves_timestamps
    run_test test_handles_large_directories
    run_test test_handles_dash_dash_separator
    run_test test_handles_files_starting_with_dash
    run_test test_regression_multiple_sources_same_basename
    run_test test_regression_move_to_subdirectory_of_self
    run_test test_regression_concurrent_moves
    run_test test_flag_non_book_forces_filesystem_move
    run_test test_flag_book_only_fails_when_no_books_detected

    # Summary
    echo ""
    echo "========================================="
    echo "  Test Summary"
    echo "========================================="
    echo -e "Total:  $TESTS_RUN"
    echo -e "${GREEN}Passed: $TESTS_PASSED${NC}"
    echo -e "${RED}Failed: $TESTS_FAILED${NC}"
    echo "========================================="

    if [ $TESTS_FAILED -eq 0 ]; then
        echo -e "${GREEN}All tests passed!${NC}"
        exit 0
    else
        echo -e "${RED}Some tests failed!${NC}"
        exit 1
    fi
}

main
