<?php namespace Summer\Kefu\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use Summer\Kefu\Models\KefuKbsModel;

class KefuKbs extends Controller
{
    public $implement = [        'Backend\Behaviors\ListController',        'Backend\Behaviors\FormController'    ];

    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Summer.Kefu', 'main-menu-item-kefu', 'side-menu-item-kbs');
    }
    public function test(){
        $best_kb       = [];// 最佳匹配
        if($best_kb){
            dd(1);
        }else{
            dd(2);
        }
    }
}
