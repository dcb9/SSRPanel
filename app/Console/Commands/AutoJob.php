<?php

namespace App\Console\Commands;

use App\Components\Helpers;
use App\Components\Yzy;
use App\Components\PaymentComponent;
use App\Http\Models\ReferralLog;
use App\Http\Models\UserBalanceLog;
use App\Http\Models\VerifyCode;
use Illuminate\Console\Command;
use App\Http\Models\Coupon;
use App\Http\Models\Invite;
use App\Http\Models\Order;
use App\Http\Models\Payment;
use App\Http\Models\User;
use App\Http\Models\UserLabel;
use App\Http\Models\UserBanLog;
use App\Http\Models\UserSubscribe;
use App\Http\Models\UserSubscribeLog;
use App\Http\Models\UserTrafficHourly;
use Log;
use DB;
use Mail;

class AutoJob extends Command
{
    protected $signature = 'autoJob';
    protected $description = '自动化任务';
    protected static $systemConfig;

    public function __construct()
    {
        parent::__construct();
        self::$systemConfig = Helpers::systemConfig();
    }

    /*
     * 警告：除非熟悉业务流程，否则不推荐更改以下执行顺序，随意变更以下顺序可能导致系统异常
     */
    public function handle()
    {
        $jobStartTime = microtime(true);

        // 注册验证码自动置无效
        $this->expireVerifyCode();

        // 优惠券到期自动置无效
        $this->expireCoupon();

        // 邀请码到期自动置无效
        $this->expireInvite();

        // 封禁访问异常的订阅链接
        $this->blockSubscribe();

        // 封禁账号
        $this->blockUsers();

        // 移除过期的账号的标签和流量
        $this->removeUserLabels();

        // 解封被封禁的账号
        $this->unblockUsers();

        // 端口回收与分配
        $this->dispatchPort();

        // 审计待支付的订单
        $this->detectOrders();

        // 关闭超时未支付订单
        $this->closeOrders();

        $jobEndTime = microtime(true);
        $jobUsedTime = round(($jobEndTime - $jobStartTime), 4);

        Log::info('执行定时任务【' . $this->description . '】，耗时' . $jobUsedTime . '秒');
    }

    // 注册验证码自动置无效
    private function expireVerifyCode()
    {
        VerifyCode::query()->where('status', 0)->where('created_at', '<=', date('Y-m-d H:i:s', strtotime("-10 minutes")))->update(['status' => 2]);
    }

    // 优惠券到期自动置无效
    private function expireCoupon()
    {
        $couponList = Coupon::query()->where('status', 0)->where('available_end', '<=', time())->get();
        if (!$couponList->isEmpty()) {
            foreach ($couponList as $coupon) {
                Coupon::query()->where('id', $coupon->id)->update(['status' => 2]);
            }
        }
    }

    // 邀请码到期自动置无效
    private function expireInvite()
    {
        $inviteList = Invite::query()->where('status', 0)->where('dateline', '<=', date('Y-m-d H:i:s'))->get();
        if (!$inviteList->isEmpty()) {
            foreach ($inviteList as $invite) {
                Invite::query()->where('id', $invite->id)->update(['status' => 2]);
            }
        }
    }

    // 封禁访问异常的订阅链接
    private function blockSubscribe()
    {
        if (self::$systemConfig['is_subscribe_ban']) {
            $subscribeList = UserSubscribe::query()->where('status', 1)->get();
            if (!$subscribeList->isEmpty()) {
                foreach ($subscribeList as $subscribe) {
                    // 24小时内不同IP的请求次数
                    $request_times = UserSubscribeLog::query()->where('sid', $subscribe->id)->where('request_time', '>=', date("Y-m-d H:i:s", strtotime("-24 hours")))->distinct('request_ip')->count('request_ip');
                    if ($request_times >= self::$systemConfig['subscribe_ban_times']) {
                        UserSubscribe::query()->where('id', $subscribe->id)->update(['status' => 0, 'ban_time' => time(), 'ban_desc' => '存在异常，自动封禁']);

                        // 记录封禁日志
                        $this->addUserBanLog($subscribe->user_id, 0, '【完全封禁订阅】-订阅24小时内请求异常');
                    }
                }
            }
        }
    }

    // 封禁账号
    private function blockUsers()
    {
        // 过期用户处理
        $userList = User::query()->where('status', '>=', 0)->where('enable', 1)->where('expire_time', '<', date('Y-m-d'))->get();
        if (!$userList->isEmpty()) {
            foreach ($userList as $user) {
                if (self::$systemConfig['is_ban_status']) {
                    User::query()->where('id', $user->id)->update([
                        'u'                 => 0,
                        'd'                 => 0,
                        'transfer_enable'   => 0,
                        'enable'            => 0,
                        'traffic_reset_day' => 0,
                        'ban_time'          => 0,
                        'status'            => -1
                    ]);

                    $this->addUserBanLog($user->id, 0, '【禁止登录，清空账户】-账号已过期');

                    // 如果注册就有初始流量，则废除其名下邀请码
                    if (self::$systemConfig['default_traffic']) {
                        Invite::query()->where('uid', $user->id)->where('status', 0)->update(['status' => 2]);
                    }
                } else {
                    User::query()->where('id', $user->id)->update([
                        'u'                 => 0,
                        'd'                 => 0,
                        'transfer_enable'   => 0,
                        'enable'            => 0,
                        'traffic_reset_day' => 0,
                        'ban_time'          => 0
                    ]);

                    $this->addUserBanLog($user->id, 0, '【封禁代理，清空账户】-账号已过期');
                }
            }
        }

        // 封禁1小时内流量异常账号
        if (self::$systemConfig['is_traffic_ban']) {
            $userList = User::query()->where('status', '>=', 0)->where('enable', 1)->where('ban_time', 0)->get();
            if (!$userList->isEmpty()) {
                foreach ($userList as $user) {
                    // 多往前取5分钟，防止数据统计任务执行时间过长导致没有数据
                    $totalTraffic = UserTrafficHourly::query()->where('user_id', $user->id)->where('node_id', 0)->where('created_at', '>=', date('Y-m-d H:i:s', time() - 3900))->sum('total');
                    if ($totalTraffic >= (self::$systemConfig['traffic_ban_value'] * 1024 * 1024 * 1024)) {
                        User::query()->where('id', $user->id)->update(['enable' => 0, 'ban_time' => strtotime(date('Y-m-d H:i:s', strtotime("+" . self::$systemConfig['traffic_ban_time'] . " minutes")))]);

                        // 写入日志
                        $this->addUserBanLog($user->id, self::$systemConfig['traffic_ban_time'], '【临时封禁代理】-1小时内流量异常');
                    }
                }
            }
        }

        // 禁用流量超限用户
        $userList = User::query()->where('status', '>=', 0)->where('enable', 1)->where('ban_time', 0)->whereRaw("u + d >= transfer_enable")->get();
        if (!$userList->isEmpty()) {
            foreach ($userList as $user) {
                User::query()->where('id', $user->id)->update(['enable' => 0]);

                // 写入日志
                $this->addUserBanLog($user->id, 0, '【封禁代理】-流量已用完');
            }
        }
    }

    // 移除过期的账号的标签和流量（临时封禁不移除）
    private function removeUserLabels()
    {
        $userList = User::query()->where('enable', 0)->where('ban_time', 0)->where('expire_time', '<', date('Y-m-d'))->get();
        if (!$userList->isEmpty()) {
            foreach ($userList as $user) {
                UserLabel::query()->where('user_id', $user->id)->delete();
                User::query()->where('id', $user->id)->update([
                    'u'                 => 0,
                    'd'                 => 0,
                    'transfer_enable'   => 0,
                    'traffic_reset_day' => 0
                ]);
            }
        }
    }

    // 解封被临时封禁的账号
    private function unblockUsers()
    {
        // 解封被临时封禁的账号
        $userList = User::query()->where('status', '>=', 0)->where('enable', 0)->where('ban_time', '>', 0)->get();
        foreach ($userList as $user) {
            if ($user->ban_time < time()) {
                User::query()->where('id', $user->id)->update(['enable' => 1, 'ban_time' => 0]);

                // 写入操作日志
                $this->addUserBanLog($user->id, 0, '【自动解封】-临时封禁到期');
            }
        }

        // 可用流量大于已用流量也解封（比如：邀请返利自动加了流量）
        $userList = User::query()->where('status', '>=', 0)->where('enable', 0)->where('ban_time', 0)->where('expire_time', '>=', date('Y-m-d'))->whereRaw("u + d < transfer_enable")->get();
        if (!$userList->isEmpty()) {
            foreach ($userList as $user) {
                User::query()->where('id', $user->id)->update(['enable' => 1]);

                // 写入操作日志
                $this->addUserBanLog($user->id, 0, '【自动解封】-有流量解封');
            }
        }
    }

    // 端口回收与分配
    private function dispatchPort()
    {
        if (self::$systemConfig['auto_release_port']) {
            ## 自动分配端口
            $userList = User::query()->where('status', '>=', 0)->where('enable', 1)->where('port', 0)->get();
            if (!$userList->isEmpty()) {
                foreach ($userList as $user) {
                    $port = self::$systemConfig['is_rand_port'] ? Helpers::getRandPort() : Helpers::getOnlyPort();

                    User::query()->where('id', $user->id)->update(['port' => $port]);
                }
            }

            ## 被封禁的账号自动释放端口
            $userList = User::query()->where('status', -1)->where('enable', 0)->get();
            if (!$userList->isEmpty()) {
                foreach ($userList as $user) {
                    if ($user->port) {
                        User::query()->where('id', $user->id)->update(['port' => 0]);
                    }
                }
            }

            ## 过期一个月的账户自动释放端口
            $userList = User::query()->where('enable', 0)->get();
            if (!$userList->isEmpty()) {
                foreach ($userList as $user) {
                    if ($user->port) {
                        $overdueDays = floor((strtotime(date('Y-m-d H:i:s')) - strtotime($user->expire_time)) / 86400);
                        if ($overdueDays > 30) {
                            User::query()->where('id', $user->id)->update(['port' => 0]);
                        }
                    }
                }
            }
        }
    }

    // 审计待支付的订单
    private function detectOrders()
    {
        /*
         * 因为订单在15分钟未支付则会被自动关闭
         * 当有赞没有正常推送消息或者其他原因导致用户已付款但是订单不生效从而导致用户无法正常加流量、置状态
         * 故需要每分钟请求一次未支付订单，审计一下其支付状态
         */
        $paymentList = Payment::query()->with(['order', 'user'])->where('status', 0)->where('pay_way', 1)->get();
        if (!$paymentList->isEmpty()) {
            foreach ($paymentList as $payment) {
                // 跳过order丢失的订单
                if (!isset($payment->order)) {
                    continue;
                }

                $yzy = new yzy();
                $trade = $yzy->getTradeByQrId($payment->qr_id);
                if ($trade['response']['total_results']) {
                    // 再判断一遍当前要操作的订单的状态是否被改变了（可能请求延迟的时候已经回调处理完了）
                    $payment = Payment::query()->where('id', $payment->id)->first();
                    if ($payment->status != '0') {
                        continue;
                    }

                    try {
                        $paymentComponent = new PaymentComponent($payment);
                        $paymentComponent->paid();
                    } catch (\Exception $e) {
                        Log::info('【有赞云】审计订单时更新支付单和订单异常：' . $e);
                    }
                }
            }
        }
    }

    // 关闭超时未支付订单
    private function closeOrders()
    {
        // 关闭超时未支付的有赞云订单（有赞云收款二维码超过30分钟自动关闭，关闭后无法再支付，所以我们限制15分钟内必须付款）
        $paymentList = Payment::query()->with(['order', 'order.coupon'])->where('status', 0)->where('created_at', '<=', date("Y-m-d H:i:s", strtotime("-15 minutes")))->get();
        if (!$paymentList->isEmpty()) {
            DB::beginTransaction();
            try {
                foreach ($paymentList as $payment) {
                    // 关闭支付单
                    Payment::query()->where('id', $payment->id)->update(['status' => -1]);

                    // 关闭订单
                    Order::query()->where('oid', $payment->oid)->update(['status' => -1]);

                    // 退回优惠券
                    if (!empty($payment->order) && $payment->order->coupon_id) {
                        Coupon::query()->where('id', $payment->order->coupon_id)->update(['status' => 0]);

                        Helpers::addCouponLog($payment->order->coupon_id, $payment->order->goods_id, $payment->oid, '订单超时未支付，自动退回');
                    }
                }

                DB::commit();
            } catch (\Exception $e) {
                Log::info('【异常】自动关闭超时未支付订单：' . $e);

                DB::rollBack();
            }
        }
    }

    /**
     * 添加用户封禁日志
     *
     * @param int    $userId  用户ID
     * @param int    $minutes 封禁时长，单位分钟
     * @param string $desc    封禁理由
     */
    private function addUserBanLog($userId, $minutes, $desc)
    {
        $log = new UserBanLog();
        $log->user_id = $userId;
        $log->minutes = $minutes;
        $log->desc = $desc;
        $log->save();
    }

    /**
     * 添加返利日志
     *
     * @param int $userId    用户ID
     * @param int $refUserId 返利用户ID
     * @param int $oid       订单ID
     * @param int $amount    发生金额
     * @param int $refAmount 返利金额
     *
     * @return int
     */
    public function addReferralLog($userId, $refUserId, $oid, $amount, $refAmount)
    {
        $log = new ReferralLog();
        $log->user_id = $userId;
        $log->ref_user_id = $refUserId;
        $log->order_id = $oid;
        $log->amount = $amount;
        $log->ref_amount = $refAmount;
        $log->status = 0;

        return $log->save();
    }

    /**
     * 记录余额操作日志
     *
     * @param int    $userId 用户ID
     * @param string $oid    订单ID
     * @param int    $before 记录前余额
     * @param int    $after  记录后余额
     * @param int    $amount 发生金额
     * @param string $desc   描述
     *
     * @return int
     */
    public function addUserBalanceLog($userId, $oid, $before, $after, $amount, $desc = '')
    {
        $log = new UserBalanceLog();
        $log->user_id = $userId;
        $log->order_id = $oid;
        $log->before = $before;
        $log->after = $after;
        $log->amount = $amount;
        $log->desc = $desc;
        $log->created_at = date('Y-m-d H:i:s');

        return $log->save();
    }
}
