<?php

namespace App\Components;

use Stripe\Stripe;
use Stripe\Charge;

class StripeComponent
{
    public function __construct()
    {
        $privateKey = config('stripe.secret_key');
        if (empty($privateKey)) {
            throw new \Exception('Stripe secret key must be set');
        }

        Stripe::setApiKey($privateKey);
    }

    public function charge($charge) {
        return Charge::create($charge);
    }
}
