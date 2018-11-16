<?php

namespace App\Http\Controllers;

use App\Components\Helpers;
use App\Components\Stripe;
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

        $stripe = new Stripe();

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

        // update database
        DB::beginTransaction();
        try {
            $this->setPaid($payment);
            DB::commit();
            return Response::json([
                'status' => $chargeRes['status'] ==='succeeded' ? 'success' : 'fail',
                'data' => '',
                'message' => '支付成功'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return Response::json([
                'status' => 'fail',
                'data' => '',
                'message' => '支付成功，操作数据库失败，请联系管理员'
            ]);
        }
    }

    protected function setPaid($payment) {
        // 更新支付单
        $payment->status = 1;
        $payment->save();

        // 更新订单
        $order = Order::query()->with(['user'])->where('oid', $payment->oid)->first();
        $order->status = 2;
        $order->save();

        // $goods = Goods::query()->where('id', $order->goods_id)->first();
        // @TODO 将商品与用户关联上
    }
}
