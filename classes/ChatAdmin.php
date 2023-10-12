<?php namespace Summer\Kefu\Classes;


use PHPSocketIO\SocketIO;
use PHPSocketIO\Socket;
use PHPSocketIO\Nsp;

use Config;
use Carbon\Carbon;
use Summer\Kefu\Models\KefuCsrModel;
use Summer\Kefu\Models\KefuGodModel;
use Summer\Kefu\Models\KefuReceptionLogModel;
use Summer\Kefu\Models\KefuRecordModel;
use Summer\Kefu\Models\KefuSessionModel;
use Summer\Kefu\Models\KefuTrajectoryModel;
use Summer\Kefu\Models\Settings;
use System\Models\File;
use Winter\User\Models\User as UserModel;
use Db;
class ChatAdmin extends ChatCom
{
    public function __construct(SocketIO $io, Nsp $nsp, Socket $socket = null)
    {
        parent::__construct($io,$nsp,$socket);
        $headers=$socket->handshake['headers'];
        $cookies=$this->parseCookieHeader($headers['cookie']);
        $admin_auth=$cookies['admin_auth']??null;
        if($admin_auth) {
            //URL 编码进行解码
            //解密
            //非 laravel 获取的 cookie,应该是还拼接了一个token|,拆成数组
            //json 转数组
            $admin_cookie_arr = explode('|',decrypt(urldecode($admin_auth), false));
            $admin_cookie_dec=json_decode($admin_cookie_arr[1]);
            list($admin_id, $token) = $admin_cookie_dec;
            // 获取管理员绑定客服代表
            $csr_info=KefuCsrModel::with(['target'])->where('admin_id',$admin_id)->first();
            if($csr_info){
                $this->csr_info = $csr_info;
                //获取房间号
                $room_name='csr|'.$csr_info->id;
                //加入房间
                $this->socket->join($room_name);
            }
        }
    }

    public function on()
    {
        // 首次连接初始化
        $this->onInitialize();


        // 加载更多聊天记录
        $this->socket->on('chatRecord', function($data){
            $this->onChatRecord($data);
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
            $data['sender_identity']=0;
            $this->inChatRecord($data);
        });
        // 邀请对话 & 删除会话 & 转接会话 & 修改用户昵称
        $this->socket->on('actionSession', function($data){
            $this->onActionSession($data);
        });
        // 轨迹记录
        $this->socket->on('trajectory', function($data){
            $this->onTrajectory($data);
        });
        // 用户卡片
        $this->socket->on('userCard', function($data){
            print_r($data);
            $this->onUserCard($data);
        });
        // 搜索用户
        $this->socket->on('searchUser', function($data){
            print_r($data);
            $this->onSearchUser($data);
        });
        // 客服代表改变状态
        $this->socket->on('csrChangeStatus', function($data){
            print_r($data);
            if (isset($data['status'])) {
                KefuCsrModel::where('id',$this->csr_info->id)->update(['status'=>$data['status']]);
                $return_data=[];
                $return_data['code']=1;
                $return_data['data']['csr_status']=$data['status'];
                $return_data['data']['csr']=$this->csr_info->id;
                $this->socket->emit('csr_change_status', $return_data);
                //给所有顾客发送
                $this->io->of('/index')->emit('csr_change_status', $return_data);
            }
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
        $csr_info=$this->csr_info;
        $initialize_data=[]; //初始化信息
        $initialize_data['chat_name']=$settings->chat_name;
        $initialize_data['modulename']='admin'; // 模块
        $initialize_data['csr_info']=$csr_info; // 客服信息
        $initialize_data['new_msg']=Common::getUnreadMessagesCsr($csr_info); // 新消息


        // 获取会话列表
        $sessions=KefuSessionModel::with([
            'god',
            'records'=>function($q){
                $q->orderBy('id','DESC');
            }
        ])->where('csr_id',$csr_info->id)->orderBy('id','DESC')->limit(40)->get();
        $session_temp = [];
        //12个小时内的会话是对话中。以后的是最近沟通
        $sessions->each(function ($session)use(&$session_temp){
            $session->last_message=Common::formatMessage($session->records->isNotEmpty()?$session->records[0]:'');
            $session->session_user='god|'.$session->god_id;
            $session->nickname=$session->god->nickname;
            if($session->god->target){
                $session->god_avatar=$session->god->target->avatar?Config::get('filesystems.disks.local.url')."/".$session->god->target->avatar->getDiskPath():'/plugins/summer/kefu/assets/img/avatar.png';
            }else{
                $session->god_avatar='/plugins/summer/kefu/assets/img/avatar.png';
            }
            $session->online=$this->isOnline('god|'.$session->god->id);
            Carbon::setLocale('zh-cn');
            $now=Carbon::now();
            $last_time=$session->records->isNotEmpty()?$session->records[0]->created_at:$session->created_at;
            $session->last_time=$last_time->diffForHumans($now);
            $hoursDifference = $now->diffInHours($last_time);
            if($hoursDifference<12){
                $session_temp['dialogue'][] = $session;
            }else{
                $session_temp['recently'][] = $session;
            }
        });
        //获取访问中(邀请中)的用户->查询对应的用户信息

        //已有会话的顾客ids
        $had_god_ids=KefuSessionModel::whereNull('deleted_at')->lists('god_id');
        $query=KefuGodModel::select();
        if($had_god_ids){
            $query->whereNotIn('id',$had_god_ids);
        }
        $invitation_ids=$this->getOnlineGodIds();
        $query->whereIn('id',$invitation_ids);
        $invitations=$query->get();
        $invitations->each(function ($invitation){
            $invitation->god_avatar='/plugins/summer/kefu/assets/img/avatar.png';
            $invitation->godname=$invitation->nickname;
            $invitation->online=1;
            $invitation->unread_msg_count=0;
            $invitation->last_message='';
            $invitation->session_user='god|'.$invitation->id;
            $invitation->last_time=Common::formatAt($invitation->created_at);

        });
        $session_temp['invitation']=$invitations;
        $initialize_data['session'] = $session_temp;

        // 更新当前接待量
        $reception_count = isset($session_temp['dialogue']) ? count($session_temp['dialogue']) : false;
        if ($reception_count) {
            $this->csr_info->reception_count=$reception_count;
            $this->csr_info->save();
        }

        // 向所有人发送上线消息
        $return_data=[];
        $return_data['user_id']=$csr_info->id;
        $return_data['user_name']=$csr_info->nickname;
        $return_data['tourists']='not';
        $return_data['modulename']='admin';
        $this->io->emit('online', $return_data);

        $return_data=[];
        $return_data['data']=$initialize_data;
        $this->socket->emit('initialize', $return_data);
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
                'nickname' => '无会话。',
                'godname' => '无会话',
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
            ->where('sender_identity', 0)
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

                if ( $value['sender_identity'] == 0) {
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
        $return_data['data']['session_info']=$session_info;
        $return_data['data']['next_page']=($data['page'] >= $page_number) ? 'done' : $data['page'] + 1;
        $return_data['data']['page']=$data['page'];
        $this->socket->emit('chat_record', $return_data);

    }
    /**
     * 轨迹记录
     * @param $data
     */
    public function onTrajectory($data){
        $page_count = 20; //一次加载20条

        if (!isset($data['page'])) {
            $data['page'] = 1;
        }
        if (!isset($data['session_user'])) {
            $this->showMsg('用户未找到');
            return;
        }
        // 顾客信息
        $god_exp=explode('|',$data['session_user']);
        $god_info=KefuGodModel::where('id',$god_exp[1])->first();
        if(!$god_info){
            $this->showMsg('用户未找到。');
            return;
        }
        $god_info->session_user = 'god|'.$god_info->id;

        $trajectory_count = KefuTrajectoryModel::where('god_id', $god_info->id)->count('id');
        $page_number = ceil($trajectory_count / $page_count);

        $trajectory_records = KefuTrajectoryModel::where('god_id', $god_info->id)
            ->orderBy('id','DESC')
            ->offset(($data['page'] - 1) * $page_count)
            ->limit($page_count)
            ->get()->toArray();

        // 最后的一条消息
        $last_message = [
            'last_time'    => Common::formatSessionTime(null),
            'last_message' => '无轨迹记录',
        ];

        if (!$trajectory_records) {
            $now_ymd                     = date('Y-m-d');
            $trajectory_temp[$now_ymd][] = [
                'id'         => 1,
                'note'       => '无轨迹记录',
                'log_type'   => 7,
                'createtime' => Common::formatTime(null),
            ];

        } else {

            if ($data['page'] == 1) {
                $last_message = [
                    'last_time'    => Common::formatSessionTime($trajectory_records[0]['createtime']),
                    'last_message' => $trajectory_records[0]['log_type'] == 0 ? '访问 ' . $trajectory_records[0]['url'] : $trajectory_records[0]['note'],
                ];
                if (!isset($data['platform']) || $data['platform'] != 'uni') {
                    $trajectory_records = array_reverse($trajectory_records, false);
                }
            }

            // 按天分组
            $trajectory_temp = [];

            foreach ($trajectory_records as $key => $value) {

                $createtime          = date('Y-m-d', $value['createtime']);
                $value['createtime'] = date('H:i', $value['createtime']);

                $trajectory_temp[$createtime][] = $value;
            }
        }
        $return_data=[];
        $return_data['code']=1;
        $return_data['data']['trajectory']=$trajectory_temp;
        $return_data['data']['god_info']=$god_info;
        $return_data['data']['last_message']=$last_message;
        $return_data['data']['next_page']=($data['page'] >= $page_number) ? 'done' : $data['page'] + 1;
        $return_data['data']['page']=$data['page'];
        $this->socket->emit('trajectory', $return_data);
    }

    /**
     * 用户名片
     * @param $data
     * @throws \Exception
     */
    public function onUserCard($data){
        if (!isset($data['session_user'])) {
            $this->showMsg('用户未找到');
            return;
        }
        // 顾客信息
        $god_exp=explode('|',$data['session_user']);
        $god_info=KefuGodModel::where('id',$god_exp[1])->first();
        if(!$god_info){
            $this->showMsg('用户未找到。');
            return;
        }

        if (isset($data['action']) && $data['action'] == 'done') {//更新
            if (KefuGodModel::where('id', $god_info->id)->update($data['form_data'])) {
                $this->showMsg('保存成功。');
                // 修改后重新获取用户信息
                $god_info=KefuGodModel::where('id',$god_info->id)->first();
                $return_data=[];
                $return_data['code']=1;
                $return_data['data']=$god_info;
                $this->socket->emit('user_card', $return_data);
            } else {
                $this->showMsg('保存失败，请重试！。');
            }
            return;
        }else{// 详情
            $return_data=[];
            $return_data['code']=1;
            $return_data['data']=$god_info;
            $this->socket->emit('user_card', $return_data);
        }
    }

    /**
     * 邀请对话 & 删除会话 & 转接会话 & 修改用户昵称
     * @param $data
     * @throws \Exception
     */
    public function onActionSession($data){
        if (!isset($data['session_user'])) {
            $this->showMsg('用户未找到');
            return;
        }
        // 顾客信息
        $god_exp=explode('|',$data['session_user']);
        $god_info=KefuGodModel::where('id',$god_exp[1])->first();
        if(!$god_info){
            $this->showMsg('用户未找到。');
            return;
        }
        if($data['action']=='del'){ // 删除会话
            $session_info=KefuSessionModel::where('god_id',$god_info->id)->whereNull('deleted_at')->first();
            if(!$session_info){
                $this->showMsg('会话找不到啦。');
                return;
            }
            $session_info->delete();
            if (!$session_info->trashed()) {
                $this->showMsg('会话删除失败。');
                return;
            }else{
                $this->showMsg('会话已移除~');
                return;
            }
        }elseif($data['action']=='invitation'){ // 邀请会话
            if($this->isOnline($data['session_user'])){//是否还在线
                // 记录轨迹
                $trajectory_table=new KefuTrajectoryModel();
                $trajectory_table->god_id=$god_info->id;
                $trajectory_table->csr_id=$this->csr_info->id;
                $trajectory_table->log_type=1;
                $trajectory_table->note=$this->csr_info->nickname." 邀请对话";
                $trajectory_table->save();
                // 顾客收到邀请
                $return_data=[];
                $return_data['code']=1;
                $return_data['data']['action']='received_invitation';
                $this->io->of('/index')->to($data['session_user'])->emit('action_session', $return_data);

                // 当前客服邀请发送成功
                $return_data=[];
                $return_data['code']=1;
                $return_data['data']['action']='send_success';
                $return_data['data']['session_user']=$data['session_user'];
                $this->socket->emit('action_session', $return_data);

            }else{
                $this->showMsg('邀请对话失败，用户已下线！');
                return;
            }
        }elseif($data['action']=='transfer'){// 转接会话-获取客服列表
            // 转接会话->返回客服列表
            $csr_list = KefuCsrModel::with(['target'])
                ->where('admin_id','>',0) //绑定有后台管理员
                ->where('id', '<>',$this->csr_info->id) // 排除当前客服
                ->where('status', 3) // 客服在线
                ->get();
            $return_data=[];
            $return_data['code']=1;
            $return_data['data']['action']='transfer';
            $return_data['data']['session_user']=$data['session_user'];
            $return_data['data']['csr_list']=$csr_list;
            $this->socket->emit('action_session', $return_data);

        }elseif($data['action']=='transfer_done'){// 转接会话-操作
            if (!isset($data['csr']) || !isset($data['session_user'])) {
                $this->showMsg('会话转移失败，请重试~');
                return;
            }
            if ($this->csr_info->id == $data['csr']) {
                $this->showMsg('不能将会话转移给自己哦~');
                return;
            }
            $new_csr_info=KefuCsrModel::where('id',$data['csr'])->first(); //转移的新客服
            $session = $this->distributionCsr($new_csr_info, $god_info); //建立会话
            if($session){
                // 将会话发送给新客服
                if($this->isOnline("csr|".$new_csr_info->id)){
                    $message = [
                        'id'           => $session->id,
                        'session_id'   => $session->id,
                        'session_user' => $data['session_user'],
                        'last_time'    => Common::formatSessionTime(null),
                        'last_message' => '本会话被客服 ' . $this->csr_info->nickname. ' 转移给您',
                        'online'       => 1,
                        'avatar'       => '',
                        'nickname'     => $god_info['nickname'],
                    ];

                    // 查询当前用户发送的未读消息条数
                    $message['unread_msg_count'] = KefuRecordModel::where('session_id', $message['session_id'])
                        ->where('sender_identity', 1)
                        ->where('god_id', $god_info->id)
                        ->where('status', 0)
                        ->count();
                    $message['unread_msg_count'] += 1;
                    $return_data=[];
                    $return_data['data']=$message;
                    $this->io->of('/admin')->to("csr|".$new_csr_info->id)->emit('new_message', $return_data);
                }

                // 发客服转移消息给用户
                if($this->isOnline("god|".$god_info->id)){
                    $return_data=[];
                    $return_data['code']=1;
                    $return_data['data']['action']='transfer_done';
                    $return_data['data']['session_user']=$data['session_user'];
                    $this->io->of($data['session_user'])->emit('transfer_done',$return_data);
                }
                $res = $new_csr_info->nickname;
            }else{
                $res = false;
            }
            // 通知当前客服
            $return_data=[];
            $return_data['code']=1;
            $return_data['data']['action']='transfer_done';
            $return_data['data']['session_user']=$data['session_user'];
            $return_data['data']['res']=$res;
            $this->socket->emit('action_session', $return_data);

        }elseif($data['action']=='edit_nickname'){// 修改用户昵称
            if (!isset($data['new_nickname']) || !$data['new_nickname']) {
                $this->showMsg('新昵称不能为空！');
                return;
            }
            // $new_nickname 是会话框上显示的名字，应该是为了让知道修改前是谁，加上之前的昵称
            $new_nickname=$god_info->nickname?$god_info->nickname. '(' . $data['new_nickname'] . ')' : $data['new_nickname'];
            $list_nickname=$god_info->nickname?$god_info->nickname:$data['new_nickname'];
            $god_info->nickname=$data['new_nickname'];
            $god_info->save();
            $return_data=[];
            $return_data['code']=1;
            $return_data['data']['action']='edit_nickname';
            $return_data['data']['session_user']=$data['session_user'];
            $return_data['data']['new_nickname']=$new_nickname;
            $return_data['data']['list_nickname']=$list_nickname;
            $this->socket->emit('action_session', $return_data);
        }
    }

    /**
     * 搜索用户
     * @param $data
     */
    public function onSearchUser($keywords){
        // 读取会话列表
        try {


        $sessions=KefuSessionModel::with([
            'god',
            'records'=>function($q){
                $q->orderBy('id','DESC');
            }
        ])->whereHas('god', function($q)use($keywords){
            $q->where('nickname','like','%' . $keywords . '%');
        })->where('csr_id',$this->csr_info->id)->whereNull('deleted_at')->limit(40)->orderBy('id','DESC')->get();
        if($sessions->isNotEmpty()){
            $sessions->each(function ($session){
                // 最后一条聊天记录
                $session->last_message=Common::formatMessage($session->records[0]);
                Carbon::setLocale('zh-cn');
                $now=Carbon::now();
                $last_time=$session->records[0]->created_at??$session->created_at;
                $session->last_time=$last_time->diffForHumans($now);
                $session->nickname=$session->god->nickname;
                $session->god_avatar=$session->god->target->avatar?Config::get('filesystems.disks.local.url')."/".$session->god->target->avatar->getDiskPath():'/plugins/summer/kefu/assets/img/avatar.png';
                $session->online=$this->isOnline('god|'.$session->god->id);
            });
        }

        //已有会话的顾客ids
        $had_god_ids=KefuSessionModel::whereNull('deleted_at')->lists('god_id');
        $query=KefuGodModel::select();
        if($had_god_ids){
            $query->whereNotIn('id',$had_god_ids);
        }
        $invitation_ids=$this->getOnlineGodIds();
        $query->whereIn('id',$invitation_ids);
        $query->where('nickname','like','%' . $keywords . '%');
        $invitations=$query->get();
        $invitations->each(function ($invitation){
            $invitation->god_avatar='/plugins/summer/kefu/assets/img/avatar.png';
            $invitation->godname=$invitation->nickname;
            $invitation->online=1;
            $invitation->unread_msg_count=0;
            $invitation->last_message='';
            $invitation->session_user='god|'.$invitation->id;
            $invitation->last_time=Common::formatAt($invitation->created_at);
        });
        $user_list = array_merge($invitations->toArray(), $sessions->toArray());
        $return_data=[];
        $return_data['data']=$user_list;
        $this->socket->emit('search_user', $return_data);
        }catch (\Exception $e){
            print_r($e->getMessage());
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
        $return_data=[];
        $return_data['data']['session_id']=$data['session_id'];
        $return_data['data']['type']=$data['type'];
        if($this->isOnline($data['session_user'])){
            $this->io->of('/index')->to($data['session_user'])->emit('message_input', $return_data);
        }
    }
}
