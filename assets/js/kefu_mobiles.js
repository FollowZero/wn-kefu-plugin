// 音频播放器
window.AudioContext = window.AudioContext || window.webkitAudioContext || window.mozAudioContext || window.msAudioContext;

var KeFu = {
    ws: {
        SocketTask: null,
        Timer: null,// 计时器
        ErrorMsg: [],// 发送失败的消息
        MaxRetryCount: 3,// 最大重连次数
        CurrentRetryCount: 0,// 当前重试次数
        url: null
    },
    audio: {
        context: new window.AudioContext(),
        source: null,
        buffer: null
    },
    config: null, // 初始化后,包含所有的客服插件配置
    url: null,
    fixed_csr: 0,
    token_list: [],
    csr: "",// 当前用户的客服代表ID
    session_id: 0,// 会话ID
    session_user: "",// 带身份标识的用户ID
    show_send: false, // 是否显示发送按钮
    record_scroll_height: 0,// 聊天记录滚动条高度
    emoji_config: [
        {'title': '[zy]', 'src': '/emoji/1.png'},
        {'title': '[zm]', 'src': '/emoji/2.png'},
        {'title': '[jy]', 'src': '/emoji/3.png'},
        {'title': '[jyb]', 'src': '/emoji/4.png'},
        {'title': '[bx]', 'src': '/emoji/5.png'},
        {'title': '[kzn]', 'src': '/emoji/6.png'},
        {'title': '[gg]', 'src': '/emoji/7.png'},
        {'title': '[ll]', 'src': '/emoji/8.png'},
        {'title': '[jyll]', 'src': '/emoji/9.png'},
        {'title': '[o]', 'src': '/emoji/10.png'},
        {'title': '[yz]', 'src': '/emoji/11.png'},
        {'title': '[wx]', 'src': '/emoji/12.png'},
        {'title': '[zyb]', 'src': '/emoji/13.png'},
        {'title': '[tp]', 'src': '/emoji/14.png'},
        {'title': '[wxb]', 'src': '/emoji/15.png'},
        {'title': '[zyc]', 'src': '/emoji/16.png'},
        {'title': '[llb]', 'src': '/emoji/17.png'},
        {'title': '[xm]', 'src': '/emoji/18.png'},
        {'title': '[qz]', 'src': '/emoji/19.png'},
        {'title': '[zmb]', 'src': '/emoji/20.png'},
        {'title': '[kx]', 'src': '/emoji/21.png'},
        {'title': '[mm]', 'src': '/emoji/22.png'},
        {'title': '[bz]', 'src': '/emoji/23.png'},
        {'title': '[bkx]', 'src': '/emoji/24.png'},
        {'title': '[mg]', 'src': '/emoji/25.png'},
        {'title': '[pz]', 'src': '/emoji/26.png'},
        {'title': '[pzb]', 'src': '/emoji/27.png'},
        {'title': '[wxc]', 'src': '/emoji/28.png'},
        {'title': '[jyc]', 'src': '/emoji/29.png'},
        {'title': '[jyd]', 'src': '/emoji/30.png'},
        {'title': '[dm]', 'src': '/emoji/31.png'},
        {'title': '[tpb]', 'src': '/emoji/32.png'},
        {'title': '[tpc]', 'src': '/emoji/33.png'},
        {'title': '[tpd]', 'src': '/emoji/34.png'},
        {'title': '[ly]', 'src': '/emoji/35.png'},
        {'title': '[zyd]', 'src': '/emoji/36.png'},
    ],
    initialize: function (settings=null, modulename = 'index', fixed_csr = 0) {

        KeFu.config = settings;
        KeFu.ws.url = settings.iourl+'/'+modulename;

        // 新消息
        if (settings.new_msg) {
            KeFu.newMessagePrompt();
        } else if (!localStorage.getItem('kefu_new_user')) {
            KeFu.newMessagePrompt();
        }
        /// 初始化表情
        if (!$('#kefu_emoji').html()) {

            for (var i in KeFu.emoji_config) {
                $('#kefu_emoji').append(KeFu.buildChatImg(KeFu.emoji_config[i].src, KeFu.emoji_config[i].title, 'emoji'));
            }

        }
        // 立即链接 Websocket
        KeFu.ConnectSocket();

        KeFu.eventReg();

    },
    ConnectSocket: function () {
        var socket = io(KeFu.ws.url,
            {
                reconnection: true,// 是否自动重连 默认为true
                reconnectionAttempts: 3, // 尝试重连次数
                reconnectionDelay:3000, // 3000表示3秒尝试重连一次
            }
        );
        KeFu.ws.SocketTask = socket;
        // 当连接服务端成功时触发connect默认事件
        socket.on('connect', function(){
            console.log('connect success');
        });

        socket.on('test', function(onDate){
            console.log('test');
            console.log(onDate);
        });

        // 连接成功后的初始化
        socket.on('initialize', function(msg){
            console.log('initialize');
            console.log(msg);
            $('#kefu_error').html('');
            if (KeFu.ws.ws_error) {
                KeFu.ws.CurrentRetryCount = 0;
                KeFu.ws.ws_error = false;
            }
            if (msg.data.new_msg) {
                KeFu.newMessagePrompt();
            }

            // 分配/获取客服->获取聊天记录
            var user_initialize = {
                c: 'Message',
                a: 'userInitialize',
                data: {
                    fixed_csr: KeFu.fixed_csr
                }
            };
            KeFu.wsSend(user_initialize);

        });
        // 用户客服分配结束
        socket.on('user_initialize', function(msg){
            console.log('user_initialize');
            console.log(msg);
            if (msg.code == 1) {
                if (msg.data.session.user_tourists) {
                    KeFu.sendMessage = function () {
                        layer.msg('请登录后发送消息~');
                    }
                    KeFu.edit_send_tis('为保护您的隐私请 <a href="' + msg.data.session.user_login_url + '">登录</a> 后发送消息');
                }
                KeFu.csr = msg.data.session.csr;
                KeFu.session_id = msg.data.session.id;
                KeFu.toggleWindowView('chat_wrapper');
                $('#header_title').html('客服 ' + msg.data.session.csrname + ' 为您服务');
                KeFu.changeCsrStatus(msg.data.session.csr.status);
            } else if (msg.code == 302) {
                if (!KeFu.csr) {
                    // 打开留言板
                    KeFu.csr = 'none';
                    KeFu.toggleWindowView('kefu_leave_message');
                    $('#header_title').html('当前无客服在线哦~');
                } else {
                    layer.msg('当前客服暂时离开,您可以直接发送离线消息~');
                }
            }
        });
        //离线留言返回信息
        socket.on('leave_message', function(msg){
            console.log('leave_message');
            console.log(msg);
            layer.msg(msg.msg);
            $('#kefu_leave_message form')[0].reset()
        });
        // 获取聊天记录
        socket.on('chat_record', function(msg){
            console.log('chat_record');
            console.log(msg);
            if (msg.data.page == 1) {
                $('.chat_wrapper').html('');
            }

            var chat_record = msg.data.chat_record;
            KeFu.chat_record_page = msg.data.next_page;

            for (let i in chat_record) {

                if (msg.data.page == 1) {
                    KeFu.buildPrompt(chat_record[i].datetime, msg.data.page);
                }

                for (let y in chat_record[i].data) {
                    KeFu.buildRecord(chat_record[i].data[y], msg.data.page)
                }

                if (msg.data.page != 1) {
                    KeFu.buildPrompt(chat_record[i].datetime, msg.data.page);
                }
            }

            if (msg.data.page == 1) {
                setTimeout(function () {
                    $('.chat_wrapper').scrollTop($('.chat_wrapper')[0].scrollHeight);
                }, 150)
            } else {
                $('.chat_wrapper').scrollTop($('.chat_wrapper')[0].scrollHeight - KeFu.record_scroll_height);
            }

        });
        //显示错误信息
        socket.on('show_msg', function(msg){
            layer.msg(msg.msg);
        });
        //断开连接
        socket.on('disconnect', function(){
            KeFu.ws.ws_error = true;
            $('#kefu_error').html('网络链接已断开');
            KeFu.toggle_popover('show', 'WebSocket 链接已断开');
        });
        // 尝试重连
        socket.io.on("reconnect_attempt", (attempt) => {
            console.log('尝试重连第'+attempt+'次');
        });
        // 收到新消息
        socket.on('new_message', function(msg){
            console.log('new_message');
            console.log(msg);

            if ($('.chat_wrapper').children('.status').children('span').eq(0).html() == '还没有消息') {
                $('.chat_wrapper').children('.status').children('span').eq(0).html('刚刚');
            }
            KeFu.newMessagePrompt();
            KeFu.buildRecord(msg.data, 1);
            if (msg.data.session_id == KeFu.session_id) {

                $('#kefu_input_status').html('');

                $('.chat_wrapper').scrollTop($('.chat_wrapper')[0].scrollHeight);

                var load_message = {
                    c: 'Message',
                    a: 'readMessage',
                    data: {
                        record_id: msg.data.record_id,
                        session_id: KeFu.session_id
                    }
                };

                KeFu.wsSend(load_message);
                return;
            }
        });
        // 发送信息
        socket.on('send_message', function(msg){
            console.log('send_message');
            console.log(msg);
            if (!msg.data.message_id) {
                return;
            }
            if (msg.code == 1) {
                let message_record = $('.kefu_message_' + msg.data.message_id);
                message_record.text('未读');
                message_record.removeClass('kefu_message_' + msg.data.message_id);
                message_record.addClass('kefu_message_' + msg.data.id);
            } else {
                $('.kefu_message_' + msg.data.message_id).addClass('kf-text-red').text('失败');
                layer.msg(msg.data.msg)
            }
        });

        socket.on('csr_change_status', function(msg){
            console.log('csr_change_status');
            console.log(msg);
            console.log(KeFu.csr);
            if (KeFu.csr.id == msg.data.csr) {
                KeFu.change_csr_status(msg.data.csr_status);
            }
        });
        // 输入状态更新
        socket.on('message_input', function(msg){
            console.log('message_input');
            console.log(msg);
            var input_status_display = parseInt(KeFu.config.input_status_display);
            if (input_status_display == 0 || input_status_display == 2) {
                return;
            }
            if (msg.data.type == 'input') {
                $('#kefu_input_status').html('对方正在输入...');
            } else {
                $('#kefu_input_status').html('');
            }
        });
        //重载聊天记录
        socket.on('reload_record', function(msg){
            console.log('reload_record');
            console.log(msg);
            // 重载聊天记录
            if (KeFu.session_id == msg.data.session_id) {
                var load_record = {
                    c: 'Message',
                    a: 'chatRecord',
                    data: {
                        session_id: msg.data.session_id,
                        page: 1
                    }
                };
                KeFu.ws_send(load_record)
            }
        });
    },

    wsSend: function (message) {
        if (!message) {
            message = {c: 'Message', a: 'ping', data:null};
        }
        if (KeFu.ws.SocketTask) {
            KeFu.ws.SocketTask.emit(message.a,message.data);
        } else {
            // console.log('消息发送出错', message)
            KeFu.ws.ErrorMsg.push(message);
        }
    },
    toggleWindowView: function (show_view_id) {
        // 显示留言板
        if (show_view_id == 'kefu_leave_message') {
            $('#kefu_leave_message').show(100);
            $('.write').hide();
            $('.chat_wrapper').css('bottom', '0px');
            $('.footer_div').hide();
            $('.select_model').hide();
        } else if (show_view_id == 'more') {
            $('.footer_div').show();
            $('.write').css('bottom', '170px');
            $('.chat_wrapper').css('bottom', '222px');
            $('.chat_wrapper').scrollTop($('.chat_wrapper')[0].scrollHeight);
            $('.select_model').hide();
        } else if (show_view_id == 'select_model') {
            $('#kefu_leave_message').hide();
            $('.footer_div').hide();
            $('.write').hide();
            $('.chat_wrapper').css('bottom', '222px');
            $('.select_model').show();
        } else {
            $('#kefu_leave_message').hide();
            $('.footer_div').hide();
            $('.write').show().css('bottom', '0px');
            $('.chat_wrapper').css('bottom', '52px');
            $('.select_model').hide();
        }
    },
    kefuMessageChange: function () {
        if (!KeFu.show_send) {
            KeFu.toggleSendBtn('show');
        }

        var el = $('#kefu_message'), el_height, chat_wrapper_bottom;
        if (el.val() == '') {
            el_height = 35;
            KeFu.toggleSendBtn('hide');
        } else {
            el_height = el[0].scrollHeight;
        }

        el.css('height', el_height + 'px');

        chat_wrapper_bottom = (el_height > 35) ? 70 : 52;

        $('.chat_wrapper').css('bottom', chat_wrapper_bottom + 'px');
        $('#kefu_emoji').css('bottom', chat_wrapper_bottom + 'px');
    },
    eventReg: function () {

        $(document).on('click', '.close_select_model', function () {
            KeFu.toggleWindowView('chat_wrapper')
        })

        $('.toolbar_item').on('click', function (e) {
            KeFu.toggleWindowView('select_model')
            var toolbar_type = $(e.currentTarget).data('toolbar_type');
            if (toolbar_type != 'goods' && toolbar_type != 'order') {
                return;
            }
            var api_url = KeFu.buildUrl(KeFu.url, KeFu.config.modulename, toolbar_type);

            var index = layer.load(0, {shade: false});
            $.ajax(api_url, {
                success: res => {
                    if (res.code == 1) {
                        $('.select_model .card_list').html('');
                        for (let i in res.data) {

                            var input_value = '';
                            for (let y in res.data[i]) {
                                input_value += y + '=' + res.data[i][y] + '#';
                            }

                            let project_item = '<label class="record_card">\n' +
                                '<img src="' + res.data[i].logo + '" />\n' +
                                '<div class="record_card_body">\n' +
                                '   <div class="record_card_title">' + res.data[i].subject + '</div>\n' +
                                (res.data[i].note ? '<div class="record_card_note">' + res.data[i].note + '</div>\n' : '') +
                                '   <div class="record_card_price">\n' +
                                (res.data[i].price ? '<span>￥' + res.data[i].price + '</span>\n' : '') +
                                (res.data[i].number ? '<span>x' + res.data[i].number + '</span>\n' : '') +
                                '   </div>\n' +
                                '</div>\n' +
                                '<input name="' + toolbar_type + '" type="radio" value="' + input_value + '" />\n' +
                                '</label>';
                            $('.select_model .card_list').append(project_item);
                        }
                    } else {
                        layer.msg(res.msg)
                    }
                },
                error: res => {
                    layer.msg('加载失败,请重试~');
                },
                complete: res => {
                    layer.close(index)
                }
            });
        })

        // 选择订单或商品
        $(document).on('click', '.select_model .card_list input', function (e) {
            KeFu.sendMessage($(e.target).val(), (e.target.name == 'goods') ? 4 : 5);
            KeFu.toggleWindowView('chat_wrapper')
            $('#kefu_message').focus();
        })

        $(document).on('click', '.more', function (e) {
            $('#kefu_emoji').hide();
            if ($('.footer_div').css('display') == 'block') {
                KeFu.toggleWindowView('chat_wrapper');
            } else {
                KeFu.toggleWindowView('more');
            }
        });

        // 聊天图片预览
        $(document).on('click', '.bubble img', function (e) {

            var img_obj = $(e.target);
            if (img_obj.hasClass('emoji')) {
                return;
            }
            img_obj = img_obj[0];

            layer.photos({
                photos: {
                    "title": "聊天图片预览",
                    "id": "record",
                    data: [
                        {
                            "src": img_obj.src
                        }
                    ]
                }, anim: 5 //0-6的选择，指定弹出图片动画类型，默认随机
            });

        });

        // 显示表情选择面板
        $(document).on('click', '.smiley', function (e) {
            $('#kefu_emoji').toggle(200);
            KeFu.toggleWindowView('chat_wrapper');
        });

        // 选择表情
        $(document).on('click', '#kefu_emoji img', function (e) {
            $('#kefu_message').val($('#kefu_message').val() + $(e.target).data('title'));
            KeFu.kefuMessageChange();
            $('#kefu_emoji').hide();
            // 获取焦点
            $('#kefu_message').focus();
        });

        // 用户点击聊天记录窗口，隐藏表情面板
        $(document).on('click', '.chat_wrapper,#chatfile', function () {
            $('#kefu_emoji').hide();
            KeFu.toggleWindowView('chat_wrapper');
        });

        // 用户选择了文件
        $(document).on('change', '#chatfile', function (e) {
            var file = $('#chatfile')[0].files[0];

            if (!file) {
                return;
            }

            // 上传文件
            var formData = new FormData();
            formData.append("file_data", file);
            if (KeFu.config.upload.multipart) {
                for (let i in KeFu.config.upload.multipart) {
                    formData.append(i, KeFu.config.upload.multipart[i]);
                }
            }

            $.ajax({
                url: KeFu.config.upload.uploadurl,
                type: 'post',
                data: formData,
                contentType: false,
                processData: false,
                success: function (res) {
                    if (res.code == 1) {

                        var file_suffix = res.data.extension;

                        if (file_suffix == 'png' ||
                            file_suffix == 'jpg' ||
                            file_suffix == 'gif' ||
                            file_suffix == 'jpeg') {

                            KeFu.sendMessage(res.data.id, 1,res.data.path);
                        } else {
                            KeFu.sendMessage(res.data.id, 2,res.data.path);
                        }
                    } else {
                        layer.msg(res.msg);
                    }
                },
                error: function (e) {
                    layer.msg('文件上传失败,请重试！');
                },
                complete: function () {
                    $('#chatfile').val('');
                }
            })
        });

        // 加载更多聊天记录
        document.addEventListener('scroll', function (event) {

            if ($(event.target).scrollTop() == 0 && KeFu.chat_record_page != 'done') {
                if (event.target.className == 'chat_wrapper') {

                    if (!KeFu.session_id) {
                        return;
                    }

                    // 加载历史聊天记录
                    var load_message = {
                        c: 'Message',
                        a: 'chatRecord',
                        data: {
                            session_id: KeFu.session_id,
                            page: KeFu.chat_record_page
                        }
                    };
                    KeFu.record_scroll_height = $('.chat_wrapper')[0].scrollHeight;

                    KeFu.wsSend(load_message);
                }
            }

        }, true);

        // 消息输入框换行和输入监听
        $('#kefu_message').on('input propertychange', function () {
            KeFu.kefuMessageChange();
        });

        $('#kefu_message').on('focus', function () {
            KeFu.toggleWindowView('chat_wrapper');
            setTimeout(function () {
                $('.chat_wrapper').scrollTop($('.chat_wrapper')[0].scrollHeight);
            }, 400)
        })

        // 发送消息监听
        $('.send_btn').on('click', function () {
            var message = $('#kefu_message').val();
            KeFu.sendMessage(message, 0);
        })

        // 提交留言
        $(document).on('click', '#kefu_leave_message form button', function (event) {

            var form_data = {};
            var t = $('#kefu_leave_message form').serializeArray();
            $.each(t, function () {
                form_data[this.name] = this.value;
            });

            if (!form_data['contact']) {
                layer.msg('联系方式不能为空哦~');
                return false;
            }

            var leave_message = {
                c: 'Message',
                a: 'leaveMessage',
                data: form_data
            };
            KeFu.wsSend(leave_message);
            return false;
        });

        KeFu.messageInputEventReg();

    },
    messageInputEventReg: function () {
        var input_status_display = parseInt(KeFu.config.input_status_display);
        if (input_status_display == 0) {
            return;
        }

        $(document).on('input', '#kefu_message', function (e) {
            var kefu_message_input = {
                c: 'Message',
                a: 'messageInput',
                data: {
                    session_id: KeFu.session_id,
                    session_user: KeFu.csr || KeFu.session_user,
                    type: 'input'
                }
            };
            KeFu.wsSend(kefu_message_input);
        });

        $(document).on('blur', '#kefu_message', function (e) {
            var kefu_message_input = {
                c: 'Message',
                a: 'messageInput',
                data: {
                    session_id: KeFu.session_id,
                    session_user: KeFu.csr || KeFu.session_user,
                    type: 'blur'
                }
            };
            KeFu.wsSend(kefu_message_input);
        });
    },
    buildPrompt: function (data, page) {
        if (page == 1) {
            $('.chat_wrapper').append('<div class="status"><span>' + data + '</span></div>');
        } else {
            $('.chat_wrapper').prepend('<div class="status"><span>' + data + '</span></div>');
        }
    },
    buildChatImg: function (filename, facename, class_name = 'emoji') {

        var protocol = window.location.protocol + '//';

        if (class_name == 'emoji') {
            return '<img class="emoji" ' + (facename ? 'data-title="' + facename + '"' : '') + ' src="' + '/plugins/summer/kefu/assets/img' + filename + '" />';
        } else {
            return '<img class="' + class_name + '" title="' + facename + '" src="' + filename + '" />';
        }
    },
    buildChatA: function (filepath, file_suffix, class_name) {
        if (class_name == 'record') {
            return '<a target="_blank" class="' + class_name + '" href="' + filepath + '">点击下载：' + file_suffix + ' 文件</a>';
        } else {
            return '<a target="_blank" class="' + class_name + '" href="' + filepath + '">点击下载：' + file_suffix + ' 文件</a>';
        }
    },
    buildChatCard: function (message, message_type) {
        var message = message.split('#');
        var message_arr = [];
        for (let i in message) {
            let message_temp = message[i].split('=');
            if (typeof message_temp[1] != 'undefined') {
                message_arr[message_temp[0]] = message_temp[1];
            }
        }

        var card_url = 'javascript:;';

        return '<a class="record_card_a" href="' + card_url + '">\n' +
            '   <div class="record_card">\n' +
            '       <img src="' + message_arr['logo'] + '" />\n' +
            '       <div class="record_card_body">\n' +
            '           <div class="record_card_title">' + message_arr['subject'] + '</div>\n' +
            (message_arr['note'] ? '<div class="record_card_note">' + message_arr['note'] + '</div>\n' : '') +
            '           <div class="record_card_price">\n' +
            (message_arr['price'] ? '<span>￥' + message_arr['price'] + '</span>\n' : '') +
            (message_arr['number'] ? '<span>x' + message_arr['number'] + '</span>\n' : '') +
            '           </div>\n' +
            '       </div>\n' +
            '   </div>\n' +
            '   </a>';
    },
    buildRecord: function (data, page, message_id = 'none') {

        var message = '';

        if (data.message_type == 1) {
            message = KeFu.buildChatImg(data.message, '聊天图片', 'record');
        } else if (data.message_type == 2) {
            var file_name = data.message.split('.');
            var file_suffix = file_name[file_name.length - 1];
            message = KeFu.buildChatA(data.message, file_suffix, 'record');
        } else if (data.message_type == 3) {
            KeFu.buildPrompt(data.message, page);
            return;
        } else if (data.message_type == 4 || data.message_type == 5) {
            message = KeFu.buildChatCard(data.message, data.message_type);
        } else {
            message = data.message;
        }

        var status_html = '';
        if (data.sender == 'me') {
            if (message_id == 'none') {
                message_id = data.id;
                var msg_status = parseInt(data.status);
                status_html = '<span class="kefu_message_status kefu_message_' + message_id + (msg_status == 0 ? '' : ' kf-text-grey') + '">' + (msg_status == 0 ? '未读' : '已读') + '</span>';
            } else {
                status_html = '<span class="kefu_message_status kefu_message_' + message_id + '"></span>';
            }
        }

        if (page == 1) {
            $('.chat_wrapper').append('<div class="bubble ' + data.sender + '">' + message + status_html + '</div>');
        } else {
            $('.chat_wrapper').prepend('<div class="bubble ' + data.sender + '">' + message + status_html + '</div>');
        }
    },
    findEmoji: function (emoji_title) {
        for (let i in KeFu.emoji_config) {
            if (KeFu.emoji_config[i].title == emoji_title) {
                return KeFu.emoji_config[i];
            }
        }
        return false;
    },
    sendMessage: function (message, message_type,message_path='') {

        if (message == '') {
            layer.msg('请输入消息内容~');
            return;
        }

        // 检查 websocket 是否连接
        if (!KeFu.ws.SocketTask) {
            layer.msg('网络链接异常，请刷新重试~');
            return;
        }

        if (!KeFu.session_id) {
            layer.msg('请选择一个会话~');
            return;
        }

        if (message_type == 0) {

            // 处理表情
            var reg = /\[(.+?)\]/g; // [] 中括号
            var reg_match = message.match(reg);

            if (reg_match) {
                for (let i in reg_match) {
                    var emoji_item = KeFu.findEmoji(reg_match[i]);
                    message = message.replace(emoji_item.title, KeFu.buildChatImg(emoji_item.src, '', 'emoji'))
                }
            }

            $('#kefu_message').val('');
            KeFu.kefuMessageChange();
        }

        var message_id = new Date().getTime() + KeFu.session_id + Math.floor(Math.random() * 10000);
        var load_message = {
            c: 'Message',
            a: 'sendMessage',
            data: {
                message: message,
                message_type: message_type,
                session_id: KeFu.session_id,
                modulename: KeFu.config.modulename,
                message_id: message_id
            }
        };

        KeFu.wsSend(load_message);

        var data = {
            sender: 'me',
            message: (message_type == 1 || message_type == 2) ? message_path : message,
            message_type: message_type
        }

        KeFu.buildRecord(data, 1, message_id);

        setTimeout(function () {
            $('.chat_wrapper').scrollTop($('.chat_wrapper')[0].scrollHeight);
        }, 150)
    },
    onUploadResponse: function (response) {
        try {
            var ret = typeof response === 'object' ? response : JSON.parse(response);
            if (!ret.hasOwnProperty('code')) {
                $.extend(ret, {code: -2, msg: response, data: null});
            }
        } catch (e) {
            var ret = {code: -1, msg: e.message, data: null};
        }
        return ret;
    },
    newMessagePrompt: function () {

        if (KeFu.audio.buffer) {
            KeFu.playSound();
        } else {
            let url = KeFu.config.ringing_path;

            KeFu.loadAudioFile(url);
        }
    },
    playSound: function () {
        KeFu.audio.source = KeFu.audio.context.createBufferSource();
        KeFu.audio.source.buffer = KeFu.audio.buffer;
        KeFu.audio.source.loop = false;
        KeFu.audio.source.connect(KeFu.audio.context.destination);
        KeFu.audio.source.start(0); //立即播放
    },
    loadAudioFile: function (url) {
        var xhr = new XMLHttpRequest(); //通过XHR下载音频文件
        xhr.open('GET', url, true);
        xhr.responseType = 'arraybuffer';
        xhr.onload = function (e) { //下载完成

            KeFu.audio.context.decodeAudioData(this.response,
                function (buffer) { //解码成功时的回调函数
                    KeFu.audio.buffer = buffer;
                    KeFu.playSound();
                },
                function (e) { //解码出错时的回调函数
                    console.log('音频解码失败', e);
                });
        };
        xhr.send();
    },
    toggleSendBtn: function (toggle) {
        if (toggle == 'show') {
            $('.widget_textarea').css('flex', '7');
            $('.write_right').css('flex', '3');
            $('.send_btn').show(200);
            $('.more').hide();
            KeFu.show_send = true;
        } else {
            $('.widget_textarea').css('flex', '8');
            $('.write_right').css('flex', '2');
            $('.more').show(200);
            $('.send_btn').hide();
            KeFu.show_send = false;
        }
    },
    changeCsrStatus: function (status_id) {

        status_id = parseInt(status_id);

        const states = new Map([
            [0, ['离线', '#777']],
            [1, ['繁忙', '#8a6d3b']],
            [2, ['离开', '#a94442']],
            [3, ['在线', '#3c763d']],
            ['default', ['未知', '#777']],
        ])

        let state = states.get(status_id) || states.get('default');

        $('#csr_status').html(' • ' + state[0]);
        $('#csr_status').css('color', state[1]);
    },
    getQueryVariable: function (variable) {
        var query = window.location.search.substring(1);
        var vars = query.split("&");
        for (var i = 0; i < vars.length; i++) {
            var pair = vars[i].split("=");
            if (pair[0] == variable) {
                return pair[1];
            }
        }
        return (false);
    }
};
