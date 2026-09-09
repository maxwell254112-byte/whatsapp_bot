<?php
declare(strict_types=1);

/**
 * Lightweight tests that do not require MySQL.
 * Run: php tests/php/run_unit_tests.php
 */

require_once __DIR__ . '/../../web/includes/phone.php';
require_once __DIR__ . '/../../web/includes/template.php';

// Minimal stubs for setting_get used by phone.php
function setting_get(string $key, mixed $default = null): mixed
{
    return $default;
}

$passed = 0;
$failed = 0;

function assert_true(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) {
        echo "PASS  $name\n";
        $passed++;
    } else {
        echo "FAIL  $name\n";
        $failed++;
    }
}

// Phone tests
$r = normalize_phone('+60123456789', '60');
assert_true($r['ok'] && $r['normalized'] === '60123456789', 'normalize +60 international');

$r = normalize_phone('0123456789', '60');
assert_true($r['ok'] && $r['normalized'] === '60123456789', 'normalize local leading 0');

$r = normalize_phone('0060123456789', '60');
assert_true($r['ok'] && $r['normalized'] === '60123456789', 'normalize 00 prefix');

$r = normalize_phone('abc', '60');
assert_true(!$r['ok'], 'reject invalid phone');

// Template tests
$out = render_template("Hi {{name}}\nPhone {{phone}} @ {{company}}", [
    'name' => 'Ali',
    'phone' => '+6011',
    'company' => 'Acme',
]);
assert_true($out === "Hi Ali\nPhone +6011 @ Acme", 'template replace + preserve newlines');

$out = render_template('Hello {{name}} {{unknown}}', ['name' => 'A']);
assert_true($out === 'Hello A {{unknown}}', 'unsupported vars remain visible');

$bad = find_unsupported_variables('Hi {{name}} {{foo}}');
assert_true($bad === ['foo'], 'detect unsupported variable');

// Campaign transition table
require_once __DIR__ . '/../../web/includes/campaigns.php';
assert_true(can_transition_campaign('draft', 'queued'), 'draft->queued');
assert_true(!can_transition_campaign('completed', 'queued'), 'completed locked');
assert_true(can_transition_campaign('paused', 'queued'), 'paused->queued');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
