<?php namespace Asyntai\Chatbot\Controllers;

use Backend\Classes\Controller;
use Asyntai\Chatbot\Models\Settings as SettingsModel;

class Settings extends Controller
{
    public function onSave()
    {
        file_put_contents('C:/laragon/www/october/storage/logs/asyntai_debug.txt', date('Y-m-d H:i:s') . " - Controller onSave method called!\n", FILE_APPEND);
        
        $data = input();
        \Log::info('======================== ASYNTAI CONTROLLER SAVE START ========================');
        \Log::info('🔧 Asyntai Controller onSave called with data: ' . json_encode($data));
        
        try {
            if (isset($data['site_id'])) {
                $value = trim($data['site_id']);
                \Log::info('🔧 Attempting to save site_id: ' . $value);
                
                $result = SettingsModel::set(SettingsModel::KEY_SITE_ID, $value);
                \Log::info('🔧 static::set result: ' . ($result ? 'true' : 'false'));
            }
            
            if (isset($data['script_url'])) {
                $value = trim($data['script_url']);
                \Log::info('🔧 Attempting to save script_url: ' . $value);
                SettingsModel::set(SettingsModel::KEY_SCRIPT_URL, $value);
            }
            
            if (isset($data['account_email'])) {
                $value = trim($data['account_email']);
                \Log::info('🔧 Attempting to save account_email: ' . $value);
                SettingsModel::set(SettingsModel::KEY_ACCOUNT_EMAIL, $value);
            }
            
            // Verify the settings were saved
            $savedSiteId = SettingsModel::getSiteId();
            \Log::info('🔍 Final verification - site_id: ' . ($savedSiteId ?: 'NULL'));
            
            // Check database directly
            $record = \Db::table('system_settings')->where('item', 'asyntai_chatbot_settings')->first();
            if ($record) {
                \Log::info('🔍 DB record exists, value length: ' . strlen($record->value));
                \Log::info('🔍 DB record content: ' . $record->value);
            } else {
                \Log::info('🚨 No DB record found after save!');
            }
            
        } catch (\Exception $e) {
            \Log::error('🚨 Exception in Controller onSave: ' . $e->getMessage());
            \Log::error('🚨 Exception trace: ' . $e->getTraceAsString());
        }
        
        \Log::info('======================== ASYNTAI CONTROLLER SAVE END ========================');
        return ['success' => true];
    }
}
