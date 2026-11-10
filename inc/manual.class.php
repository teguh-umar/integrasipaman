<?php

/**
 * -------------------------------------------------------------------------
 * integrasipaman - the user manual, linked from inside the plugin
 * -------------------------------------------------------------------------
 *
 * v4.15.0, suite rule (2026-11-09, hub §4). The source is
 * docs/manual/integrasipaman.md at the suite root, maintained by Copilot; at
 * build it is copied to docs/manual.md here and ships in the zip. Rendered
 * with GLPI's bundled league/commonmark: raw HTML in the file is escaped and
 * unsafe links are dropped, so the manual can never inject markup or script
 * into a GLPI page. The manual may lag the code; the page says so.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

class PluginIntegrasipamanManual
{
    /** league/commonmark settings: no raw HTML, no javascript:/data: links. */
    public const CONVERTER_OPTIONS = ['html_input' => 'escape', 'allow_unsafe_links' => false];

    public static function path(): string
    {
        return dirname(__DIR__) . '/docs/manual.md';
    }

    /** The manual's text, or null when the file is not installed. */
    public static function read(): ?string
    {
        $path = self::path();
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $text = file_get_contents($path);

        return $text === false ? null : $text;
    }

    /**
     * The version the manual documents, from its header line
     * ("Versi didokumentasikan: 4.5.0" or "Version documented: 4.5.0").
     */
    public static function versionOf(string $markdown): string
    {
        return preg_match('/(?:Versi didokumentasikan|Version documented)\s*:\s*[*_`]*v?(\d+(?:\.\d+){1,2})/i', $markdown, $m) === 1
            ? $m[1]
            : '';
    }

    /**
     * True when the manual documents an older minor release than the plugin
     * installed. The rc suffix and the patch level are ignored (v4.15.1): a
     * patch changes no screen, so a 4.15.0 manual is current for 4.15.x.
     */
    public static function lags(string $manual_version, string $plugin_version): bool
    {
        if ($manual_version === '') {
            return false;
        }
        $minor = static fn(string $v): string => implode('.', array_slice(explode('.', (string) preg_replace('/-.*$/', '', $v)), 0, 2));

        return version_compare($minor($manual_version), $minor($plugin_version), '<');
    }

    /** Markdown to safe HTML. Without commonmark (never on GLPI 11), the text is shown escaped. */
    public static function render(string $markdown): string
    {
        if (class_exists(\League\CommonMark\GithubFlavoredMarkdownConverter::class)) {
            $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter(self::CONVERTER_OPTIONS);

            return (string) $converter->convert($markdown);
        }

        return '<pre class="text-wrap">' . htmlescape($markdown) . '</pre>';
    }

    /** The page body: a notice when missing, the version line, a lag warning, the manual. */
    public static function body(?string $markdown, string $plugin_version): string
    {
        if ($markdown === null) {
            return '<div class="alert alert-info">'
                . htmlescape(__('The user manual is not installed with this copy of the plugin.', 'integrasipaman'))
                . '</div>';
        }

        $version = self::versionOf($markdown);
        $out     = '';
        if (self::lags($version, $plugin_version)) {
            $out .= '<div class="alert alert-warning">' . htmlescape(sprintf(
                __('This manual documents version %1$s; the installed plugin is %2$s. Some screens may differ.', 'integrasipaman'),
                $version,
                $plugin_version
            )) . '</div>';
        } elseif ($version !== '') {
            $out .= '<p class="text-muted small">' . htmlescape(sprintf(__('Manual for version %s', 'integrasipaman'), $version)) . '</p>';
        }

        return $out . '<div class="markdown">' . self::render($markdown) . '</div>';
    }
}
