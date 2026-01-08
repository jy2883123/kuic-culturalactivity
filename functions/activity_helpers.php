<?php
/**
 * Helper utilities for activity-specific behaviors.
 */
if (!function_exists('activity_requires_gown_size')) {
    /**
     * Determine whether the given activity should capture gown size.
     *
     * @param array $activity expects id/activity_id and program_name keys when possible.
     */
    function activity_requires_gown_size(array $activity): bool
    {
        if (array_key_exists('requires_gown_size', $activity)) {
            return (bool)$activity['requires_gown_size'];
        }

        $id = 0;
        if (isset($activity['id'])) {
            $id = (int)$activity['id'];
        } elseif (isset($activity['activity_id'])) {
            $id = (int)$activity['activity_id'];
        }

        if (defined('GOWN_SIZE_ACTIVITY_IDS') && is_array(GOWN_SIZE_ACTIVITY_IDS) &&
            in_array($id, GOWN_SIZE_ACTIVITY_IDS, true)
        ) {
            return true;
        }

        $name = $activity['program_name'] ?? '';
        $striposFn = function_exists('mb_stripos') ? 'mb_stripos' : 'stripos';
        if ($name !== '' && defined('GOWN_SIZE_KEYWORDS') && is_array(GOWN_SIZE_KEYWORDS)) {
            foreach (GOWN_SIZE_KEYWORDS as $keyword) {
                if ($keyword !== '' && $striposFn($name, $keyword) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('normalize_gown_size')) {
    /**
     * Normalize gown size input to one of S/M/L or null.
     */
    function normalize_gown_size(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(trim($value));
        $allowed = ['S', 'M', 'L'];

        return in_array($normalized, $allowed, true) ? $normalized : null;
    }
}
