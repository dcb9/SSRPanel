<?php

namespace App\Http\Controllers;

use App\Components\Helpers;
use App\Components\StripeComponent;
use App\Components\PaymentComponent;
use App\Http\Models\Payment;
use App\Http\Models\Goods;
use App\Http\Models\Order;
use Illuminate\Http\Request;
use Response;
use Log;
use DB;

class StripeController extends Controller
{
    function __construct()
    {
    }

    public function charge(Request $request) {
        $token = $request->input('token');
        $amount = $request->input('amount');
        $sn = $request->input('sn');
        $currency = 'CNY';

        Log::notice("stripe charge with token", $token);

        $payment = Payment::query()->with(['order', 'order.goods'])->where('sn', $sn)->first();
        if (empty($payment)) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '支付失败: 订单不存在']);
        }
        if ($payment->status != '0') {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '支付失败: 订单状态不正确']);
        }

        $stripe = new StripeComponent();

        $chargeObj = [
            'amount' => $amount,
            'currency' => $currency,
            'source' => $token['id'],
            // 'description' => '',
        ];
        Log::notice("stripe charge object", $chargeObj);
        $succeeded = false;
        try {
            $chargeRes = $stripe->charge($chargeObj);
            $succeeded = $chargeRes['status'] ==='succeeded' ? true : false;
        } catch (\Exception $e) {
            // do nothing
        }
        Log::notice("stripe charge response data", (array)$chargeRes);
        if (!$succeeded) {
            return Response::json([
                'status' => 'fail',
                'data' => '',
                'message' => '支付失败'
            ]);
        }

        try {
            $paymentComponent = new PaymentComponent($payment);
            $paymentComponent->paid();

            return Response::json([
                'status' => $chargeRes['status'] ==='succeeded' ? 'success' : 'fail',
                'data' => '',
                'message' => '支付成功'
            ]);
        } catch (\Exception $e) {
            Log::error('更新支付单和订单异常：' . $e->getMessage());

            return Response::json([
                'status' => 'fail',
                'data' => '',
                'message' => '支付成功，操作数据库失败，请联系管理员'
            ]);
        }
    }
}
