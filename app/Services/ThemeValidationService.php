<?php

namespace App\Services;

class ThemeValidationService
{
    private const REQUIRED_FIELDS = [
        'primary',
        'background',
        'surface',
        'text',
        'accent',
        'progressActive',
        'progressInactive',
        'timeText',
    ];

    private const OPTIONAL_FIELDS = [
        'buttonTint',
        'borderColor',
        'overlayColor',
        'darkModeOverride',
    ];

    public function validateThemeData(array $data): array
    {
        $errors = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! isset($data[$field])) {
                $errors[] = "Required field '{$field}' is missing";

                continue;
            }

            if ($field !== 'darkModeOverride' && ! $this->validateHexColor($data[$field])) {
                $errors[] = "Field '{$field}' must be a valid hex color (#RRGGBB or #RRGGBBAA)";
            }
        }

        foreach (self::OPTIONAL_FIELDS as $field) {
            if (isset($data[$field])) {
                if ($field === 'darkModeOverride') {
                    if (! is_bool($data[$field])) {
                        $errors[] = "Field '{$field}' must be a boolean";
                    }
                } elseif (! $this->validateHexColor($data[$field])) {
                    $errors[] = "Field '{$field}' must be a valid hex color (#RRGGBB or #RRGGBBAA)";
                }
            }
        }

        return $errors;
    }

    public function validateHexColor(string $color): bool
    {
        return (bool) preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/', $color);
    }

    public function validateRequiredFields(array $data): array
    {
        $errors = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! isset($data[$field])) {
                $errors[] = "Required field '{$field}' is missing";
            }
        }

        return $errors;
    }
}
