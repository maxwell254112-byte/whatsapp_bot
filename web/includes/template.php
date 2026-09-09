<?php
declare(strict_types=1);

const TEMPLATE_VARS = ['name', 'phone', 'company'];

function render_template(string $body, array $vars): string
{
    $safe = [
        'name' => (string)($vars['name'] ?? ''),
        'phone' => (string)($vars['phone'] ?? ''),
        'company' => (string)($vars['company'] ?? ''),
    ];

    $out = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function (array $m) use ($safe): string {
        $key = strtolower($m[1]);
        if (array_key_exists($key, $safe)) {
            return $safe[$key];
        }
        // Unsupported variables remain visible for operator awareness
        return $m[0];
    }, $body);

    return (string)$out;
}

function find_unsupported_variables(string $body): array
{
    preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $body, $matches);
    $found = [];
    foreach ($matches[1] as $name) {
        $key = strtolower($name);
        if (!in_array($key, TEMPLATE_VARS, true)) {
            $found[] = $key;
        }
    }
    return array_values(array_unique($found));
}
