<?php

declare(strict_types=1);

namespace App\Enums;

enum LibraryRepairIssueType: string
{
    case MISSING_DIRECTORY = 'missing_directory';
    case ORPHAN_DIRECTORY = 'orphan_directory';
    case DUPLICATE_DIRECTORY = 'duplicate_directory';
    case NESTED_AUDIO = 'nested_audio';
    case NUMBERED_SUFFIX_DIRECTORY = 'numbered_suffix_directory';
    case BOGUS_DIRECTORY = 'bogus_directory';
    case INVALID_AUDIO = 'invalid_audio';
}
