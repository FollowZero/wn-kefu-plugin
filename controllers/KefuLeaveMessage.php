<?php namespace Summer\Kefu\Controllers;

use Backend\Classes\Controller;
use BackendMenu;

class KefuLeaveMessage extends Controller
{
    public $implement = [        'Backend\Behaviors\ListController',        'Backend\Behaviors\FormController'    ];
    
    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Summer.Kefu', 'main-menu-item-kefu', 'side-menu-item-leavemessage');
    }
}
