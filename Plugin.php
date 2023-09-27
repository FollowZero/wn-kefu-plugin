<?php namespace Summer\Kefu;

use System\Classes\PluginBase;

class Plugin extends PluginBase
{
    public $require = ['Winter.User'];

    public function registerComponents()
    {
        return [
            'Summer\Kefu\Components\KefuCom' => 'summer_kefu_kefucom',
        ];
    }

    public function registerSettings()
    {
        return [
            'settings' => [
                'label'       => '客服管理',
                'description' => '客服系统配置',
                'category'    => 'Summer',
                'icon'        => 'wn-icon-comments-o',
                'class'       => 'Summer\Kefu\Models\Settings',
                'order'       => 600,
            ]
        ];
    }
    public function register()
    {
        $this->registerConsoleCommand('summer.kefu', \Summer\Kefu\Console\KefuCommand::class);
    }
}
