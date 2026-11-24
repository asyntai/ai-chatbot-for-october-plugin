<?php namespace Asyntai\Chatbot\Classes;

use Exception;
use Throwable;
use Cms\Classes\Page as CmsPage;
use Cms\Classes\Theme;

/**
 * The signed, read-only feed Asyntai reads this site's pages from.
 *
 * Format: Asyntai store feed v1. The product feed is always empty (October
 * sites share pages), and names the pages feed in `store.pages_url`.
 *
 * WHAT IS SENT. Every page is rendered exactly as a logged-out visitor gets
 * it, through a normal request to this site, and only a 200 answer is used.
 * A draft, a hidden page or a members-only page is therefore never sent:
 * the site itself refuses to show it to an anonymous visitor.
 *
 * WHICH PAGES. Theme pages with a fixed address, plus the entries behind
 * pages with parameters: Tailor sections (October 3+), RainLab.Blog posts and
 * RainLab.Pages static pages, when those are installed.
 */
class Feed
{
    /** How far a signed request's clock may drift, in seconds. */
    const CLOCK_SKEW = 300;

    /** Pages rendered per feed page. Each one is a request to this site. */
    const PAGE_SIZE = 8;

    /** A site with more addresses than this is answering wrongly. */
    const MAX_URLS = 3000;

    /** Header on our own render requests, so the widget is left out. */
    const RENDER_HEADER = 'X-Asyntai-Feed';

    // -----------------------------------------------------------------
    // Requests
    // -----------------------------------------------------------------

    /** [status, body] for the product feed. */
    public static function products(array $query): array
    {
        $refused = self::refuse($query);
        if ($refused !== null) {
            return $refused;
        }

        return [200, [
            'ok' => true,
            'store' => [
                'name' => self::siteName(),
                'url' => State::siteUrl(),
                'currency' => '',
                'version' => self::octoberVersion(),
                'pages_url' => State::pagesUrl(),
            ],
            'page' => 1,
            'pages' => 0,
            'total' => 0,
            'categories' => [],
            'products' => [],
        ]];
    }

    /** [status, body] for the pages feed. */
    public static function pages(array $query): array
    {
        $refused = self::refuse($query);
        if ($refused !== null) {
            return $refused;
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        // The page size is ours, whatever was asked for: every item is a
        // render, and a large page would run into the reader's timeout.
        $urls = self::urls();
        $slice = array_slice($urls, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        return [200, [
            'ok' => true,
            'page' => $page,
            'pages' => (int) ceil(count($urls) / self::PAGE_SIZE),
            'total' => count($urls),
            'items' => self::render($slice),
        ]];
    }

    /**
     * [403, error] when the request is not a valid signed call, else null.
     * Signed over the raw query values, in a fixed order, before clamping.
     */
    private static function refuse(array $query): ?array
    {
        $tokens = State::acceptedTokens();
        if (!$tokens) {
            return [403, ['ok' => false, 'error' => 'Sharing is switched off on this site.']];
        }

        $ts = (string) ($query['ts'] ?? '');
        if ($ts === '' || !ctype_digit($ts)) {
            return [403, ['ok' => false, 'error' => 'Missing timestamp.']];
        }
        if (abs(time() - (int) $ts) > self::CLOCK_SKEW) {
            return [403, ['ok' => false, 'error' => 'The request has expired.']];
        }

        $signed = 'page=' . (string) ($query['page'] ?? '')
            . '&limit=' . (string) ($query['limit'] ?? '')
            . '&ids=' . (string) ($query['ids'] ?? '')
            . '&ts=' . $ts;
        $sig = strtolower((string) ($query['sig'] ?? ''));

        foreach ($tokens as $token) {
            if ($sig !== '' && hash_equals(hash_hmac('sha256', $signed, $token), $sig)) {
                return null;
            }
        }

        return [403, ['ok' => false, 'error' => 'Bad signature.']];
    }

    // -----------------------------------------------------------------
    // Which addresses
    // -----------------------------------------------------------------

    /**
     * Every public address worth reading, as [url, type] pairs, sorted so the
     * paging is stable between calls.
     */
    public static function urls(): array
    {
        $found = [];
        $theme = null;
        try {
            $theme = Theme::getActiveTheme();
        }
        catch (Throwable $e) {
            $theme = null;
        }

        if ($theme) {
            try {
                foreach (CmsPage::listInTheme($theme, true) as $page) {
                    foreach (self::pageUrls($page) as $pair) {
                        $found[$pair[0]] = $pair;
                    }
                }
            }
            catch (Throwable $e) {
                // A theme we cannot list still has static pages below.
            }
            foreach (self::staticPageUrls($theme) as $pair) {
                $found[$pair[0]] = $pair;
            }
        }

        ksort($found);

        return array_slice(array_values($found), 0, self::MAX_URLS);
    }

    /** The addresses one theme page stands for. */
    private static function pageUrls($page): array
    {
        $settings = (array) ($page->settings ?? []);
        if (!empty($settings['is_hidden'])) {
            return [];
        }

        $pattern = (string) ($page->url ?? '');
        $file = (string) $page->getBaseFileName();
        if ($pattern === '' || in_array($file, ['404', 'error'], true)) {
            return [];
        }
        // A search results page with no query shows nothing worth reading.
        if ($file === 'search' || substr($file, -7) === '/search') {
            return [];
        }

        // A fixed address: the page itself.
        if (strpos($pattern, ':') === false) {
            $url = self::absolute(CmsPage::url($file));
            return $url !== '' ? [[$url, 'page']] : [];
        }

        // An address with parameters: one per published entry behind it.
        $components = (array) ($settings['components'] ?? []);
        $out = [];

        foreach ($components as $alias => $properties) {
            $name = strtolower(trim(explode(' ', (string) $alias)[0]));
            $properties = (array) $properties;

            if ($name === 'section' && !empty($properties['handle'])) {
                foreach (self::tailorEntries((string) $properties['handle']) as $entry) {
                    $url = self::fill($file, $pattern, $entry);
                    if ($url !== '') {
                        $out[] = [$url, 'post'];
                    }
                }
            }

            if ($name === 'blogpost') {
                foreach (self::blogPosts() as $post) {
                    $url = self::fill($file, $pattern, $post);
                    if ($url !== '') {
                        $out[] = [$url, 'post'];
                    }
                }
            }
        }

        return $out;
    }

    /** Published entries of a Tailor section, or [] without Tailor. */
    private static function tailorEntries(string $handle): array
    {
        if (!class_exists('Tailor\\Models\\EntryRecord')) {
            return [];
        }

        try {
            $query = \Tailor\Models\EntryRecord::inSection($handle);
            if (method_exists($query, 'scopeApplyPublishedStatus')) {
                $query = $query->applyPublishedStatus();
            }

            return $query->limit(self::MAX_URLS)->get()->all();
        }
        catch (Throwable $e) {
            return [];
        }
    }

    /** Published RainLab.Blog posts, or [] without the plugin. */
    private static function blogPosts(): array
    {
        if (!class_exists('RainLab\\Blog\\Models\\Post')) {
            return [];
        }

        try {
            return \RainLab\Blog\Models\Post::isPublished()->limit(self::MAX_URLS)->get()->all();
        }
        catch (Throwable $e) {
            return [];
        }
    }

    /** RainLab.Pages static pages, or [] without the plugin. */
    private static function staticPageUrls($theme): array
    {
        if (!class_exists('RainLab\\Pages\\Classes\\Page')) {
            return [];
        }

        $out = [];
        try {
            foreach (\RainLab\Pages\Classes\Page::listInTheme($theme, true) as $page) {
                $bag = (array) ($page->getViewBag()->getProperties() ?? []);
                if (!empty($bag['is_hidden'])) {
                    continue;
                }
                $url = (string) ($bag['url'] ?? '');
                if ($url !== '' && strpos($url, ':') === false) {
                    $out[] = [self::absolute($url), 'page'];
                }
            }
        }
        catch (Throwable $e) {
            return [];
        }

        return $out;
    }

    /**
     * The page address with each parameter taken from the record field of
     * the same name (:slug -> slug, :id -> id). '' when one is missing.
     */
    private static function fill(string $file, string $pattern, $record): string
    {
        preg_match_all('/:([A-Za-z0-9_]+)(\?)?/', $pattern, $matches, PREG_SET_ORDER);
        $params = [];

        foreach ($matches as $match) {
            $name = $match[1];
            $value = '';
            try {
                $value = (string) ($record->{$name} ?? '');
            }
            catch (Throwable $e) {
                $value = '';
            }
            if ($value === '' && empty($match[2])) {
                return '';
            }
            $params[$name] = $value;
        }

        try {
            return self::absolute((string) CmsPage::url($file, $params));
        }
        catch (Throwable $e) {
            return '';
        }
    }

    private static function absolute(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return State::siteUrl() . '/' . ltrim($url, '/');
    }

    // -----------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------

    /**
     * Render each address as an anonymous visitor, side by side, and keep
     * the ones that answer 200 with words on them.
     */
    private static function render(array $pairs): array
    {
        if (!$pairs || !function_exists('curl_multi_init')) {
            return [];
        }

        $multi = curl_multi_init();
        $handles = [];
        foreach ($pairs as $i => $pair) {
            $curl = curl_init($pair[0]);
            curl_setopt_array($curl, self::renderOptions());
            curl_multi_add_handle($multi, $curl);
            $handles[$i] = $curl;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $items = [];
        foreach ($handles as $i => $curl) {
            $answer = [
                (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
                strtolower((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE)),
                (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
                (string) curl_multi_getcontent($curl),
            ];
            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);

            // A page that failed for a moment would otherwise drop out of
            // the chatbot's knowledge until the next read. One more try, on
            // its own, for a network error or a server error.
            if ($answer[0] === 0 || $answer[0] >= 500) {
                $answer = self::renderOne($pairs[$i][0]);
            }

            $item = self::accept($answer, $pairs[$i]);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        curl_multi_close($multi);

        return $items;
    }

    /** [code, type, landed url, html] for one address. */
    private static function renderOne(string $url): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, self::renderOptions());
        $html = (string) curl_exec($curl);
        $answer = [
            (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            strtolower((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE)),
            (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
            $html,
        ];
        curl_close($curl);

        return $answer;
    }

    private static function renderOptions(): array
    {
        return [
            CURLOPT_RETURNTRANSFER => true,
            // Followed, then checked in accept(): only a redirect that lands
            // on the same address (a trailing slash) is accepted. Anything
            // else is a login wall or a moved page.
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Accept: text/html', self::RENDER_HEADER . ': 1'],
            CURLOPT_USERAGENT => 'Asyntai AI Chatbot for October CMS (page share)',
        ];
    }

    /** The feed item for one rendered answer, or null when it is not usable. */
    private static function accept(array $answer, array $pair): ?array
    {
        list($code, $type, $landed, $html) = $answer;
        if ($code !== 200 || $html === '' || strpos($type, 'html') === false) {
            return null;
        }
        if (rtrim($landed, '/') !== rtrim($pair[0], '/')) {
            return null;
        }

        return self::extract($html, $pair[0], $pair[1]);
    }

    /** Title and main text of one rendered page, or null when it has none. */
    public static function extract(string $html, string $url, string $type): ?array
    {
        if (preg_match('/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex/i', $html)) {
            return null;
        }

        $title = '';
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $title = self::text($m[1]);
        }
        if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = self::text($m[1]);
        }

        // The main or article element when it really holds the page's words.
        // Some themes print an empty <main> and keep the text beside it, so
        // the whole body is used whenever those are thin.
        $text = '';
        foreach (['main', 'article', 'body'] as $tag) {
            if (!preg_match('/<' . $tag . '\b[^>]*>(.*)<\/' . $tag . '>/is', $html, $m)) {
                continue;
            }
            $candidate = self::text(self::stripChrome($m[1]));
            if (mb_strlen($candidate) > mb_strlen($text)) {
                $text = $candidate;
            }
            if (mb_strlen($candidate) >= 200) {
                break;
            }
        }
        if ($text === '') {
            $text = self::text(self::stripChrome($html));
        }

        if ($title === '' || mb_strlen($text) < 40) {
            return null;
        }

        return [
            'type' => $type,
            'title' => mb_substr($title, 0, 300),
            'url' => $url,
            'content' => $text,
        ];
    }

    /** Navigation, page chrome and code are not content. */
    private static function stripChrome(string $html): string
    {
        return (string) preg_replace(
            '#<(script|style|noscript|svg|template|nav|header|footer|aside|form|iframe)\b[^>]*>.*?</\1>#is',
            ' ', $html);
    }

    private static function text(string $html): string
    {
        $html = preg_replace('#<br\s*/?>|</(p|div|li|h[1-6]|tr|section)>#i', "\n", $html);
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = preg_replace('/\s*\n\s*/', "\n", (string) $text);

        return trim((string) $text);
    }

    // -----------------------------------------------------------------

    private static function siteName(): string
    {
        try {
            $name = (string) \Config::get('app.name', '');
            if ($name !== '' && $name !== 'October CMS') {
                return $name;
            }
        }
        catch (Throwable $e) {
        }

        return (string) parse_url(State::siteUrl(), PHP_URL_HOST);
    }

    private static function octoberVersion(): string
    {
        try {
            if (class_exists('System\\Models\\Parameter')) {
                return (string) \System\Models\Parameter::get('system::core.build', '');
            }
        }
        catch (Exception $e) {
        }

        return '';
    }
}
