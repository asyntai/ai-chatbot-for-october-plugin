<?php namespace Asyntai\Chatbot;

use Event;
use System\Classes\PluginBase;
use Asyntai\Chatbot\Models\Settings as AsyntaiSettings;

class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name' => 'Asyntai - AI Chatbot',
            'description' => 'AI assistant / chatbot – Provides instant answers to your website visitors',
            'author' => 'Asyntai',
            'icon' => 'icon-comments'
        ];
    }

    public function registerSettings()
    {
        return [
            'settings' => [
                'label' => 'Asyntai AI Chatbot',
                'description' => 'Asyntai - AI Chatbot',
                'category' => 'Asyntai AI Chatbot',
                'icon' => 'icon-comments',
                'class' => AsyntaiSettings::class,
                'order' => 500,
                'keywords' => 'asyntai ai chatbot',
            ]
        ];
    }

    public function boot()
    {
        Event::listen('cms.page.beforeRenderPage', function ($controller, $page) {
            $siteId = trim((string) AsyntaiSettings::getSiteId());
            if ($siteId === '') {
                return;
            }

            $scriptUrl = trim((string) AsyntaiSettings::getScriptUrl());
            $controller->addJs($scriptUrl, ['data-asyntai-id' => $siteId, 'async' => true, 'defer' => true]);
        });
    }
}


