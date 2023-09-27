<?php namespace Summer\Kefu\Classes;



use PHPSocketIO\SocketIO;
use PHPSocketIO\Socket;
use PHPSocketIO\Nsp;

use Carbon\Carbon;

use Summer\Kefu\Models\KefuCsrModel;
use Summer\Kefu\Models\KefuGodModel;
use Summer\Kefu\Models\KefuLeaveMessageModel;
use Summer\Kefu\Models\KefuReceptionLogModel;
use Summer\Kefu\Models\KefuRecordModel;
use Summer\Kefu\Models\KefuSessionModel;
use Summer\Kefu\Models\KefuTrajectoryModel;
use Summer\Kefu\Models\Settings;
use System\Models\File;
use Winter\User\Models\User as UserModel;
use Exception;
use Config;
use Db;

class ChatIndexs
{
    /**
     * 当前 phpsocket.io 实例
     *
     * @var SocketIO
     */
    public $io;

    /**
     * 当前连接实例
     *
     * @var Socket
     */
    public $socket;

    /**
     * 当前 namespace 实例
     *
     * @var Nsp
     */
    public $nsp;


    public $db;

    public $god_info;

    public function __construct(SocketIO $io, Nsp $nsp, Socket $socket = null)
    {
        $this->io = $io;
        $this->socket = $socket;
        $this->nsp = $nsp;
        $headers=$socket->handshake['headers'];
        $cookies=$this->parseCookieHeader($headers['cookie']);
        $god_auth=$cookies['god_auth']??null;
        if($god_auth){
            //URL 编码进行解码
            //解密
            //json 转数组
            $kefu_god_cookie_dec=json_decode(decrypt(urldecode($god_auth),false), true);
            list($id, $token) = $kefu_god_cookie_dec;
            $god_info=KefuGodModel::where('id',$id)->where('token',$token)->first();
            if($god_info){
                $this->god_info = $god_info;
                //获取房间号
                $room_name='god|'.$god_info->id;
                //加入房间
                $this->socket->join($room_name);
            }
        }


    }

    public function on()
    {
        // 首次连接初始化
        $this->onInitialize();

        // 用户打开聊天窗口->分配客服->获取聊天记录
        $this->socket->on('userInitialize', function($data){
            $auto_distribution_csr = true;// 是否需要自动分配客服代表
            // 查找之前的会话 找到对应的客服代表
            $session=KefuSessionModel::withTrashed()->with([
                'csr'=>function($q){
                    $q->with(['target']);
                }
            ])->where('god_id',$this->god_info->id)->first();
            if($session){
                $csr_status=KefuCsrModel::where('id',$session->csr_id)->value('status');
                // 上次的客服代表在线
                if($csr_status && $csr_status==3){
                    $auto_distribution_csr = false;
                    if ($session->trashed()) {
                        $session->restore();
                    }
                }
            }
            // 前台指定客服-暂不考虑
            // 自动分配客服代表
            if ($auto_distribution_csr) {
                $csr = Common::getAppropriateCsr();
                if($csr){
                    //
                    $session = $this->distributionCsr($csr, $this->god_info);
                }else{
                    $return_data=[];
                    $return_data['code']=302;
                    $return_data['msg']='无在线客服！';
                    $this->socket->emit('user_initialize', $return_data);
                    return;
                }
            }


            if($session){

                // 记录客服接待人数

                // 获取聊天记录
                $this->onChatRecord([
                    'session_id' => $session['id'],
                ]);

                $initialize_data['session'] = $session;
                $return_data=[];
                $return_data['code']=1;
                $return_data['data']=$initialize_data;
                $this->socket->emit('user_initialize', $return_data);
            }else{
                $this->showMsg('分配客服代表失败，请重试！');
                return;
            }

        });
        // 加载更多聊天记录
        $this->socket->on('chatRecord', function($data){
            $this->onChatRecord($data);
        });

        // 记录留言
        $this->socket->on('leaveMessage', function($data){
            if (!$data['contact']) {
                $this->showMsg('联系方式不能为空~');
                return;
            }
            $last_leave_message_time = KefuLeaveMessageModel::where('god_id',$this->god_info->id)->orderBy('id','DESC')->value('created_at');
            if($last_leave_message_time){
                $now=Carbon::now();
                //距离上次相差多少分钟
                $minutesDifference = $now->diffInMinutes($last_leave_message_time);
                if($minutesDifference<10){
                    $this->showMsg('请勿频繁留言，请稍后再试~');
                    return;
                }
            }
            // 入库
            $leave_message_table=new KefuLeaveMessageModel();
            $leave_message_table->god_id=$this->god_info->id;
            $leave_message_table->name=$data['name'];
            $leave_message_table->contact=$data['contact'];
            $leave_message_table->message=$data['message'];
            $leave_message_table->save();
            if($leave_message_table->id){
                // 记录轨迹
                $trajectory_table=new KefuTrajectoryModel();
                $trajectory_table->god_id=$this->god_info->id;
                $trajectory_table->csr_id=0;
                $trajectory_table->log_type=6;
                $trajectory_table->note=$leave_message_table->id;
                $trajectory_table->save();
                //给客户端发信息
                $return_data=[];
                $return_data['code']=1;
                $return_data['msg']='留言成功!';
                $this->socket->emit('leave_message', $return_data);
                // 对接钉钉机器人 通知客服上线。
            }else{
                $this->showMsg('留言失败，请重试~');
                return;
            }
        });

        // 发送消息
        $this->socket->on('sendMessage', function($data){
            if (!isset($data['session_id'])) {
                $this->showMsg('发送失败,会话找不到啦！');
                return;
            }
            if (!isset($data['message']) || $data['message'] == '') {
                $this->showMsg('请输入消息内容！');
                return;
            }
            if (!isset($data['message_type'])) {
                $this->showMsg('消息类型错误！');
                return;
            }

            $this->inChatRecord($data);
        });
        // 输入状态更新
        $this->socket->on('messageInput', function($data){
            print_r($data);
            $this->onMessageInput($data);
        });
    }

    // 首次连接初始化
    public function onInitialize()
    {
        // 客服配置
        $settings=Settings::instance();
        $initialize_data=[]; //初始化信息
        $initialize_data['chat_name']=$settings->chat_name;
        $initialize_data['modulename']='index'; // 模块
        $initialize_data['god_info']=$this->god_info; // 顾客信息
        $initialize_data['new_msg']=Common::getUnreadMessagesCsr($this->god_info); // 新消息
        // 无客服游客-供前台建立会话
        $session=KefuSessionModel::where('god_id',$this->god_info->id)->first();
        if($session){
            $tourists = 'not';
        }else{
            $tourists = [
                'id'               => 'invitation|' . $this->god_info->id,
                'avatar'           => '',
                'nickname'         => $this->god_info->nickname,
                'online'           => 1,
                'unread_msg_count' => 0,
                'session_user'     => 'god|' . $this->god_info->id,
                'last_message'     => '',
                'last_time'        => Common::formatAt($this->god_info->created_at),
            ];
        }
        // 向所有在线客服代表发送上线消息
        $return_data=[];
        $return_data['user_id']=$this->god_info->id;
        $return_data['user_name']=$this->god_info->nickname;
        $return_data['tourists']=$this->god_info->nickname;
        $return_data['modulename']='index';
        $this->io->of('/admin')->emit('online', $return_data);

        $return_data=[];
        $return_data['data']=$initialize_data;
        $this->socket->emit('initialize', $return_data);
    }


    //显示错误信息
    public function showMsg($msg = '')
    {
        $return_data=[];
        $return_data['code']=0;
        $return_data['msg']=$msg;
        $this->socket->emit('show_msg', $return_data);
    }

    public function parseCookieHeader($cookieHeader) {
        $cookies = [];

        $cookieParts = explode('; ', $cookieHeader);
        foreach ($cookieParts as $cookiePart) {
            list($name, $value) = explode('=', $cookiePart, 2);
            $cookies[$name] = $value;
        }

        return $cookies;
    }


    /*
     * 加载更多聊天记录
     */
    public function onChatRecord($data)
    {
        $page_count = 20; //一次加载20条

        if (!isset($data['page'])) {
            $data['page'] = 1;
        }
        if (!isset($data['session_id'])) {
            $return_data=[];
            $return_data['code']=1;
            $return_data['data']['chat_record']=[];
            $return_data['data']['session_info']=[
                'nickname' => '无会话',
                ];
            $return_data['data']['next_page']='done';
            $return_data['data']['page']=$data['page'];
            $this->socket->emit('chat_record', $return_data);
            return;
        }
        $chat_record_count = KefuRecordModel::where('session_id',$data['session_id'])->count();
        $page_number = ceil($chat_record_count / $page_count);

        // 会话信息
        $session_info =KefuSessionModel::where('id', $data['session_id'])->first();

        if (!$session_info) {
            $this->showMsg('会话找不到啦！');
            return;
        }

        ///??
//        $session_user                      = Common::sessionUser($session_info);
//        $session_user_info                 = Common::userInfo($session_user);
//        $session_user_info['session_user'] = $session_user;
//        $session_user_info['status']       = (isset($session_user_info['wechat_openid']) && $session_user_info['wechat_openid']) ? 3 : (Gateway::isUidOnline($session_user) ? 3 : 0);

        //zp Chatindex 是顾客的类，按照之前的注释的话,就是要把客服发来的信息标记为已读
        // 标记此会话所有不是当前用户发的消息为已读->SQL不使用不等于->查得会话对象的ID
        KefuRecordModel::where('session_id', $data['session_id'])
            ->where('sender_identity', 1)
            ->where('status', 0)
            ->update(['status' => 1]);

        //zp 这里还有一层判断，是否在线
        $return_data=[];
        $return_data['data']['session_id']=$data['session_id'];
        $return_data['data']['record_id']='all';
        $this->socket->emit('read_message_done', $return_data);

        $chat_record = KefuRecordModel::with(['msgfile'])->where('session_id', $data['session_id'])
            ->orderBy('id','DESC')
            ->offset(($data['page'] - 1) * $page_count)
            ->limit($page_count)
            ->get();
        $chat_record->each(function ($record){
            if($record->msgfile){
                $record->msgfils_path=Config::get('filesystems.disks.local.url')."/".$record->msgfile->getDiskPath();
            }else{
                $record->msgfils_path='';
            }
        });
        $chat_record=$chat_record->toArray();

        // 为什么第一页的数据要反转？搞清楚后找反转对象的方法
        if ($data['page'] == 1) {
            $chat_record = array_reverse($chat_record, false);
        }

        $tourists_record = false;
        // 顾客绑定有会员但只有顾客登录没有用户登录
//        $tourists_record[] = [
//            'datetime' => '为保护您的隐私,您需要登录后才能查看历史聊天记录',
//            'data'     => [],
//        ];

        // 消息按时间分组
        if ($chat_record && !$tourists_record) {

            $record_temp = [];
            $createtime  = $chat_record[0]['createtime'];

            foreach ($chat_record as $key => $value) {

                if ( $value['sender_identity'] == 1) {
                    $value['sender'] = 'me';
                } else {
                    $value['sender'] = 'you';
                }

                if ($value['message_type'] == 1 || $value['message_type'] == 2) {
                    //处理图片和文件
                    $value['message']=$value['msgfils_path'];
//                    $value['message'] = Common::imgSrcFill($value['message'], false);
                } else {
                    $value['message'] = htmlspecialchars_decode($value['message']);
                }

                if (($value['createtime'] - $createtime) < 3600) {
                    $record_temp[$createtime][] = $value;
                } else {
                    $createtime                 = $value['createtime'];
                    $record_temp[$createtime][] = $value;
                }
            }

            unset($chat_record);

            foreach ($record_temp as $key => $value) {
                $chat_record[] = [
                    'datetime' => Common::formatTime($key),
                    'data'     => $value,
                ];
            }
            unset($record_temp);
        } elseif ($tourists_record) {
            $chat_record = $tourists_record;
        } else {
            $chat_record[] = [
                'datetime' => '还没有消息',
                'data'     => [],
            ];
        }


        $return_data=[];
        $return_data['code']=1;
        $return_data['data']['chat_record']=$chat_record;
        $return_data['data']['session_info']='';
        $return_data['data']['next_page']=($data['page'] >= $page_number) ? 'done' : $data['page'] + 1;
        $return_data['data']['page']=$data['page'];
        $this->socket->emit('chat_record', $return_data);
    }


    /**
     * 写入聊天记录/系统消息
     */
    public function inChatRecord($data)
    {
        $session_id=$data['session_id']??0;
        $message_id=$data['message_id']??false;
        $message=$data['message'];
        $message_type=$data['message_type']??0;
        $session = KefuSessionModel::where('id', $session_id)->first();
        if (!$session) {
            $return_data=[];
            $return_data['code']=0;
            $return_data['data']['msg']='发送失败,会话找不到啦！';
            $return_data['data']['message_id']=$message_id;
            $this->socket->emit('send_message', $return_data);
            return;
        }
        // 顾客信息-发信人
        $god_info=$this->god_info;
        // 客服信息-收信人
        $csr_info=KefuCsrModel::where('id',$session->csr_id)->first();
        if(!$csr_info){
            $return_data=[];
            $return_data['code']=0;
            $return_data['data']['msg']='发送失败,无法确定收信人！';
            $return_data['data']['message_id']=$message_id;
            $this->socket->emit('send_message', $return_data);
            return;
        }
        // 发送人身份:0=客服,1=用户
        $sender_identity = 1;
        // 还原html
        $message_html = htmlspecialchars_decode($message);
        // 去除样式
        $message_html = preg_replace("/style=.+?['|\"]/i", '', $message_html);
        $message_html = preg_replace("/width=.+?['|\"]/i", '', $message_html);
        $message_html = preg_replace("/height=.+?['|\"]/i", '', $message_html);

        if ($sender_identity == 1) {
            // 过滤除了img的所有标签
            $message_html = strip_tags($message_html, "<img>");
            $message_html = Common::removeXss($message_html);
        }
        $record_table=new KefuRecordModel();
        $record_table->session_id=$session_id;
        $record_table->sender_identity=$sender_identity;
        $record_table->sender_id=0;
        $record_table->god_id=$god_info->id;
        $record_table->csr_id=$csr_info->id;
        $record_table->message=htmlspecialchars($message_html);// 入库的消息内容不解码
        $record_table->message_type=($message_type == 'auto_reply') ? 0 : $message_type;
        $record_table->status=0;
        $record_table->save();

        if ($record_table->id>0) {
            //图片和文件处理
            if(in_array($message_type,[1,2])){
                $file=File::where('id',$message)->first();
                if($file){
                    $record_table->msgfile()->add($file);
                    $message_html=Config::get('filesystems.disks.local.url')."/".$file->getDiskPath();
                }
            }
            $msg['record_id'] = $record_table->id; //消息记录ID
            // 确定会话状态
            KefuSessionModel::where('id', $session['id'])->update([
                'deleted_at' => null,
            ]);

            // 给客服发送消息
            $msg['id']           = $session_id;
            $msg['avatar']       = '';
            $msg['nickname']     = $session->godname;
            $msg['session_user'] = 'god|'.$god_info->id;
            $msg['online']       = 1;
            $msg['last_message'] = Common::formatMessage($record_table);
            $msg['last_time']    = '';
            $msg['message']      = $message_html;
            $msg['message_type']      = ($message_type == 'auto_reply') ? 0 : $message_type;
            $msg['sender']       = 'you';
            /// 查询当前用户发送的未读消息条数
            $msg['unread_msg_count']=0;
            $return_data=[];
            $return_data['data']=$msg;
            //客服房间名
            $csr_room='csr|'.$csr_info->id;
            $this->io->of('/admin')->to($csr_room)->emit('new_message', $return_data);


            // 知识库自动回复

        } else {
            $return_data=[];
            $return_data['code']=0;
            $return_data['data']['msg']='发送失败,请重试！';
            $return_data['data']['message_id']=$message_id;
            $this->socket->emit('send_message', $return_data);
            return;
        }
    }

    /**
     * 是否在线
     * 顾客和客服连接上时会加入一个"身份｜ID"的房间。
     * 这里先获取命名空间下的所以房间。判断是否有传参的房间
     * @param $room_name
     * @return bool
     */
    public function isOnline($room_name){
        $identity=explode('|',$room_name);
        $identity_name=$identity[0];
        if($identity_name=='csr'){
            $nsp_name='/admin';
        }elseif($identity_name=='god'){
            $nsp_name='/index';
        }else{
            return false;
        }
        $rooms=$this->io->of($nsp_name)->adapter->rooms;
        if($rooms){
            return array_key_exists($room_name,$rooms);
        }else{
            return false;
        }
    }
    /**
     * 输入状态更新
     * @param $data
     */
    public function onMessageInput($data){
        if (!isset($data['session_id']) || !isset($data['type']) || !isset($data['session_user'])) {
            return;
        }
        $csr_room='csr|'.$data['session_user']['id'];
        $return_data=[];
        $return_data['data']['session_id']=$data['session_id'];
        $return_data['data']['type']=$data['type'];
        if($this->isOnline($csr_room)){
            $this->io->of('/admin')->to($csr_room)->emit('message_input', $return_data);
        }
    }

    /**
     * 分配/转移客服
     * @param int csr_info 客服代表
     * @param int god_info 顾客
     * @return array 新的会话信息
     */
    public function distributionCsr($csr_info, $god_info)
    {
        $welcome_msg=$csr_info->welcome_msg;
        if(!$welcome_msg){
            $welcome_msg=Settings::get('welcome_msg');
        }
        // 检查是否已有客服
        $session = KefuSessionModel::withTrashed()->with([
            'csr'=>function($q){
                $q->with(['target']);
            }
        ])->where('god_id',$god_info->id)->first();
        if ($session) {
            // 切换客服
            Db::beginTransaction();
            try {
                $note = '客服代表已由 ' . $session->csr->target->first_name . ' 转为 ' . $csr_info->target->first_name;
                // 记录轨迹
                $trajectory_table=new KefuTrajectoryModel();
                $trajectory_table->god_id=$god_info->id;
                $trajectory_table->csr_id=$csr_info->id;
                $trajectory_table->log_type=8;
                $trajectory_table->note=$note;
                $trajectory_table->url='';
                $trajectory_table->referrer='';
                $trajectory_table->save();
                if ($session->trashed()) {
                    $session->restore();
                }
                // 切换客服
                $session->csr_id=$csr_info->id;
                $session->save();
                // 插入/发送 切换客服的信息
                $dis_data=[];
                $dis_data['session_id']=$session->id;
                $dis_data['message']=$note;
                $dis_data['message_type']=3;
                $this->inChatRecord($dis_data);
                if ($welcome_msg) {
                    // 插入/发送 欢迎信息
                    $dis_data=[];
                    $dis_data['session_id']=$session->id;
                    $dis_data['message']=$welcome_msg;
                    $dis_data['message_type']=0;
                    $this->inChatRecord($dis_data);
                }
                // 插入接待记录,用于数据统计
                $reception_log_table=new KefuReceptionLogModel();
                $reception_log_table->god_id=$god_info->id;
                $reception_log_table->csr_id=$csr_info->id;
                $reception_log_table->save();

                // 更新客服的最后接待时间
                KefuCsrModel::where('id',$csr_info->id)->update([
                    'last_reception_at'=>Carbon::now()
                ]);
                //增加当前接待量
                KefuCsrModel::where('id',$csr_info->id)->increment('reception_count');
                Db::commit();
            }catch (Exception $e) {
                Db::rollBack();
                return false;
            }

        } else {
            // 分配客服
            Db::beginTransaction();
            try {
                // 记录轨迹
                $trajectory_table=new KefuTrajectoryModel();
                $trajectory_table->god_id=$god_info->id;
                $trajectory_table->csr_id=$csr_info->id;
                $trajectory_table->log_type=2;
                $trajectory_table->note='客服代表 ' . $csr_info->target->first_name;
                $trajectory_table->url='';
                $trajectory_table->referrer='';
                $trajectory_table->save();

                $session_table=new KefuSessionModel();
                $session_table->god_id=$god_info->id;
                $session_table->csr_id=$csr_info->id;
                $session_table->save();

                // 插入接待记录,用于数据统计
                $reception_log_table=new KefuReceptionLogModel();
                $reception_log_table->god_id=$god_info->id;
                $reception_log_table->csr_id=$csr_info->id;
                $reception_log_table->save();

                if ($welcome_msg) {
                    // 插入/发送 欢迎信息
                    $dis_data=[];
                    $dis_data['session_id']=$session_table->id;
                    $dis_data['message']=$welcome_msg;
                    $dis_data['message_type']=0;
                    $this->inChatRecord($dis_data);
                }
                Db::commit();
            }catch (Exception $e) {
                print_r($e->getMessage());
                Db::rollBack();
                return false;
            }
        }

        $session = KefuSessionModel::with([
            'csr'=>function($q){
                $q->with(['target']);
            }
        ])->where('god_id',$god_info->id)->first();
        $session->nickname=$session->csr->target->first_name;

        return $session;
    }
}
