<?php namespace Summer\Kefu\Components;

use Log;
use Mail;
use Lang;
use Auth;
use Flash;
use Cache;
use Input;
use Config;
use Cookie;
use Request;
use Response;
use Summer\Kefu\Models\KefuCsrModel;
use Summer\Kefu\Models\Settings;
use Validator;
use Carbon\Carbon;
use ValidationException;
use ApplicationException;
use Cms\Classes\ComponentBase;

use Summer\Kefu\Classes\Common;
use Summer\Kefu\Models\KefuTrajectoryModel;

class kefuCom extends ComponentBase
{

    protected $ioaddress;

    protected $token_info = false;// 用户、游客、管理员的资料

    protected $token_list = [];// 要发送给前台的token

    protected $referrer = '';

    public function componentDetails()
    {
        return [
            'name' => '弹窗客服',
            'description' => '弹窗客服'
        ];
    }
    public function defineProperties()
    {
        return [];
    }

    protected function setData()
    {
        $this->referrer = Common::trajectoryAnalysis(request()->header('referer'));
        //用户auth
        $user_auth=Cookie::get('user_auth');
        //顾客auth
        $god_auth=$_COOKIE['god_auth']??null;
        $current_url = request()->header('referer');
        $kefu_god_check = Common::checkKefuGod($user_auth,$god_auth);
        if(!$kefu_god_check){
            return new Exception('顾客信息初始化失败');
        }
        // 重置 god_auth
        $session_config=Config::get('session');
        setcookie(
            'god_auth',
            $kefu_god_check['kefu_god_cookie'],
            0,
            $session_config['path'],
            $session_config['domain'],
            $session_config['secure'] ?? false,
            $session_config['http_only'] ?? true
        );
        //记录轨迹
        if (isset($kefu_god_check['trajectory'])) {
            $trajectory_table=new KefuTrajectoryModel();
            $trajectory_table->god_id=$kefu_god_check['id'];
            $trajectory_table->csr_id=$kefu_god_check['trajectory']['csr_id'];
            $trajectory_table->log_type=0;
            $trajectory_table->note=$kefu_god_check['trajectory']['note'];
            $trajectory_table->url=$current_url;
            $trajectory_table->referrer=$this->referrer;
            $trajectory_table->save();
        }

        $settings=Settings::instance();
        $host = $_SERVER['HTTP_HOST'];
        $port=$settings->port>0?$settings->port:'39701';
        $iourl=$host.":".$port;

        $settings->modulename='index';
        $settings->iourl=$iourl;
        //自动邀请配置
        if($settings->invite_box_img){
            $settings->invite_box_img_path=$settings->invite_box_img->path;
        }else{
            $settings->invite_box_img_path='/plugins/summer/kefu/assets/img/invite_box_img.jpg';
        }
        // 只在有客服在线时弹出邀请框
        if($settings->only_csr_online_invitation && $settings->auto_invitation_switch){
            $online_csr=KefuCsrModel::where('status',3)->first();
            if($online_csr){
                $settings->auto_invitation_switch=$settings->auto_invitation_switch;
            }else{
                $settings->auto_invitation_switch=0;
            }
        }
        //会话窗口轮播图
        $slider_images=[];
        if($settings->sliders->isNotEmpty()){
            foreach ($settings->sliders as $slider){
                $slider_images[]=$slider->path;
            }
        }else{
            $slider_images[]='/plugins/summer/kefu/assets/img/slider1.jpg';
            $slider_images[]='/plugins/summer/kefu/assets/img/slider2.jpg';
        }
        $settings->slider_images=$slider_images;
        // 窗口抖动配置处理
        $is_shake=false;
        if(in_array($settings->new_message_shake,[1,3])){//顾客端是1,3开启。客服端是2,3开启
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
        //排除配置-一些只有服务用到的配置避免暴露
        unset($settings->new_message_shake);


//        dd($settings);
        $this->setVar('settings',$settings);
    }
    /**
     * The component is executed.
     *
     * @return string|void
     */
    public function onRun()
    {
        try {
            $this->setData();
            $this->addCss('https://cdn.staticfile.org/twitter-bootstrap/3.3.7/css/bootstrap.min.css');
            $this->addCss('/plugins/summer/kefu/assets/css/kefu_default.css');
            $this->addJs('https://cdn.staticfile.org/jquery/2.1.4/jquery.min.js');
            $this->addJs('https://cdn.staticfile.org/twitter-bootstrap/3.3.7/js/bootstrap.min.js');
            $this->addJs('https://cdn.staticfile.org/layer/2.3/layer.js');
            $this->addJs('/plugins/summer/kefu/assets/socket.io-client/socket.io.js');
            $this->addJs('/plugins/summer/kefu/assets/js/kefus.js?v=5');
        } catch (ApplicationException $e) {
            echo $e->getMessage();
            return $this->controller->run('404');
        }
    }
    protected function setVar($name, $value)
    {
        return $this->$name = $this->page[$name] = $value;
    }


}
