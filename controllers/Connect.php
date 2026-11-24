<?php namespace Asyntai\Chatbot\Controllers;

use App;
use BackendMenu;
use BackendAuth;
use ApplicationException;
use Backend\Classes\Controller;
use System\Classes\SettingsManager;
use Asyntai\Chatbot\Classes\State;
use Asyntai\Chatbot\Models\Settings;

/**
 * The settings screen and the connect handshake behind it.
 *
 * Every call from the browser is an October AJAX handler on this page, so it
 * needs a backend session and the CSRF token. Nothing is ever loaded from
 * Asyntai as a script inside the backend.
 */
class Connect extends Controller
{
    /** How long a handshake stays valid, in seconds. */
    const STATE_TTL = 900;

    public $requiredPermissions = ['asyntai.chatbot.manage'];

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('October.System', 'system', 'settings');
        SettingsManager::setContext('Asyntai.Chatbot', 'settings');
    }

    public function index()
    {
        $this->pageTitle = 'Asyntai AI Chatbot';

        $this->vars['siteId'] = State::siteId();
        $this->vars['accountEmail'] = (string) (Settings::getAccountEmail() ?: '');
        $this->vars['sharing'] = State::sharingOn();
        $this->vars['hasToken'] = State::get('feed_token') !== '';
    }

    /**
     * Start a connect. With sharing ticked, a fresh feed token is parked at
     * Asyntai first. Without it, nothing is parked and connecting works
     * exactly as in 1.0.0.
     */
    public function onPrepare()
    {
        $share = post('share') === '1';
        $state = 'october_' . bin2hex(random_bytes(12));

        if ($share) {
            $token = bin2hex(random_bytes(24));
            $body = State::httpPostJson(State::origin() . '/api/v1/wp-search/stage/', [
                'state' => $state,
                'platform' => 'october',
                'feed_protocol' => 'store_feed_v1',
                // A chatbot install never unlocks the paid AI Search bar.
                'product' => 'chat',
                'site_url' => State::siteUrl(),
                'feed_url' => State::feedUrl(),
                'feed_token' => $token,
            ]);
            if ($body === null) {
                throw new ApplicationException('Could not reach Asyntai. Check that this site can make outgoing HTTPS requests.');
            }
            // Kept aside until the sign-in finishes; the feed accepts it
            // meanwhile, because Asyntai starts reading at sign-in.
            State::set('pending_token', $token);
        }
        else {
            State::forget('pending_token');
        }

        State::set('state', $state);
        State::set('state_at', (string) time());

        $user = BackendAuth::getUser();

        $url = State::origin() . '/wp-auth?' . http_build_query([
            'state' => $state,
            'platform' => 'october',
            'product' => 'chat',
            'site_url' => State::siteUrl(),
            'wp_email' => $user ? (string) $user->email : '',
            'lang' => substr((string) App::getLocale(), 0, 2),
        ]);

        return ['state' => $state, 'url' => $url];
    }

    /** Has the owner finished signing in? */
    public function onPoll()
    {
        $state = trim((string) post('state', ''));
        $stored = State::get('state');
        $startedAt = (int) State::get('state_at', '0');

        // Only the handshake this site just started is ever polled.
        if ($state === '' || $state !== $stored) {
            throw new ApplicationException('Unknown handshake.');
        }
        if (time() - $startedAt > self::STATE_TTL) {
            throw new ApplicationException('This connection attempt has expired. Please press Get started again.');
        }

        $body = State::httpGet(State::origin() . '/api/v1/wp-search/connect-status/?state=' . rawurlencode($state));
        $decoded = $body === null ? null : json_decode($body, true);
        if (!is_array($decoded) || empty($decoded['ready']) || empty($decoded['data']['site_id'])) {
            return ['ready' => false];
        }

        return [
            'ready' => true,
            'site_id' => (string) $decoded['data']['site_id'],
            'script_url' => (string) ($decoded['data']['script_url'] ?? ''),
            'account_email' => (string) ($decoded['data']['account_email'] ?? ''),
        ];
    }

    /** Store the sign-in result. A parked feed token becomes the live one. */
    public function onFinish()
    {
        $siteId = trim((string) post('site_id', ''));
        if (!preg_match('/^[A-Za-z0-9_-]{6,64}$/', $siteId)) {
            throw new ApplicationException('Invalid site id.');
        }

        $payload = [Settings::KEY_SITE_ID => $siteId];
        $scriptUrl = trim((string) post('script_url', ''));
        if ($scriptUrl !== '' && preg_match('#^https://#i', $scriptUrl)) {
            $payload[Settings::KEY_SCRIPT_URL] = $scriptUrl;
        }
        $email = trim((string) post('account_email', ''));
        if ($email !== '') {
            $payload[Settings::KEY_ACCOUNT_EMAIL] = mb_substr($email, 0, 255);
        }
        Settings::set($payload);
        Settings::clearInternalCache();

        $pending = State::get('pending_token');
        if ($pending !== '') {
            State::set('feed_token', $pending);
            State::set('feed_enabled', '1');
            State::forget('pending_token');
        }
        State::forget('state');
        State::forget('state_at');

        return ['ok' => true];
    }

    /** Switch page sharing on or off. */
    public function onShare()
    {
        $on = post('enabled') === '1';
        if ($on && State::get('feed_token') === '') {
            // Connected before sharing existed, or after a reset: Asyntai has
            // no token for this site yet, so the owner signs in once more.
            return ['ok' => false, 'needs_connect' => true];
        }
        State::set('feed_enabled', $on ? '1' : '0');

        return ['ok' => true, 'enabled' => $on];
    }

    /** What Asyntai says about the last read of the shared pages. */
    public function onStatus()
    {
        $siteId = State::siteId();
        if ($siteId === '') {
            return ['sync' => null];
        }

        $body = State::httpGet(State::origin() . '/api/v1/search-widget/status/?widget_id=' . rawurlencode($siteId), 8);
        $decoded = $body === null ? null : json_decode($body, true);
        $sync = (is_array($decoded) && isset($decoded['sync']) && is_array($decoded['sync'])) ? $decoded['sync'] : null;

        return ['sync' => $sync === null ? null : [
            'status' => (string) ($sync['status'] ?? ''),
            'count' => (int) ($sync['count'] ?? 0),
            'error' => (string) ($sync['error'] ?? ''),
        ]];
    }

    /** Forget the connection. The feed token goes with it. */
    public function onDisconnect()
    {
        Settings::resetAll();
        foreach (['feed_token', 'feed_enabled', 'pending_token', 'state', 'state_at'] as $key) {
            State::forget($key);
        }

        return ['ok' => true];
    }
}
