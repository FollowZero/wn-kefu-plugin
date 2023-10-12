<?php namespace Summer\Kefu\Console;

use Illuminate\Console\Command;
use Summer\Kefu\Models\Settings;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Workerman\Worker;
use PHPSocketIO\SocketIO;

use Summer\Kefu\Classes\ChatIndex;
use Summer\Kefu\Classes\ChatAdmin;

class KefuCommand extends Command
{
    /**
     * @var string The console command name.
     */
    protected $name = 'summer:kefu';

    /**
     * 简介-运行计划
     * 此命令是用来启动 Summer/Kefu 的客服服务端进程
     */
    protected $description = '客服';

    /**
     * Execute the console command.
     * @return void
     */
    public function handle()
    {
        $port=Settings::get('port','39701');
        // 创建socket.io服务端，监听3120端口
        $io = new SocketIO($port);

        $nsp_index=$io->of('/index');
        $nsp_index->on('connection',function ($socket_index)use($nsp_index,$io){
            print_r(1);
            // 绑定客服连接事件
            try {
                $chat_index = new ChatIndex($io, $nsp_index, $socket_index);
                $chat_index->on();
            } catch (\Exception $e) {
                echo $e->getMessage();
            }
        });
        $nsp_admin=$io->of('/admin');
        $nsp_admin->on('connection',function ($socket_admin)use($nsp_admin,$io){
            print_r(2);
            // 绑定客服连接事件
            try {
                $chat_admin = new ChatAdmin($io, $nsp_admin, $socket_admin);
                $chat_admin->on();
            } catch (\Exception $e) {
                echo $e->getMessage();
            }
        });
        Worker::runAll();
    }

    /**
     * Get the console command arguments.
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['action', InputArgument::OPTIONAL, 'action start|stop|restart|status','start'],
        ];
    }

    /**
     * Get the console command options.
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['daemon','d', InputOption::VALUE_NONE, 'd -d'],
        ];
    }

}
