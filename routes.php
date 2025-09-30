<?php

use Illuminate\Support\Facades\Route;
use Backend\Classes\AuthManager;
use Asyntai\Chatbot\Models\Settings as AsyntaiSettings;

// Backend-authenticated endpoint to persist Asyntai settings
Route::group([
    'prefix' => \Backend::uri(),
    'middleware' => ['web']
], function () {
    Route::post('asyntai/chatbot/connect/save', function () {
        try {
            // Ensure backend user is logged in
            if (!AuthManager::instance()->getUser()) {
                return response()->json(['success' => false, 'error' => 'forbidden'], 403);
            }

            // Accept JSON or form-encoded
            $data = request()->json()->all();
            if (!$data) {
                $data = request()->all();
            }

            $siteId = isset($data['site_id']) ? trim((string) $data['site_id']) : '';
            if ($siteId === '') {
                return response()->json(['success' => false, 'error' => 'missing site_id'], 400);
            }

            $payload = [ AsyntaiSettings::KEY_SITE_ID => $siteId ];
            if (!empty($data['script_url'])) {
                $payload[AsyntaiSettings::KEY_SCRIPT_URL] = trim((string) $data['script_url']);
            }
            if (!empty($data['account_email'])) {
                $payload[AsyntaiSettings::KEY_ACCOUNT_EMAIL] = trim((string) $data['account_email']);
            }

            // Persist and clear internal cache to avoid stale reads
            AsyntaiSettings::set($payload);
            AsyntaiSettings::clearInternalCache();

            // Verify
            $saved = [
                'site_id' => (string) AsyntaiSettings::getSiteId(),
                'script_url' => (string) AsyntaiSettings::getScriptUrl(),
                'account_email' => (string) (AsyntaiSettings::getAccountEmail() ?: '')
            ];

            return response()->json(['success' => true, 'saved' => $saved]);
        } catch (\Throwable $e) {
            \Log::error('[Asyntai] Save error: '.$e->getMessage());
            return response()->json(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()], 500);
        }
    });
});


