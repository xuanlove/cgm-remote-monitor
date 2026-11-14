<?php
declare(strict_types=1);

namespace NightPanel;

/**
 * Minimal translation layer.
 *
 * The panel ships with Simplified Chinese and English. A visitor's choice is
 * kept in the session (and a cookie, so it survives a new session); without a
 * choice the browser's Accept-Language decides, and anything unrecognised
 * falls back to Chinese, which is the deployment default.
 *
 * Dictionaries live in `panel/lang/<locale>.php` and return a flat
 * key => string map. A key missing from the active locale falls back to the
 * default locale, then to the key itself, so a half-translated release still
 * shows something meaningful.
 */
final class Lang
{
    public const DEFAULT_LOCALE = 'zh_cn';

    /** Directories are resolved once: the panel is a single small app. */
    private const COOKIE = 'panel_lang';

    /** @var array<string,array<string,string>> */
    private static array $dictionaries = [];

    private static string $locale = self::DEFAULT_LOCALE;

    /** @return array<string,string> locale code => native name */
    public static function available(): array
    {
        return [
            'zh_cn' => '中文',
            'en' => 'English',
        ];
    }

    /**
     * Resolve the locale for this request: session choice, then cookie, then
     * Accept-Language, then the default.
     */
    public static function init(): string
    {
        $candidates = [];

        if (!empty($_SESSION['lang'])) {
            $candidates[] = (string)$_SESSION['lang'];
        }
        if (!empty($_COOKIE[self::COOKIE])) {
            $candidates[] = (string)$_COOKIE[self::COOKIE];
        }
        foreach (self::fromAcceptLanguage((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $locale) {
            $candidates[] = $locale;
        }

        foreach ($candidates as $candidate) {
            if (isset(self::available()[$candidate])) {
                self::$locale = $candidate;
                return self::$locale;
            }
        }

        self::$locale = self::DEFAULT_LOCALE;
        return self::$locale;
    }

    /** Switch the locale for the rest of the request and remember it. */
    public static function set(string $locale): bool
    {
        if (!isset(self::available()[$locale])) {
            return false;
        }
        self::$locale = $locale;
        $_SESSION['lang'] = $locale;
        // The switcher runs before any output, but keep CLI/embedded callers
        // from tripping over a header that can no longer be sent.
        if (!headers_sent()) {
            setcookie(self::COOKIE, $locale, [
                'expires' => time() + 86400 * 365,
                'path' => '/',
                'httponly' => true,
                'secure' => Auth::isHttps(),
                'samesite' => 'Lax',
            ]);
        }
        return true;
    }

    public static function current(): string
    {
        return self::$locale;
    }

    /** BCP-47 tag for the page's lang attribute and JS formatting. */
    public static function htmlLang(): string
    {
        return self::$locale === 'en' ? 'en' : 'zh-CN';
    }

    /**
     * Translate a key, replacing `:name` placeholders with the given values.
     *
     * @param array<string,string|int|float> $params
     */
    public static function t(string $key, array $params = []): string
    {
        $text = self::dictionary(self::$locale)[$key]
            ?? self::dictionary(self::DEFAULT_LOCALE)[$key]
            ?? $key;

        foreach ($params as $name => $value) {
            $text = str_replace(':' . $name, (string)$value, $text);
        }
        return $text;
    }

    /**
     * A JSON blob of translated strings for the page's JavaScript.
     *
     * @param array<int,string> $keys
     */
    public static function js(array $keys): string
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = self::t($key);
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /** @return array<string,string> */
    private static function dictionary(string $locale): array
    {
        if (!isset(self::$dictionaries[$locale])) {
            $file = dirname(__DIR__, 2) . '/lang/' . $locale . '.php';
            self::$dictionaries[$locale] = is_file($file) ? (array)require $file : [];
        }
        return self::$dictionaries[$locale];
    }

    /**
     * Locale codes the browser advertises, most preferred first.
     *
     * @return array<int,string>
     */
    private static function fromAcceptLanguage(string $header): array
    {
        $locales = [];
        foreach (explode(',', $header) as $part) {
            $tag = strtolower(trim(explode(';', $part)[0]));
            if ($tag === '') {
                continue;
            }
            if (str_starts_with($tag, 'zh')) {
                $locales[] = 'zh_cn';
            } elseif (str_starts_with($tag, 'en')) {
                $locales[] = 'en';
            }
        }
        return $locales;
    }
}
