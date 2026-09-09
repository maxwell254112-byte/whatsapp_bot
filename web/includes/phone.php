<?php
declare(strict_types=1);

/**
 * Phone normalization with configurable default country code.
 * Does not invent digits; fails closed on ambiguous/invalid input.
 */
function normalize_phone(string $raw, ?string $defaultCountryCode = null): array
{
    $defaultCountryCode = $defaultCountryCode ?? (string)setting_get('default_country_code', '60');
    $original = trim($raw);
    if ($original === '') {
        return ['ok' => false, 'phone' => '', 'normalized' => '', 'error' => 'Empty phone'];
    }

    $digits = preg_replace('/[^\d+]/', '', $original) ?? '';
    $digits = str_replace('+', '', $digits);

    // 00 prefix international
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }

    if ($digits === '' || !preg_match('/^\d{8,15}$/', $digits)) {
        // local leading 0 + country
        $local = preg_replace('/\D+/', '', $original) ?? '';
        if (str_starts_with($local, '0') && strlen($local) >= 9 && strlen($local) <= 12) {
            $digits = $defaultCountryCode . substr($local, 1);
        } else {
            return ['ok' => false, 'phone' => $original, 'normalized' => '', 'error' => 'Invalid phone number'];
        }
    }

    // If number already starts with country code, keep it; if looks local (starts with 0 handled above)
    if (!preg_match('/^\d{8,15}$/', $digits)) {
        return ['ok' => false, 'phone' => $original, 'normalized' => '', 'error' => 'Invalid phone length'];
    }

    // Avoid double country code for common case: already has country code
    // If user entered local without 0 (e.g. 123456789 for MY), prepend country only when shorter than 10? Too risky.
    // Rule: if length <= 10 and does not start with country code, prepend.
    if (!str_starts_with($digits, $defaultCountryCode) && strlen($digits) <= 10) {
        $digits = $defaultCountryCode . ltrim($digits, '0');
    }

    if (strlen($digits) < 8 || strlen($digits) > 15) {
        return ['ok' => false, 'phone' => $original, 'normalized' => '', 'error' => 'Invalid phone length'];
    }

    return [
        'ok' => true,
        'phone' => '+' . $digits,
        'normalized' => $digits,
        'error' => '',
    ];
}
