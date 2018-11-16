<?php

namespace App\Components;

use Stripe\Stripe as StripeClient;
use Stripe\Charge;

class Stripe
{
    public function __construct()
    {
        $privateKey = config('stripe.secret_key');
        if (empty($privateKey)) {
            throw new \Exception('Stripe secret key must be set');
        }

        StripeClient::setApiKey($privateKey);
    }

    public function charge($charge) {
        return Charge::create($charge);
    }
}
