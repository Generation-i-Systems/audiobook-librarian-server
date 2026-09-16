<?php

declare(strict_types=1);

namespace App\Enums;

enum PermissionKey: string
{
    // Phase 1 — simple content entities
    case MANAGE_AUTHORS = 'manage-authors';
    case MANAGE_GENRES = 'manage-genres';
    case MANAGE_SERIES = 'manage-series';
    case MANAGE_TAGS = 'manage-tags';
    case MANAGE_BADGES = 'manage-badges';

    // Phase 2 — books
    case MANAGE_BOOKS = 'manage-books';

    // Phase 3 — users
    case MANAGE_USERS = 'manage-users';

    // Phase 4 — remaining infra/misc admin features
    case ACCESS_ADMINER = 'access-adminer';
    case ACCESS_HORIZON = 'access-horizon';
    case MANAGE_QUEUE = 'manage-queue';
    case ACCESS_AI_TOOLS = 'access-ai-tools';
    case MANAGE_LIBRARY_REPAIR = 'manage-library-repair';
    case MANAGE_DIRECTORY_VALIDATION = 'manage-directory-validation';
    case MANAGE_TRASH = 'manage-trash';
    case MANAGE_ACCOUNT_REQUESTS = 'manage-account-requests';
    case MANAGE_MESSAGES = 'manage-messages';
    case ACCESS_DEBUG_TOOLS = 'access-debug-tools';

    public function label(): string
    {
        return match ($this) {
            self::MANAGE_AUTHORS => 'Manage Authors',
            self::MANAGE_GENRES => 'Manage Genres',
            self::MANAGE_SERIES => 'Manage Series',
            self::MANAGE_TAGS => 'Manage Tags',
            self::MANAGE_BADGES => 'Manage Badges',
            self::MANAGE_BOOKS => 'Manage Books',
            self::MANAGE_USERS => 'Manage Users',
            self::ACCESS_ADMINER => 'Access Database Admin (Adminer)',
            self::ACCESS_HORIZON => 'Access Horizon Dashboard',
            self::MANAGE_QUEUE => 'Manage Queue',
            self::ACCESS_AI_TOOLS => 'Access AI Query/Assistant Tools',
            self::MANAGE_LIBRARY_REPAIR => 'Manage Library Repair',
            self::MANAGE_DIRECTORY_VALIDATION => 'Manage Directory Validation',
            self::MANAGE_TRASH => 'Manage Trash',
            self::MANAGE_ACCOUNT_REQUESTS => 'Manage Account Requests',
            self::MANAGE_MESSAGES => 'Manage Messages',
            self::ACCESS_DEBUG_TOOLS => 'Access Debug Tools',
        };
    }
}
