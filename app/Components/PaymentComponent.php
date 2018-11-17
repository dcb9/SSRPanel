<?php

namespace App\Components;

use App\Components\Helpers;
use App\Http\Models\Order;
use App\Http\Models\Goods;
use App\Http\Models\GoodsLabel;
use App\Http\Models\User;
use App\Http\Models\UserLabel;
use App\Http\Models\SsNode;
use App\Http\Models\SsNodeLabel;
use App\Mail\sendUserInfo;
use Hash;
use DB;
use Mail;

class PaymentComponent
{
    protected static $systemConfig;
    protected $payment;

    public function __construct($payment)
    {
        self::$systemConfig = Helpers::systemConfig();
        $this->payment = $payment;
    }

    public function paid() {
        // 处理订单
        $payment = $this->payment;
        DB::beginTransaction();
        try {
            $this->createUserIfNotExist($payment);

            // 更新支付单
            $payment->status = 1;
            $payment->save();

            // 更新订单
            $order = Order::query()->with(['user'])->where('oid', $payment->oid)->first();
            $order->status = 2;
            $order->save();

            $goods = Goods::query()->where('id', $order->goods_id)->first();

            // 商品为流量或者套餐
            if ($goods->type <= 2) {
                // 如果买的是套餐，则先将之前购买的所有套餐置都无效，并扣掉之前所有套餐的流量，重置用户已用流量为0
                if ($goods->type == 2) {
                    $existOrderList = Order::query()
                        ->with(['goods'])
                        ->whereHas('goods', function ($q) {
                            $q->where('type', 2);
                        })
                        ->where('user_id', $order->user_id)
                        ->where('oid', '<>', $order->oid)
                        ->where('is_expire', 0)
                        ->where('status', 2)
                        ->get();

                    foreach ($existOrderList as $vo) {
                        Order::query()->where('oid', $vo->oid)->update(['is_expire' => 1]);

                        // 先判断，防止手动扣减过流量的用户流量被扣成负数
                        if ($order->user->transfer_enable - $vo->goods->traffic * 1048576 <= 0) {
                            User::query()->where('id', $order->user_id)->update(['u' => 0, 'd' => 0, 'transfer_enable' => 0]);
                        } else {
                            User::query()->where('id', $order->user_id)->update(['u' => 0, 'd' => 0]);
                            User::query()->where('id', $order->user_id)->decrement('transfer_enable', $vo->goods->traffic * 1048576);
                        }
                    }
                }

                // 把商品的流量加到账号上
                User::query()->where('id', $order->user_id)->increment('transfer_enable', $goods->traffic * 1048576);

                // 计算账号过期时间
                if ($order->user->expire_time < date('Y-m-d', strtotime("+" . $goods->days . " days"))) {
                    $expireTime = date('Y-m-d', strtotime("+" . $goods->days . " days"));
                } else {
                    $expireTime = $order->user->expire_time;
                }

                // 套餐就改流量重置日，流量包不改
                if ($goods->type == 2) {
                    if (date('m') == 2 && date('d') == 29) {
                        $traffic_reset_day = 28;
                    } else {
                        $traffic_reset_day = date('d') == 31 ? 30 : abs(date('d'));
                    }
                    User::query()->where('id', $order->user_id)->update(['traffic_reset_day' => $traffic_reset_day, 'expire_time' => $expireTime, 'enable' => 1]);
                } else {
                    User::query()->where('id', $order->user_id)->update(['expire_time' => $expireTime, 'enable' => 1]);
                }

                // 写入用户标签
                if ($goods->label) {
                    // 用户默认标签
                    $defaultLabels = [];
                    if (self::$systemConfig['initial_labels_for_user']) {
                        $defaultLabels = explode(',', self::$systemConfig['initial_labels_for_user']);
                    }

                    // 取出现有的标签
                    $userLabels = UserLabel::query()->where('user_id', $order->user_id)->pluck('label_id')->toArray();
                    $goodsLabels = GoodsLabel::query()->where('goods_id', $order->goods_id)->pluck('label_id')->toArray();

                    // 标签去重
                    $newUserLabels = array_values(array_unique(array_merge($userLabels, $goodsLabels, $defaultLabels)));

                    // 删除用户所有标签
                    UserLabel::query()->where('user_id', $order->user_id)->delete();

                    // 生成标签
                    foreach ($newUserLabels as $vo) {
                        $obj = new UserLabel();
                        $obj->user_id = $order->user_id;
                        $obj->label_id = $vo;
                        $obj->save();
                    }
                }

                // 写入返利日志
                if ($order->user->referral_uid) {
                    $this->addReferralLog($order->user_id, $order->user->referral_uid, $order->oid, $order->amount, $order->amount * self::$systemConfig['referral_percent']);
                }

                // 取消重复返利
                User::query()->where('id', $order->user_id)->update(['referral_uid' => 0]);
            } elseif ($goods->type == 3) { // 商品为在线充值
                User::query()->where('id', $order->user_id)->increment('balance', $goods->price * 100);

                // 余额变动记录日志
                $this->addUserBalanceLog($order->user_id, $order->oid, $order->user->balance, $order->user->balance + $goods->price, $goods->price, '用户在线充值');
            }

            // 自动提号机：如果order的email值不为空
            if ($order->email) {
                $title = '自动发送账号信息';
                $content = [
                    'order_sn'      => $order->order_sn,
                    'goods_name'    => $order->goods->name,
                    'goods_traffic' => flowAutoShow($order->goods->traffic * 1048576),
                    'port'          => $order->user->port,
                    'passwd'        => $order->user->passwd,
                    'method'        => $order->user->method,
                    //'protocol'       => $order->user->protocol,
                    //'protocol_param' => $order->user->protocol_param,
                    //'obfs'           => $order->user->obfs,
                    //'obfs_param'     => $order->user->obfs_param,
                    'created_at'    => $order->created_at->toDateTimeString(),
                    'expire_at'     => $order->expire_at
                ];

                // 获取可用节点列表
                $labels = UserLabel::query()->where('user_id', $order->user_id)->get()->pluck('label_id');
                $nodeIds = SsNodeLabel::query()->whereIn('label_id', $labels)->get()->pluck('node_id');
                $nodeList = SsNode::query()->whereIn('id', $nodeIds)->orderBy('sort', 'desc')->orderBy('id', 'desc')->get()->toArray();
                $content['serverList'] = $nodeList;

                try {
                    Mail::to($order->email)->send(new sendUserInfo($content));
                    Helpers::addEmailLog($order->email, $title, json_encode($content));
                } catch (\Exception $e) {
                    Helpers::addEmailLog($order->email, $title, json_encode($content), 0, $e->getMessage());
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            throw $e;
        }
    }

    // 如果支付单中没有用户信息则创建一个用户
    public function createUserIfNotExist($payment) {
        if (!$payment->user_id) {
            // 生成一个可用端口
            $port = self::$systemConfig['is_rand_port'] ? Helpers::getRandPort() : Helpers::getOnlyPort();

            $user = new User();
            $user->username = '自动生成-' . $payment->order->email;
            $user->password = Hash::make(makeRandStr());
            $user->port = $port;
            $user->passwd = makeRandStr();
            $user->vmess_id = createGuid();
            $user->enable = 1;
            $user->method = Helpers::getDefaultMethod();
            $user->protocol = Helpers::getDefaultProtocol();
            $user->obfs = Helpers::getDefaultObfs();
            $user->usage = 1;
            $user->transfer_enable = 1; // 新创建的账号给1，防止定时任务执行时发现u + d >= transfer_enable被判为流量超限而封禁
            $user->enable_time = date('Y-m-d');
            $user->expire_time = date('Y-m-d', strtotime("+" . $payment->order->goods->days . " days"));
            $user->reg_ip = getClientIp();
            $user->referral_uid = 0;
            $user->traffic_reset_day = 0;
            $user->status = 1;
            $user->save();

            if ($user->id) {
                Order::query()->where('oid', $payment->oid)->update(['user_id' => $user->id]);
            }
        }
    }
}
