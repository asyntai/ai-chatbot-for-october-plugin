<?php namespace Asyntai\Chatbot\Models;

use System\Models\SettingModel;
use October\Rain\Database\Traits\Validation;

class Settings extends SettingModel
{
    use Validation;

    public const KEY_SITE_ID = 'asyntai_site_id';
    public const KEY_SCRIPT_URL = 'asyntai_script_url';
    public const KEY_ACCOUNT_EMAIL = 'asyntai_account_email';

    public $settingsCode = 'asyntai_chatbot_settings';

    public $settingsFields = 'fields.yaml';

    public $rules = [];

    public function initSettingsData()
    {
        $this->{self::KEY_SITE_ID} = '';
        $this->{self::KEY_SCRIPT_URL} = 'https://asyntai.com/static/js/chat-widget.js';
        $this->{self::KEY_ACCOUNT_EMAIL} = '';
    }

    public static function getSiteId()
    {
        return static::get(self::KEY_SITE_ID);
    }

    public static function getScriptUrl()
    {
        return static::get(self::KEY_SCRIPT_URL, 'https://asyntai.com/static/js/chat-widget.js');
    }

    public static function getAccountEmail()
    {
        return static::get(self::KEY_ACCOUNT_EMAIL);
    }

    public static function resetAll()
    {
        static::set([
            self::KEY_SITE_ID => '',
            self::KEY_SCRIPT_URL => 'https://asyntai.com/static/js/chat-widget.js',
            self::KEY_ACCOUNT_EMAIL => ''
        ]);
        static::clearInternalCache();
    }
}


