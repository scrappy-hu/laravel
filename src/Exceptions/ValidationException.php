<?php

declare(strict_types=1);

namespace Scrappy\Exceptions;

/**
 * 400 validation_error / invalid_request — the request body or a
 * field failed schema validation. `fieldErrors()` returns the per-
 * field error map straight from Zod's flatten output.
 */
class ValidationException extends ScrappyException
{
    /**
     * Field-level validation errors from the api. Keys are field
     * paths, values are arrays of error message strings.
     *
     * @return array<string, list<string>>
     */
    public function fieldErrors(): array
    {
        $details = $this->payload['details'] ?? null;
        if (! is_array($details)) {
            return [];
        }
        $field = $details['fieldErrors'] ?? null;
        if (! is_array($field)) {
            return [];
        }

        // Filter to expected shape — array of strings per field.
        $result = [];
        foreach ($field as $key => $value) {
            if (! is_string($key) || ! is_array($value)) {
                continue;
            }
            $result[$key] = array_values(array_filter($value, 'is_string'));
        }

        return $result;
    }
}
