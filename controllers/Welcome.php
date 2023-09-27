<?php namespace Summer\Kefu\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use Cache;
use Config;
use BackendAuth;
use Summer\Kefu\Models\KefuFastReplyModel;
use Summer\Kefu\Models\Settings;

class Welcome extends Controller
{
    public $layout = 'kefulayout';

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Summer.Kefu', 'main-menu-item-kefu', 'side-menu-item-welcome');
        $this->addCss('https://cdn.staticfile.org/twitter-bootstrap/3.3.7/css/bootstrap.min.css');
        $this->addCss('/plugins/summer/kefu/assets/css/kefu_admin_default.css?v=2');
        $this->addCss('/plugins/summer/kefu/assets/css/layout.css?v=2');
        $this->addJs('https://cdn.staticfile.org/jquery/2.1.4/jquery.min.js');
        $this->addJs('https://cdn.staticfile.org/twitter-bootstrap/3.3.7/js/bootstrap.min.js');
        $this->addJs('https://cdn.staticfile.org/layer/2.3/layer.js');
        $this->addJs('/plugins/summer/kefu/assets/socket.io-client/socket.io.js');
        $this->addJs('/plugins/summer/kefu/assets/js/kefus.js?v=5');

        //通用逻辑
        $settings=Settings::instance();
        $host = $_SERVER['HTTP_HOST'];
        $port=$settings->port>0?$settings->port:'39701';
        $iourl=$host.":".$port;
        $settings->modulename='admin';
        $settings->iourl=$iourl;
        // 窗口抖动配置处理
        $is_shake=false;
        if(in_array($settings->new_message_shake,[2,3])){//顾客端是1,3开启。客服端是2,3开启
            $is_shake=true;
        }
        $settings->is_shake=$is_shake;
        //消息提示音
        if($settings->ringing){
            $settings->ringing_path=$settings->ringing->path;
        }else{
            $settings->ringing_path='/plugins/summer/kefu/assets/audio/message_prompt.wav';
        }
        // 上传配置
        $upload=[];
        $upload['uploadurl']=url('api/kefu/file/upfile');
        $upload['cdnurl']=url(Config::get('filesystems.disks.local.url'))."/";
        $settings->upload=$upload;
        // 快捷回复
        $admin = BackendAuth::getUser();
        $admin_id=$admin->id;
        $fast_replies=KefuFastReplyModel::where('status',1)
            ->where('admin_id', 0)
            ->orWhere('admin_id', null) // 数据库中默认0没生效多写一个条件
            ->orWhere('admin_id', $admin_id)

            ->whereNull('deleted_at')->get();
        $fast_reply_temp = [];
        $fast_replies->each(function ($fast_reply)use(&$fast_reply_temp){
            $fast_reply_temp[$fast_reply->id]=$fast_reply;
        });
        $settings->fast_replies=$fast_reply_temp;
        $this->vars['fast_replies']=$fast_replies;
        $this->vars['settings']=$settings;
    }
    public function index()
    {
        $this->pageTitle="欢迎页";
    }
}
