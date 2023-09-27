<?php namespace Summer\Kefu\Classes;


use PHPSocketIO\SocketIO;
use PHPSocketIO\Socket;
use PHPSocketIO\Nsp;

use Config;
use Carbon\Carbon;
use Summer\Kefu\Models\KefuCsrModel;
use Summer\Kefu\Models\KefuGodModel;
use Summer\Kefu\Models\KefuKbsModel;
use Summer\Kefu\Models\KefuReceptionLogModel;
use Summer\Kefu\Models\KefuRecordModel;
use Summer\Kefu\Models\KefuSessionModel;
use Summer\Kefu\Models\KefuTrajectoryModel;
use Summer\Kefu\Models\Settings;
use System\Models\File;
use Winter\User\Models\User as UserModel;
use Db;
class ChatCom
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
    /**
     * 当前链接的客服信息
     */
    public $csr_info;
    /**
     * 当前链接的顾客信息
     */
    public $god_info;

    public function __construct(SocketIO $io, Nsp $nsp, Socket $socket = null)
    {
        $this->io = $io;
        $this->socket = $socket;
        $this->nsp = $nsp;
        // 常驻内存的程序在使用mysql时经常会遇到mysql gone away的错误，这个是由于程序与mysql的连接长时间没有通讯，连接被mysql服务端踢掉导致。本数据库类可以解决这个问题，当发生mysql gone away错误时，会自动重试一次。
        // 文档的原话,这里案例还是用自带的。
        //        $db_host=env('DB_HOST', '127.0.0.1');
        //        $db_port=env('DB_PORT', '3306');
        //        $db_username=env('DB_USERNAME', '');
        //        $db_password=env('DB_PASSWORD', '');
        //        $db_name=env('DB_DATABASE', '');
        //        $this->db = new \Workerman\MySQL\Connection($db_host, $db_port, $db_username, $db_password, $db_name);
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
        // 发送人身份:0=客服,1=用户
        $sender_identity = $data['sender_identity']??0;
        // 客服信息-
        $csr_info=KefuCsrModel::where('id',$session->csr_id)->first();
        // 顾客信息-
        $god_info=KefuGodModel::where('id',$session->god_id)->first();
        // 接收人信息
        $to_info=null;
        // 接收房间名称
        $to_room=null;
        if($sender_identity==0){//客服发送的信息-接收人是顾客
            $of_name='/index';
            $to_info=$god_info;
            $to_room='god|'.$god_info->id;
            $sender_room='csr|'.$csr_info->id;
            $sender_nickname=$session->csrname;
        }else{//顾客发送的信息-接收人是客服
            $of_name='/admin';
            $to_info=$csr_info;
            $to_room='csr|'.$csr_info->id;
            $sender_room='god|'.$god_info->id;
            $sender_nickname=$session->godname;
        }
        if(!$to_info){
            $return_data=[];
            $return_data['code']=0;
            $return_data['data']['msg']='发送失败,无法确定收信人！';
            $return_data['data']['message_id']=$message_id;
            $this->socket->emit('send_message', $return_data);
            return;
        }
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
            KefuSessionModel::where('id', $session->id)->update([
                'deleted_at' => null,
            ]);

            // 判断接收人是否在线
            if($this->isOnline($to_room)){
                // 加上发信人的信息
                $msg['id']           = $session_id;
                $msg['avatar']       = '';
                $msg['nickname']     = $sender_nickname;
                $msg['session_user'] = $sender_room;
                $msg['online']       = 1;
                $msg['last_message'] = Common::formatMessage($record_table);
                $msg['last_time']    = Common::formatSessionTime(null);
                $msg['message']      = $message_html;
                $msg['message_type']      = ($message_type == 'auto_reply') ? 0 : $message_type;
                $msg['sender']       = 'you';
                // 查询当前用户发送的未读消息条数
                $msg['unread_msg_count']=KefuRecordModel::where('session_id',$session_id)->where('sender_identity',$sender_identity)->where('status',0)->count();
                $return_data=[];
                $return_data['data']=$msg;
                $this->io->of($of_name)->to($to_room)->emit('new_message', $return_data);

                if ($message_type == 'auto_reply') {
                    // 通知客服端：如果客服端口刚好打开的此用户的窗口->重载消息列表以显示自动回复
                    $return_data=[];
                    $return_data['data']['session_id']=$session_id;
                    $this->io->of('/admin')->to('csr|'.$csr_info->id)->emit('reload_record', $return_data);
                }

            }
            // 用户给客服发送消息，检查知识库自动回复
            $message_text = trim(strip_tags($message_html));// 去除消息中的标签
            $kbs_switch=Settings::get('kbs_switch');
            if($sender_identity == 1 && $message_text && $kbs_switch){
                // 读取知识库
                $csr_id=$csr_info->id;
                $kbs=KefuKbsModel::where('status',1)->whereNull('deleted_at')->whereHas('csrs', function ($query) use ($csr_id) {
                    $query->where('id', $csr_id);
                })->orWhereDoesntHave('csrs')->orderBy('weigh','DESC')->get()->toArray();
                // 计算匹配度
                $last_kb_match = 0;
                $best_kb       = [];// 最佳匹配
                $StrComparison=new StrComparison();
                foreach ($kbs as $key => $kb) {
                    $kb_questions = explode(PHP_EOL, $kb['questions']);
                    foreach ($kb_questions as $kb_question) {
                        $kb_question = trim($kb_question);
                        if ($kb_question) {
                            $match_temp = $StrComparison->getSimilar($kb_question, $message_text);
                            if ($match_temp > 0 && $match_temp > $last_kb_match && $match_temp >= $kb['match']) {
                                $last_kb_match = $match_temp;
                                $best_kb       = $kbs[$key];
                            }
                        }
                    }
                }
                if($best_kb){
                    $kbs_data=[];
                    $kbs_data['session_id']=$session_id;
                    $kbs_data['message']=$best_kb['answer'];
                    $kbs_data['message_type']='auto_reply';
                    $kbs_data['sender_identity']=0;
                    $this->inChatRecord($kbs_data);
                }else{
                    $kbs=KefuKbsModel::where('status',2)->whereNull('deleted_at')->whereHas('csrs', function ($query) use ($csr_id) {
                        $query->where('id', $csr_id);
                    })->orWhereDoesntHave('csrs')->orderBy('weigh','DESC')->get()->toArray();
                    foreach ($kbs as $key => $kb){
                        $kbs_data=[];
                        $kbs_data['session_id']=$session_id;
                        $kbs_data['message']=$kb['answer'];
                        $kbs_data['message_type']='auto_reply';
                        $kbs_data['sender_identity']=0;
                        $this->inChatRecord($kbs_data);
                        break;
                    }
                }
            }


            $return_data=[];
            $return_data['code']=1;
            $return_data['data']['id']=$record_table->id;
            $return_data['data']['message_id']=$message_id;
            $this->socket->emit('send_message', $return_data);
            return;
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
     * 分配/转移客服
     * @param int csr_info 客服代表
     * @param int god_info 顾客
     * @return array 新的会话信息
     */
    public function distributionCsr($csr_info, $god_info)
    {
        $welcome_msg=$csr_info->welcome_msg;
        if(!$welcome_msg){
            $welcome_msg=Settings::get('new_user_msg');
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
                $dis_data['sender_identity']=0;
                $this->inChatRecord($dis_data);
                if ($welcome_msg) {
                    // 插入/发送 欢迎信息
                    $dis_data=[];
                    $dis_data['session_id']=$session->id;
                    $dis_data['message']=$welcome_msg;
                    $dis_data['message_type']=0;
                    $dis_data['sender_identity']=0;
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
                    $dis_data['sender_identity']=0;
                    $this->inChatRecord($dis_data);
                }
                Db::commit();
            }catch (Exception $e) {
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
     * 获取在线的顾客ids
     */
    public function getOnlineGodIds(){
        $inputArray=$this->io->of('/index')->adapter->rooms;
        $resultArray = [];
        foreach ($inputArray as $key => $value) {
            // 检查键是否以 'god|' 开头
            if (strpos($key, 'god|') === 0) {
                // 提取后面的数字部分并添加到结果数组中
                $parts = explode('|', $key);
                if (count($parts) === 2) {
                    $resultArray[] = (int)$parts[1];
                }
            }
        }
        return $resultArray;
    }
    /**
     * 显示错误信息
     */
    public function showMsg($msg = '')
    {
        $return_data=[];
        $return_data['code']=0;
        $return_data['msg']=$msg;
        $this->socket->emit('show_msg', $return_data);
    }
    /**
     * 解析 Cookie
     */
    public function parseCookieHeader($cookieHeader) {
        $cookies = [];

        $cookieParts = explode('; ', $cookieHeader);
        foreach ($cookieParts as $cookiePart) {
            list($name, $value) = explode('=', $cookiePart, 2);
            $cookies[$name] = $value;
        }

        return $cookies;
    }
}
