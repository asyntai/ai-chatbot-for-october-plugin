<?php namespace Asyntai\Chatbot;

use Event;
use Backend;
use System\Classes\PluginBase;
use Asyntai\Chatbot\Classes\Feed;
use Asyntai\Chatbot\Models\Settings as AsyntaiSettings;

class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name' => 'Asyntai - AI Chatbot',
            'description' => 'AI assistant / chatbot – Provides instant answers to your website visitors',
            'author' => 'Asyntai',
            'icon' => 'icon-comments',
            'homepage' => 'https://asyntai.com/',
        ];
    }

    public function registerPermissions()
    {
        return [
            'asyntai.chatbot.manage' => [
                'tab' => 'Asyntai AI Chatbot',
                'label' => 'Connect the site and choose what the chatbot may read',
            ],
        ];
    }

    public function registerSettings()
    {
        return [
            'settings' => [
                'label' => 'Asyntai AI Chatbot',
                'description' => 'Connect your site and choose what the chatbot may read.',
                'category' => 'Asyntai AI Chatbot',
                'icon' => 'icon-comments',
                'url' => Backend::url('asyntai/chatbot/connect'),
                'permissions' => ['asyntai.chatbot.manage'],
                'order' => 500,
                'keywords' => 'asyntai ai chatbot',
            ]
        ];
    }

    public function boot()
    {
        // Both event names: the older one is all October 2 fires, the newer
        // one is the name later releases keep. The guard in inject() stops
        // the widget going in twice on a release that fires both.
        Event::listen('cms.page.postProcessContent', function ($controller, $url, $page, &$content) {
            if (is_string($content)) {
                $content = self::inject($content);
            }
        });
        Event::listen('cms.page.postprocess', function ($controller, $url, $page, $dataHolder) {
            if (isset($dataHolder->content) && is_string($dataHolder->content)) {
                $dataHolder->content = self::inject($dataHolder->content);
            }
        });
    }

    /** Add the widget loader before </body>, once. */
    public static function inject(string $content): string
    {
        $siteId = trim((string) AsyntaiSettings::getSiteId());
        // Only our own loader counts as "already there": the AI Search plugin
        // puts its own data-asyntai-id script on the same pages.
        if ($siteId === '' || strpos($content, 'setAttribute("data-asyntai-id"') !== false) {
            return $content;
        }
        // Our own page-share renders do not need the widget.
        if (isset($_SERVER['HTTP_X_ASYNTAI_FEED']) && $_SERVER['HTTP_X_ASYNTAI_FEED'] === '1') {
            return $content;
        }

        $scriptUrl = trim((string) AsyntaiSettings::getScriptUrl());
        // Load the widget only after the page's load event: an async script
        // tag still holds the load event open until its fetch finishes.
        $bootstrap = '<script>(function(){var l=function(){var s=document.createElement("script");'
            . 's.src=' . json_encode($scriptUrl) . ';s.async=true;'
            . 's.setAttribute("data-asyntai-id",' . json_encode($siteId) . ');'
            . 'document.head.appendChild(s)};'
            . 'if(document.readyState==="complete"){l()}'
            . 'else{window.addEventListener("load",l)}})();</script>';

        if (stripos($content, '</body>') !== false) {
            return str_ireplace('</body>', $bootstrap . '</body>', $content);
        }

        return $content . $bootstrap;
    }
}
