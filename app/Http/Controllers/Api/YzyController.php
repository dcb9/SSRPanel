<?php

namespace App\Http\Controllers\Api;

use App\Components\Helpers;
use App\Components\PaymentComponent;
use App\Http\Controllers\Controller;
use App\Http\Models\Payment;
use App\Http\Models\PaymentCallback;
use Illuminate\Http\Request;
use Log;
use DB;
use Mail;
use Hash;

/**
 * 有赞云支付消息推送接收
 *
 * Class YzyController
 *
 * @package App\Http\Controllers
 */
class YzyController extends Controller
{
    protected static $systemConfig;

    function __construct()
    {
        self::$systemConfig = Helpers::systemConfig();
    }

    // 接收GET请求
    public function index(Request $request)
    {
        \Log::info("【有赞云】回调接口[GET]：" . var_export($request->all(), true) . '[' . getClientIp() . ']');
    }

    // 接收POST请求
    public function store(Request $request)
    {
        \Log::info("【有赞云】回调接口[POST]：" . var_export($request->all(), true));

        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        if (!$data) {
            Log::info('YZY-POST:回调数据无法解析，可能是非法请求[' . getClientIp() . ']');
            exit();
        }

        // 判断消息是否合法
        $msg = $data['msg'];
        $sign_string = self::$systemConfig['youzan_client_id'] . "" . $msg . "" . self::$systemConfig['youzan_client_secret'];
        $sign = md5($sign_string);
        if ($sign != $data['sign']) {
            Log::info('本地签名：' . $sign_string . ' | 远程签名：' . $data['sign']);
            Log::info('YZY-POST:回调数据签名错误，可能是非法请求[' . getClientIp() . ']');
            exit();
        } else {
            // 返回请求成功标识给有赞
            var_dump(["code" => 0, "msg" => "success"]);
        }

        // 容错
        if (!isset($data['kdt_name'])) {
            Log::info("【有赞云】回调数据解析错误，请检查有赞支付设置是否与有赞控制台中的信息保持一致。如果还出现此提示，请执行一遍php artisan cache:clear命令");
            exit();
        }

        // 先写入回调日志
        $this->callbackLog($data['client_id'], $data['id'], $data['kdt_id'], $data['kdt_name'], $data['mode'], $data['msg'], $data['sendCount'], $data['sign'], $data['status'], $data['test'], $data['type'], $data['version']);

        // msg内容经过 urlencode 编码，进行解码
        $msg = json_decode(urldecode($msg), true);

        switch ($data['type']) {
            case 'trade_TradePaid':
                $this->tradePaid($msg);
                break;
            case 'trade_TradeCreate':
                $this->tradeCreate($msg);
                break;
            case 'trade_TradeClose':
                $this->tradeClose($msg);
                break;
            case 'trade_TradeSuccess':
                $this->tradeSuccess($msg);
                break;
            case 'trade_TradePartlySellerShip':
                $this->tradePartlySellerShip($msg);
                break;
            case 'trade_TradeSellerShip':
                $this->tradeSellerShip($msg);
                break;
            case 'trade_TradeBuyerPay':
                $this->tradeBuyerPay($msg);
                break;
            case 'trade_TradeMemoModified':
                $this->tradeMemoModified($msg);
                break;
            default:
                Log::info('【有赞云】回调无法识别，可能是没有启用[交易消息V3]接口，请到有赞云控制台启用消息推送服务');
                exit();
        }

        exit();
    }

    // 交易支付
    private function tradePaid($msg)
    {
        Log::info('【有赞云】回调交易支付');

        $payment = Payment::query()->with(['order', 'order.goods'])->where('qr_id', $msg['qr_info']['qr_id'])->first();
        if (!$payment) {
            Log::info('【有赞云】回调订单不存在');
            exit();
        }

        if ($payment->status != '0') {
            Log::info('【有赞云】回调订单状态不正确');
            exit();
        }
        $payment->pay_way = $msg['full_order_info']['order_info']['pay_type_str'] == 'WEIXIN_DAIXIAO' ? 1 : 2; // 1-微信、2-支付宝

        try {
            $paymentComponent = new PaymentComponent($payment);
            $paymentComponent->paid();
        } catch(\Exception $e) {
            Log::error('【有赞云】回调更新支付单和订单异常：' . $e->getMessage());
        }

        exit();
    }

    // 创建交易
    private function tradeCreate($msg)
    {
        Log::info('【有赞云】回调创建交易');
        exit();
    }

    // 关闭交易（无视，系统自带15分钟自动关闭未支付订单的定时任务）
    private function tradeClose($msg)
    {
        Log::info('【有赞云】回调关闭交易');

        exit();
    }

    // 交易成功
    private function tradeSuccess($msg)
    {
        Log::info('【有赞云】回调交易成功');

        exit();
    }

    // 卖家部分发货
    private function tradePartlySellerShip($msg)
    {
        Log::info('【有赞云】回调卖家部分发货');
        exit();
    }

    // 卖家发货
    private function tradeSellerShip($msg)
    {
        Log::info('【有赞云】回调卖家发货');
        exit();
    }

    // 买家付款
    private function tradeBuyerPay($msg)
    {
        Log::info('【有赞云】回调买家付款');
        exit();
    }

    // 卖家修改交易备注
    private function tradeMemoModified($msg)
    {
        Log::info('【有赞云】回调卖家修改交易备注');
        exit();
    }

    public function show(Request $request)
    {
        exit('show');
    }

    // 写入回调请求日志
    private function callbackLog($client_id, $yz_id, $kdt_id, $kdt_name, $mode, $msg, $sendCount, $sign, $status, $test, $type, $version)
    {
        $obj = new PaymentCallback();
        $obj->client_id = $client_id;
        $obj->yz_id = $yz_id;
        $obj->kdt_id = $kdt_id;
        $obj->kdt_name = $kdt_name;
        $obj->mode = $mode;
        $obj->msg = urldecode($msg);
        $obj->sendCount = $sendCount;
        $obj->sign = $sign;
        $obj->status = $status;
        $obj->test = $test;
        $obj->type = $type;
        $obj->version = $version;
        $obj->save();

        return $obj->id;
    }
}
