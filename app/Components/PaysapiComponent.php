<?php

namespace App\Components;

use App\Components\Helpers;
use Illuminate\Http\Request;

class PaysapiComponent {
    protected static $returnURL;
    protected static $notifyURL;
    protected static $uid;
    protected static $token;

    public function __construct()
    {
        $systemConfig = Helpers::systemConfig();
        self::$returnURL = $systemConfig['website_url'] . '/payment';
        self::$notifyURL = $systemConfig['website_url'] . '/paysapi/notify';

        self::$uid = config('paysapi.uid');
        self::$token = config('paysapi.token');
        if (empty(self::$uid) || empty(self::$token)) {
            throw new \Exception('You must config Paysapi');
        }
    }

    public function verifyNotify(Request $request) {
        $paysapiId = $request->input("paysapi_id");
        $orderId = $request->input("orderid");
        $price = $request->input("price");
        $realPrice = $request->input("realprice");
        $orderUid = $request->input("orderuid");
        $key = $request->input("key");

        $temps = md5($orderId . $orderUid . $paysapiId . $price . $realPrice . self::$token);

        return $key === $temps;
    }

    public function createPayment($payment, $goodsname, $istype) {
        $notifyURL = self::$notifyURL;
        $returnURL = self::$returnURL . '/' . $payment->sn;
        $token = self::$token;
        $uid = self::$uid;

        $orderid = $payment->order_sn;
        $orderuid = $payment->user_id;
        $price = $payment->amount;


        $key = md5($goodsname. $istype . $notifyURL . $orderid
                . $orderuid . $price . $returnURL . $token . $uid);

        return [
            'goodsname' => $goodsname,
            'istype' => $istype,
            'key' => $key,
            'notify_url' => $notifyURL,
            'return_url' => $returnURL,
            'orderid' => $orderid,
            'orderuid' => $orderuid,
            'price' => $price,
            'uid' => $uid,
        ];
    }
}
