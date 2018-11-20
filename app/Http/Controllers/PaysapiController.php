<?php

namespace App\Http\Controllers;

use App\Components\Helpers;
use App\Components\PaysapiComponent;
use App\Components\StripeComponent;
use App\Components\PaymentComponent;
use App\Http\Models\Payment;
use App\Http\Models\Goods;
use App\Http\Models\Order;
use Illuminate\Http\Request;
use Response;
use Log;
use DB;

class PaysapiController extends Controller
{
    public function notify(Request $request) {
        Log::info('paysapi', (array)$request);

        $paysapiComponent = new PaysapiComponent();
        if ($paysapiComponent->verifyNotify($request)) {
            Log::error('Invalid paysapi notify api call');
        }

        $realPrice = $request->input("realprice");
        $orderId = $request->input("orderid");

        $payment = Payment::query()->with(['order'])->where('order_sn', $orderId)->first();
        if ($payment->status != 0) {
            exit; // the order is paid
        }

        // the unit of the realPrice is Yuan
        // that of the amount is cent
        if ($realPrice * 100 < $payment->amount) {
            // @todo we have to handle this case, user paid is less than the amount of the order
            exit;
        }

        $paymentComponent = new PaymentComponent($payment);
        $paymentComponent->paid();
    }
}
