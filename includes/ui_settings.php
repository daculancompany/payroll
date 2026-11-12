<?php
/**
 * Appearance settings (v2 theme engine) — the one allowlist of keys and
 * values, shared by the admin save (Action::save_ui_settings → users.settings)
 * and the employee portal save (emp-portal-ajax.php → employee_portal_accounts
 * .settings). Unknown keys are dropped; every value is validated.
 *
 * @param array $cur   what is stored now (decoded JSON)
 * @param array $in    what the client sent (decoded JSON)
 * @param bool  $shell include the admin-only `ui` key (classic / v2 shell)
 */
if (!function_exists('ui_settings_merge')) {
    function ui_settings_merge(array $cur, array $in, bool $shell = false): array
    {
        $enum = function ($v, array $allowed, $default) { return in_array($v, $allowed, true) ? $v : $default; };
        $bool = function ($v) { return $v === true || $v === 1 || $v === '1' || $v === 'true'; };
        $hex  = function ($v) { return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : ''; };
        $slug = function ($v) { return is_string($v) && preg_match('/^[a-z0-9-]{0,32}$/', $v) ? $v : ''; };

        $rules = [
            'theme'        => function ($v) use ($enum) { return $enum($v, ['light', 'dark', 'black', 'system'], 'light'); },
            'colorTheme'   => $slug,
            'accent'       => $hex,
            'darkSidebar'  => $bool,
            'fontFamily'   => function ($v) use ($enum) { return $enum($v, ['default', 'system', 'rounded', 'serif', 'mono'], 'default'); },
            'density'      => function ($v) { $n = (int) $v; return ($n >= 90 && $n <= 115) ? $n : 100; },
            'radius'       => function ($v) use ($enum) { return $enum($v, ['sharp', 'default', 'rounded'], 'default'); },
            'headerStyle'  => function ($v) use ($enum) { return $enum($v, ['auto', 'solid', 'translucent'], 'auto'); },
            'highContrast' => $bool,
            'compact'      => $bool,
            'brandGradient'=> $bool,
            'reduceMotion' => $bool,
            'sidebarCollapsed' => $bool,
            'paperWhite'   => $bool,
            // client ms timestamp: on load, the newer of device/account copy wins
            'savedAt'      => function ($v) { $n = (int) $v; return $n > 0 ? min($n, (int) (microtime(true) * 1000) + 60000) : 0; },
        ];
        if ($shell) $rules['ui'] = function ($v) use ($enum) { return $enum($v, ['v2', 'classic'], 'classic'); };

        $out = $cur;
        foreach ($rules as $k => $fn) {
            if (array_key_exists($k, $in)) $out[$k] = $fn($in[$k]);
        }
        return array_intersect_key($out, $rules);
    }
}
