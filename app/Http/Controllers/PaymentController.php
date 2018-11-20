<?php

namespace App\Http\Controllers;

use App\Components\Helpers;
use App\Components\Yzy;
use App\Components\PaysapiComponent;
use App\Http\Models\Coupon;
use App\Http\Models\Goods;
use App\Http\Models\Order;
use App\Http\Models\Payment;
use App\Http\Models\PaymentCallback;
use Illuminate\Http\Request;
use Response;
use Redirect;
use Log;
use DB;
use Auth;

/**
 * 支付控制器
 *
 * Class PaymentController
 *
 * @package App\Http\Controllers
 */
class PaymentController extends Controller
{
    protected static $systemConfig;

    function __construct()
    {
        self::$systemConfig = Helpers::systemConfig();
    }

    // 创建支付单
    public function create(Request $request)
    {
        $goods_id = intval($request->get('goods_id'));
        $coupon_sn = $request->get('coupon_sn');

        $payment_type = $request->get('payment_type');
        // set youzan as a default payment type
        $payment_type = $payment_type ? $payment_type : 'youzan';

        $goods = Goods::query()->where('is_del', 0)->where('status', 1)->where('id', $goods_id)->first();

        $self = $this;
        $createPaymentFunc = function() use($self) {
            return call_user_func_array([$self, "createYzyPayment"], func_get_args());
        };
        $successReturnValueFunc = function ($payment) {
            return $payment->sn;
        };

        switch($payment_type) {
            case 'youzan':
                // 判断系统是否开启有赞云支付
                if (!self::$systemConfig['is_youzan']) {
                    return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：系统并未开启在线支付功能']);
                }
                break;
            case 'stripe':
                $createPaymentFunc = function($ignore, $amount) use($self) {
                    // Amount must be at least 50 cents
                    if ($amount / 7 < 0.5) {
                        throw new \Exception('Stripe restriction: amount must be at least 50 cents');
                    }

                    return call_user_func_array([$self, "createStripePayment"], func_get_args());
                };
                $successReturnValueFunc = function ($payment) {
                    return [
                        'amount' => intval($payment->amount * 100),
                        'email' => Auth::user()->username,
                        'sn' => $payment->sn,
                    ];
                };
                break;
            case 'paysapi-alipay':
                $istype = '1';
                $payWay = 5;
            case 'paysapi-wechat':
                if (!isset($istype)) {
                    $istype = '2';
                    $payWay = 6;
                }

                $createPaymentFunc = function() use ($self, $payWay) {
                    $args = func_get_args();
                    array_push($args, $payWay);
                    return call_user_func_array([$self, "createPaysapiPayment"], $args);
                };
                $successReturnValueFunc = function ($payment) use ($goods, $istype) {
                    $paysapiComponent = new PaysapiComponent();
                    return $paysapiComponent->createPayment($payment, $goods->name, $istype);
                };
                break;
            default:
                return Response::json(['status' => 'fail', 'data' => '', 'message' => 'Invalid payment type']);
        }

        $coupon = null;
        // 使用优惠券
        if ($coupon_sn) {
            $coupon = Coupon::query()->where('status', 0)->where('is_del', 0)->whereIn('type', [1, 2])->where('sn', $coupon_sn)->first();
            if (!$coupon) {
                return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：优惠券不存在']);
            }
        }

        $res = $this->validatePayment($goods, $coupon);
        if ($res !== true) {
            return $res;
        }

        $amount = $this->getPaymentAmount($goods, $coupon);

        DB::beginTransaction();
        try {
            $orderSn = date('ymdHis') . mt_rand(100000, 999999);
            $sn = makeRandStr(12);

            // 生成订单
            $order = new Order();
            $order->order_sn = $orderSn;
            $order->user_id = Auth::user()->id;
            $order->goods_id = $goods_id;
            $order->coupon_id = !empty($coupon) ? $coupon->id : 0;
            $order->origin_amount = $goods->price;
            $order->amount = $amount;
            $order->expire_at = date("Y-m-d H:i:s", strtotime("+" . $goods->days . " days"));
            $order->is_expire = 0;
            $order->pay_way = 2;
            $order->status = 0;
            $order->save();

            $payment = $createPaymentFunc($goods, $amount, $sn, $order, $orderSn);

            // 优惠券置为已使用
            if (!empty($coupon)) {
                if ($coupon->usage == 1) {
                    $coupon->status = 1;
                    $coupon->save();
                }

                Helpers::addCouponLog($coupon->id, $goods_id, $order->oid, $payment->getPayWayLabelAttribute() + '支付使用');
            }

            $data = $successReturnValueFunc($payment);

            DB::commit();

            return Response::json([
                'status' => 'success',
                'data' => $data,
                'message' => '创建订单成功，正在转到付款页面，请稍后'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('创建支付订单失败：' . $e->getMessage());

            return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建订单失败：' . $e->getMessage()]);
        }
    }

    protected function validatePayment($goods, $coupon) {
        if (!$goods) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：商品或服务已下架']);
        }

        // 判断是否存在同个商品的未支付订单
        $existsOrder = Order::query()->where('status', 0)->where('user_id', Auth::user()->id)->where('goods_id', $goods->id)->exists();
        if ($existsOrder) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：尚有未支付的订单，请先去支付']);
        }

        // 限购控制
        $strategy = self::$systemConfig['goods_purchase_limit_strategy'];
        if ($strategy == 'all' || ($strategy == 'package' && $goods->type == 2) || ($strategy == 'free' && $goods->price == 0) || ($strategy == 'package&free' && ($goods->type == 2 || $goods->price == 0))) {
            $noneExpireOrderExist = Order::query()->where('status', '>=', 0)->where('is_expire', 0)->where('user_id', Auth::user()->id)->where('goods_id', $goods->id)->exists();
            if ($noneExpireOrderExist) {
                return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：商品不可重复购买']);
            }
        }

        // 单个商品限购
        if ($goods->is_limit == 1) {
            $noneExpireOrderExist = Order::query()->where('status', '>=', 0)->where('user_id', Auth::user()->id)->where('goods_id', $goods->id)->exists();
            if ($noneExpireOrderExist) {
                return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：此商品每人限购1次']);
            }
        }

        $amount = $this->getPaymentAmount($goods, $coupon);

        // 价格异常判断
        if ($amount == 0) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：订单总价为0，无需使用在线支付']);
        }

        // 验证账号是否存在有效期更长的套餐
        if ($goods->type == 2) {
            $existOrderList = Order::query()
                ->with(['goods'])
                ->whereHas('goods', function ($q) {
                    $q->where('type', 2);
                })
                ->where('user_id', Auth::user()->id)
                ->where('is_expire', 0)
                ->where('status', 2)
                ->get();

            foreach ($existOrderList as $vo) {
                if ($vo->goods->days > $goods->days) {
                    return Response::json(['status' => 'fail', 'data' => '', 'message' => '支付失败：您已存在有效期更长的套餐，只能购买流量包']);
                }
            }
        }

        return true;
    }

    protected function getPaymentAmount($goods, $coupon) {
        if (!$coupon) {
            return $goods->price;
        }

        // 计算实际应支付总价
        $amount = $coupon->type == 2 ? $goods->price * $coupon->discount / 10 : $goods->price - $coupon->amount;
        return  $amount > 0 ? $amount : 0;
    }

    protected function createPaysapiPayment($goods, $amount, $sn, $order, $orderSn, $payWay) {
        $payment = new Payment();
        $payment->sn = $sn;
        $payment->user_id = Auth::user()->id;
        $payment->oid = $order->oid;
        $payment->order_sn = $orderSn;
        $payment->pay_way = $payWay; // 5 = paysapi alipay  6 = paysapi wechat
        $payment->amount = $amount;
        $payment->status = 0;
        $payment->save();

        return $payment;
    }

    protected function createStripePayment($goods, $amount, $sn, $order, $orderSn) {
        $payment = new Payment();
        $payment->sn = $sn;
        $payment->user_id = Auth::user()->id;
        $payment->oid = $order->oid;
        $payment->order_sn = $orderSn;
        $payment->pay_way = 3; // Stripe
        $payment->amount = $amount;
        $payment->status = 0;
        $payment->save();

        return $payment;
    }

    protected function createYzyPayment($goods, $amount, $sn, $order, $orderSn) {
        // 生成支付单
        $yzy = new Yzy();
        $result = $yzy->createQrCode($goods->name, $amount * 100, $orderSn);
        if (isset($result['error_response'])) {
            Log::error('【有赞云】创建二维码失败：' . $result['error_response']['msg']);

            throw new \Exception($result['error_response']['msg']);
        }

        $payment = new Payment();
        $payment->sn = $sn;
        $payment->user_id = Auth::user()->id;
        $payment->oid = $order->oid;
        $payment->order_sn = $orderSn;
        $payment->pay_way = 1;
        $payment->amount = $amount;
        $payment->qr_id = $result['response']['qr_id'];
        $payment->qr_url = $result['response']['qr_url'];
        $payment->qr_code = $result['response']['qr_code'];
        $payment->qr_local_url = $this->base64ImageSaver($result['response']['qr_code']);
        $payment->status = 0;
        $payment->save();

        return $payment;
    }

    // 支付单详情
    public function detail(Request $request, $sn)
    {
        if (empty($sn)) {
            return Redirect::to('services');
        }

        $payment = Payment::query()->with(['order', 'order.goods'])->where('sn', $sn)->where('user_id', Auth::user()->id)->first();
        if (!$payment) {
            return Redirect::to('services');
        }

        $order = Order::query()->where('oid', $payment->oid)->first();
        if (!$order) {
            \Session::flash('errorMsg', '订单不存在');

            return Response::view('payment/' . $sn);
        }

        $view['payment'] = $payment;
        $view['website_logo'] = self::$systemConfig['website_logo'];
        $view['website_analytics'] = self::$systemConfig['website_analytics'];
        $view['website_customer_service'] = self::$systemConfig['website_customer_service'];

        return Response::view('payment.detail', $view);
    }

    // 获取订单支付状态
    public function getStatus(Request $request)
    {
        $sn = $request->get('sn');

        if (empty($sn)) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '请求失败']);
        }

        $payment = Payment::query()->where('sn', $sn)->where('user_id', Auth::user()->id)->first();
        if (!$payment) {
            return Response::json(['status' => 'error', 'data' => '', 'message' => '支付失败']);
        } elseif ($payment->status > 0) {
            return Response::json(['status' => 'success', 'data' => '', 'message' => '支付成功']);
        } elseif ($payment->status < 0) {
            return Response::json(['status' => 'error', 'data' => '', 'message' => '订单超时未支付，已自动关闭']);
        } else {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '等待支付']);
        }
    }

    // 有赞云回调日志
    public function callbackList(Request $request)
    {
        $status = $request->get('status', 0);

        $query = PaymentCallback::query();

        if ($status) {
            $query->where('status', $status);
        }

        $view['list'] = $query->orderBy('id', 'desc')->paginate(10);

        return Response::view('payment.callbackList', $view);
    }
}
