<?php namespace Summer\Kefu\Classes;

use Carbon\Carbon;
use Db;
use Str;
use Exception;
use Summer\Kefu\Models\KefuCsrModel;
use Summer\Kefu\Models\KefuReceptionLogModel;
use Summer\Kefu\Models\KefuRecordModel;
use Summer\Kefu\Models\KefuTrajectoryModel;
use Summer\Kefu\Models\Settings;
use Winter\User\Models\User as UserModel;
use Summer\Kefu\Models\KefuGodModel;
use Summer\Kefu\Models\KefuSessionModel;

class Common
{
    /**
     *  获取最终顾客登录信息
     */
    public static function checkKefuGod($user_auth=null,$god_auth=null){
        $god_info=null; // 顾客信息
        $god_info_dl=null; // 登录的顾客信息
        $god_info_bd=null; // 绑定的顾客信息
        $god_info_xj=null; // 新建的顾客信息
        $user_info=null; // 登录的用户信息
        $user_id=0;
        if($user_auth){ // 验证--有用户登录
            //解析 JSON 数据
            $user_auth_arr = json_decode($user_auth, true);
            $user_id=$user_auth_arr[0];
            $user_info=UserModel::where('id',$user_id)->first();
        }
        if($god_auth){ // 验证--有顾客登录-处理token
            $kefu_god_cookie_dec=json_decode(decrypt($god_auth,false), true);
            list($id, $token) = $kefu_god_cookie_dec;
            $god_info_dl=KefuGodModel::with(['target'])->where('id',$id)->first();
            if($god_info_dl){ // 能根据 cookie 获取顾客信息 -- 验证 token
                if ($token != $god_info_dl->token) {
                    $god_info_dl=null;
                }
            }
        }
        if($user_info){ // 用用户登录
            // 获取绑定的顾客
            $god_info_bd=KefuGodModel::with(['target'])->where('user_id',$user_id)->first();
        }
        if($god_info_bd){ // 用户有绑定的顾客，以绑定的顾客为主
            $god_info=$god_info_bd;
        }else{
            if($god_info_dl){
                $god_info=$god_info_dl;
                //但 如果登录的顾客已经绑定有用户，且与登录的用户不一致。要跳过该顾客
                if($user_id>0 && $god_info_dl->user_id>0 && $user_id!=$god_info_dl->user_id){
                    $god_info=null;
                }
            }
        }
        //【创建】 如果 cookie 和 用户id 都没有获取到 顾客信息。创建顾客信息。
        if(!$god_info){
            $god_info_cj=self::createKefuGod();
            $god_info=$god_info_cj;
        }
        // 创建顾客信息也失败的话，就是有错误。
        if(!$god_info){
            return false;
        }
        //【绑定】
        if($user_id>0 && $user_id!=$god_info->user_id){
            $god_info->user_id=$user_id;
            $god_info->save();
            $trajectory['note'] = '登录为会员:' . $user_info->nickname . '(ID:' . $user_id . ')';
        }
        // 处理顾客信息
        $kefu_god_cookie  = [$god_info->id, $god_info->token];
        //加密后的cookie
        $kefu_god_cookie_enc=encrypt(json_encode($kefu_god_cookie),false);
        $return_data=[];
        $return_data['kefu_god_cookie']=$kefu_god_cookie_enc;
        $return_data['god_info']=$god_info->toArray();
        return $return_data;
    }
    /**
     * 创建一个顾客
     * @return bool|KefuGodModel
     */
    public static function createKefuGod()
    {
        $token = Str::uuid();
        $kefu_god_table=new KefuGodModel();
        $kefu_god_table->referrer=request()->referrer . ' IP:' . request()->ip();
        $kefu_god_table->token=$token;
        $res=$kefu_god_table->save();
        if ($res) {
            return $kefu_god_table;
        }else{
            return false;
        }
    }
    /**
     * 获取合适的客服代表
     * @return string 带标识客服代表ID
     */
    public static function getAppropriateCsr()
    {
        try {
            $csr_distribution = Settings::get('csr_distribution');
            if ($csr_distribution == 0) {
                // 拿到当前接待量最少的客服
                $csr=KefuCsrModel::with(['target'])->where('status',3)->orderBy('reception_count',"ASC")->first();
            } elseif ($csr_distribution == 1) {
                $csr=KefuCsrModel::with(['target'])->where('status',3)->orderByRaw('ceiling - reception_count DESC')->first();
            }elseif($csr_distribution == 2){
                // 分配给最久未进行接待的客服
                $csr=KefuCsrModel::with(['target'])->where('status',3)->orderBy('last_reception_at',"ASC")->first();
            }
            if ($csr) {
                return $csr;
            } else {
                return false;
            }
        }catch (Exception $e){
            print_r($e->getMessage());
        }

    }




    /**
     * 获取用户的未读消息->获取他的会话->获取会话中的非他自己发送的未读消息
     * @param string user_id 带标识符的用户id
     * @param bool is_latest 是否只获取用户已进入网站但未链接websocket期间的消息
     * @return string
     */
    public static function getUnreadMessagesGod($god_info, $is_latest = false)
    {
        $new_msg = '';
        $sessions = KefuSessionModel::where('god_id',$god_info->id)->whereNull('deleted_at')->orderBy('created_at', 'desc')->get();
        foreach ($sessions as $session){
            $query=KefuRecordModel::select();
            $query->where('session_id',$session->id);
            $query->where('sender_identity',0);//0 客服发来的信息
            $query->where('status',0);
            if($is_latest){
                $tenSecondsAgo = Carbon::now()->subSeconds(10);
                $query->where('created_at','>=',$tenSecondsAgo);
            }
            $new_msg=$query->first();
            if($new_msg){
                $new_msg=$god_info->nickname.":".self::formatMessage($new_msg);
                break;
            }
        }
        return $new_msg;
    }
    public static function getUnreadMessagesCsr($csr_info, $is_latest = false)
    {
        $new_msg = '';
        $sessions = KefuSessionModel::where('csr_id',$csr_info->id)->whereNull('deleted_at')->orderBy('created_at', 'desc')->limit(40)->get();
        foreach ($sessions as $session){
            $query=KefuRecordModel::select();
            $query->where('session_id',$session->id);
            $query->where('sender_identity',1);//1 用户发来的信息
            $query->where('status',0);
            if($is_latest){
                $tenSecondsAgo = Carbon::now()->subSeconds(10);
                $query->where('created_at','>=',$tenSecondsAgo);
            }
            $new_msg=$query->first();
            if($new_msg){
                $new_msg=$csr_info->target->first_name.":".self::formatMessage($new_msg);
                break;
            }
        }
        return $new_msg;
    }
    /**
     * 轨迹分析
     */
    public static function trajectoryAnalysis($url)
    {
        if (!$url) {
            return '';
        }

        $parse_url = parse_url($url);
        if (!$parse_url) {
            return $url;
        }

        $parse_url['query'] = isset($parse_url['query']) ? self::convertUrlQuery($parse_url['query']) : false;
        $data['host_name']  = false;
        $data['search_key'] = false;

        if (isset($parse_url['host'])) {

            if ($parse_url['host'] == 'www.baidu.com') {
                $data['host_name']  = '百度';
                $data['search_key'] = isset($parse_url['query']['wd']) ? $parse_url['query']['wd'] : '';
            }

            if ($parse_url['host'] == 'www.so.com') {
                $data['host_name']  = '360搜索';
                $data['search_key'] = isset($parse_url['query']['q']) ? $parse_url['query']['q'] : '';
            }
        }

        if ($data['host_name']) {
            $res = $data['host_name'];
        } else {
            return $url;
        }

        if ($data['search_key']) {
            $res .= '搜索 ' . $data['search_key'];
        }

        return $res;
    }
    /**
     * 解析一个url的参数
     *
     * @param string    query
     * @return    array    params
     */
    public static function convertUrlQuery($query)
    {
        $queryParts = explode('&', $query);

        $params = [];
        foreach ($queryParts as $param) {
            $item             = explode('=', $param);
            $params[$item[0]] = $item[1];
        }

        return $params;
    }
    /**
     * 清理XSS
     */
    public static function removeXss($val)
    {
        $val = preg_replace('/([\x00-\x08,\x0b-\x0c,\x0e-\x19])/', '', $val);

        $search = 'abcdefghijklmnopqrstuvwxyz';
        $search .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $search .= '1234567890!@#$%^&*()';
        $search .= '~`";:?+/={}[]-_|\'\\';
        for ($i = 0; $i < strlen($search); $i++) {
            $val = preg_replace('/(&#[xX]0{0,8}' . dechex(ord($search[$i])) . ';?)/i', $search[$i], $val); // with a ;
            $val = preg_replace('/(&#0{0,8}' . ord($search[$i]) . ';?)/', $search[$i], $val); // with a ;
        }

        $ra1   = [
            'javascript',
            'vbscript',
            'expression',
            'applet',
            'meta',
            'xml',
            'blink',
            'link',
            'style',
            'script',
            'embed',
            'object',
            'iframe',
            'frame',
            'frameset',
            'ilayer',
            'layer',
            'bgsound',
            'title',
            'base'
        ];
        $ra2   = [
            'onabort',
            'onactivate',
            'onafterprint',
            'onafterupdate',
            'onbeforeactivate',
            'onbeforecopy',
            'onbeforecut',
            'onbeforedeactivate',
            'onbeforeeditfocus',
            'onbeforepaste',
            'onbeforeprint',
            'onbeforeunload',
            'onbeforeupdate',
            'onblur',
            'onbounce',
            'oncellchange',
            'onchange',
            'onclick',
            'oncontextmenu',
            'oncontrolselect',
            'oncopy',
            'oncut',
            'ondataavailable',
            'ondatasetchanged',
            'ondatasetcomplete',
            'ondblclick',
            'ondeactivate',
            'ondrag',
            'ondragend',
            'ondragenter',
            'ondragleave',
            'ondragover',
            'ondragstart',
            'ondrop',
            'onerror',
            'onerrorupdate',
            'onfilterchange',
            'onfinish',
            'onfocus',
            'onfocusin',
            'onfocusout',
            'onhelp',
            'onkeydown',
            'onkeypress',
            'onkeyup',
            'onlayoutcomplete',
            'onload',
            'onlosecapture',
            'onmousedown',
            'onmouseenter',
            'onmouseleave',
            'onmousemove',
            'onmouseout',
            'onmouseover',
            'onmouseup',
            'onmousewheel',
            'onmove',
            'onmoveend',
            'onmovestart',
            'onpaste',
            'onpropertychange',
            'onreadystatechange',
            'onreset',
            'onresize',
            'onresizeend',
            'onresizestart',
            'onrowenter',
            'onrowexit',
            'onrowsdelete',
            'onrowsinserted',
            'onscroll',
            'onselect',
            'onselectionchange',
            'onselectstart',
            'onstart',
            'onstop',
            'onsubmit',
            'onunload'
        ];
        $ra    = array_merge($ra1, $ra2);
        $found = true;
        while ($found == true) {

            $val_before = $val;
            for ($i = 0; $i < sizeof($ra); $i++) {
                $pattern = '/';
                for ($j = 0; $j < strlen($ra[$i]); $j++) {
                    if ($j > 0) {
                        $pattern .= '(';
                        $pattern .= '(&#[xX]0{0,8}([9ab]);)';
                        $pattern .= '|';
                        $pattern .= '|(&#0{0,8}([9|10|13]);)';
                        $pattern .= ')*';
                    }
                    $pattern .= $ra[$i][$j];
                }
                $pattern     .= '/i';
                $replacement = substr($ra[$i], 0, 2) . '<k>' . substr($ra[$i], 2);
                $val         = preg_replace($pattern, $replacement, $val);
                if ($val_before == $val) {
                    $found = false;
                }
            }
        }
        return $val;
    }
    /**
     * 格式化会话时间-按天顺时针格式化
     * @param int time 时间戳
     * @return string
     */
    public static function formatSessionTime($time = null)
    {
        if (!$time) {
            return date('H:i');
        }
        $now_date  = getdate(time());
        $time_date = getdate($time);

        if (($now_date['year'] === $time_date['year']) && ($now_date['yday'] === $time_date['yday'])) {
            return date('H:i', $time);
        } else {
            return self::formatTime($time);
        }
    }
    public static function formatTime($time = null){
        $now_time = time();
        if($time === null){
            $time=$now_time-1;
        }
        $now=Carbon::now();
        $time_bon=Carbon::createFromTimestamp($time);
        $diffForHumans=$time_bon->diffForHumans($now);
        return $diffForHumans;
    }
    public static function formatAt($time_bon){
        $now=Carbon::now();
        $diffForHumans=$time_bon->diffForHumans($now);
        return $diffForHumans;
    }
    /**
     * 格式化消息-将图片和连接用文字代替
     * @param array message 消息内容
     * @return string
     */
    public static function formatMessage($message)
    {
        if (!$message) {
            return '';
        }
        if ($message->message_type == 0 || $message->message_type == 3) {
            $message_text = htmlspecialchars_decode($message->message);

            // 匹配所有的img标签
            $preg = '/<img.*?src=(.*?)>/is';
            preg_match_all($preg, $message_text, $result, PREG_PATTERN_ORDER);
            $message_text = str_replace($result[0], '[图片]', $message_text);
            $message_text = strip_tags($message_text);

        } elseif ($message->message_type == 1) {
            $message_text = '[图片]';
        } elseif ($message->message_type == 2) {
            $message_text = '[链接]';
        } elseif ($message->message_type == 4) {
            $message_text = '[商品卡片]';
        } elseif ($message->message_type == 5) {
            $message_text = '[订单卡片]';
        } else {
            $message_text = strip_tags($message->message);
        }

        return $message_text;
    }
}
