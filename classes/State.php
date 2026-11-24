<?php namespace Asyntai\Chatbot\Classes;

use Db;
use Url;
use Exception;
use Asyntai\Chatbot\Models\Settings;

/**
 * Plugin state and the calls this plugin makes to Asyntai.
 *
 * The widget id stays in the settings model, where 1.0.0 put it, so an
 * existing connection survives the update. The feed token and the connect
 * handshake live in their own table, because they must never be rendered into
 * a backend form.
 */
class State
{
    const TABLE = 'asyntai_chatbot_state';

    /** Where this site's PHP talks to Asyntai. Overridable for a test server. */
    public static function origin(): string
    {
        $origin = env('ASYNTAI_CHATBOT_ORIGIN', '');

        return $origin ? rtrim((string) $origin, '/') : 'https://asyntai.com';
    }

    /** The public address of this site, not the backend's. */
    public static function siteUrl(): string
    {
        return rtrim((string) Url::to('/'), '/');
    }

    // -----------------------------------------------------------------
    // Storage
    // -----------------------------------------------------------------

    public static function get(string $name, string $default = ''): string
    {
        try {
            $row = Db::table(self::TABLE)->where('name', $name)->first();
        }
        catch (Exception $e) {
            return $default;
        }

        return $row ? (string) $row->value : $default;
    }

    public static function set(string $name, string $value): bool
    {
        try {
            if (Db::table(self::TABLE)->where('name', $name)->exists()) {
                Db::table(self::TABLE)->where('name', $name)->update(['value' => $value]);
            }
            else {
                Db::table(self::TABLE)->insert(['name' => $name, 'value' => $value]);
            }
        }
        catch (Exception $e) {
            return false;
        }

        return true;
    }

    public static function forget(string $name): void
    {
        try {
            Db::table(self::TABLE)->where('name', $name)->delete();
        }
        catch (Exception $e) {
            // A row we could not delete is overwritten by the next connect.
        }
    }

    // -----------------------------------------------------------------
    // Connection
    // -----------------------------------------------------------------

    public static function siteId(): string
    {
        return trim((string) Settings::getSiteId());
    }

    /** Whether Asyntai may read the shared pages right now. */
    public static function sharingOn(): bool
    {
        return self::get('feed_enabled') === '1' && self::get('feed_token') !== '';
    }

    /**
     * Tokens the feed accepts: the live one while sharing is on, and the one
     * parked by the last connect. Asyntai starts reading the moment the owner
     * signs in, which is a moment before this site stores the sign-in result.
     */
    public static function acceptedTokens(): array
    {
        $tokens = [];
        if (self::sharingOn()) {
            $tokens[] = self::get('feed_token');
        }
        $pending = self::get('pending_token');
        if ($pending !== '') {
            $tokens[] = $pending;
        }

        return $tokens;
    }

    public static function feedUrl(): string
    {
        return self::siteUrl() . '/asyntai/chatbot/feed';
    }

    public static function pagesUrl(): string
    {
        return self::siteUrl() . '/asyntai/chatbot/feed/pages';
    }

    // -----------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------

    /** Body of a GET on a 2xx answer, or null. */
    public static function httpGet(string $url, int $timeout = 10): ?string
    {
        return self::request('GET', $url, null, $timeout, ['Accept: application/json']);
    }

    /** Body of a JSON POST on a 2xx answer, or null. */
    public static function httpPostJson(string $url, array $payload, int $timeout = 15): ?string
    {
        return self::request('POST', $url, json_encode($payload), $timeout, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
    }

    /** Plain curl, so one file serves every October release. */
    private static function request(string $method, string $url, ?string $body, int $timeout, array $headers): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        try {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERAGENT => 'Asyntai AI Chatbot for October CMS',
            ]);
            if ($method === 'POST') {
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, (string) $body);
            }
            $response = curl_exec($curl);
            $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
        }
        catch (Exception $e) {
            return null;
        }

        if ($response === false || $code < 200 || $code > 299) {
            return null;
        }

        return (string) $response;
    }
}
