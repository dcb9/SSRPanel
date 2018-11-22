@extends('user.layouts')

@section('css')
    <link href="/assets/pages/css/invoice-2.min.css" rel="stylesheet" type="text/css" />
@endsection
@section('title', trans('home.panel'))
@section('content')
    <!-- BEGIN CONTENT BODY -->
    <div class="page-content" style="padding-top:0;">
        <!-- BEGIN PAGE BASE CONTENT -->
        <div class="invoice-content-2 bordered">
            <div class="row invoice-body">
                <div class="col-xs-12 table-responsive">
                    <table class="table table-hover">
                        @if($goods->type == 3)
                            <thead>
                                <tr>
                                    <th class="invoice-title"> {{trans('home.service_name')}} </th>
                                    <th class="invoice-title text-center"> {{trans('home.service_price')}} </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td style="padding: 10px;">
                                        <h2>{{$goods->name}}</h2>
                                        充值金额：{{$goods->price}}元
                                        </td>
                                    <td class="text-center"> ￥{{$goods->price}} </td>
                                </tr>
                            </tbody>
                        @else
                            <thead>
                                <tr>
                                    <th class="invoice-title"> {{trans('home.service_name')}} </th>
                                    <th class="invoice-title text-center"> {{trans('home.service_price')}} </th>
                                    <th class="invoice-title text-center"> {{trans('home.service_quantity')}} </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td style="padding: 10px;">
                                        <h2>{{$goods->name}}</h2>
                                    </td>
                                    <td class="text-center"> ￥{{$goods->price}} </td>
                                    <td class="text-center"> x 1 </td>
                                </tr>
                            </tbody>
                      	@endif
                    </table>
                </div>
            </div>
            @if($goods->type <= 2)
                <div class="row invoice-subtotal">
                    <div class="col-xs-3">
                        <h2 class="invoice-title"> {{trans('home.service_subtotal_price')}} </h2>
                        <p class="invoice-desc"> ￥{{$goods->price}} </p>
                    </div>
                    <div class="col-xs-3">
                        <h2 class="invoice-title"> {{trans('home.service_total_price')}} </h2>
                        <p class="invoice-desc grand-total"> ￥{{$goods->price}} </p>
                    </div>
                </div>
            @endif
            <div class="row" style="display: none;" id="paymentBtns">
                <div class="col-xs-12" style="text-align: right;">

                    <button class="btn blue hidden-print" id="alipayBtn">支付宝付款</button>
                    <button class="btn blue hidden-print" id="wechatPaymentBtn">微信支付</button>
                    <button class="btn blue hidden-print" id="stripePaymentBtn">Stripe Pay</button>
                    @if($is_youzan)
                        <a class="btn blue hidden-print" onclick="onlinePay()"> {{trans('home.online_pay')}} </a>
                    @endif
                  	@if($goods->type <= 2)
                        <a class="btn blue hidden-print uppercase" onclick="pay()"> {{trans('home.service_pay_button')}} </a>
                  	@endif
                </div>
            </div>
        </div>
        <!-- END PAGE BASE CONTENT -->
    </div>
    <!-- END CONTENT BODY -->
@endsection
@section('script')
    <script src="https://checkout.stripe.com/checkout.js"></script>
    <script src="/js/layer/layer.js" type="text/javascript"></script>

    <script type="text/javascript">
        $(document).ready(function(){
            document.getElementById('alipayBtn').addEventListener('click', function(e) {
                paysapiPay('paysapi-alipay');
                e.preventDefault();
            })

            document.getElementById('wechatPaymentBtn').addEventListener('click', function(e) {
                paysapiPay('paysapi-wechat');
                e.preventDefault();
            })

            document.getElementById('stripePaymentBtn').addEventListener('click', function(e) {
                e.preventDefault();

                stripePay(function (data) {
                    stripePaymentAmount = data.amount
                    sn = data.sn
                    // Open Checkout with further options:
                    handler.open({
                        name: 'Our Awesome SSR',
                        description: '{{$goods->name}}',
                        amount: data.amount,
                        currency: 'CNY',
                        email: data.email,
                    });
                })
            });
            document.getElementById('paymentBtns').style.display = null;
        })

        var stripePaymentAmount=0;
        var sn='';
        // stripe payment
        var handler = StripeCheckout.configure({
            key: '{{config('stripe.public_key')}}',
            image: 'https://stripe.com/img/documentation/checkout/marketplace.png',
            token: function(token) {
                console.log(token)
                // You can access the token ID with `token.id`.
                // Get the token ID to your server-side code for use.
                $.ajax({
                    type: "POST",
                    url: "{{url('stripe/charge')}}",
                    async: false,
                    data: {_token:'{{csrf_token()}}', token, amount: stripePaymentAmount, sn},
                    dataType: 'json',
                    beforeSend: function () {
                        index = layer.load(1, {
                            shade: [0.7,'#CCC']
                        });
                    },
                    success: function (ret) {
                        window.location.href = '{{url('invoices')}}';
                    }
                });
            }
        });

        // Close Checkout on page navigation:
        window.addEventListener('popstate', function() {
            handler.close();
        });
    </script>

    <script type="text/javascript">
        function nonAjaxSubmit(action, method, values) {
            const form = $('<form/>', {
                action: action,
                method: method
            })

            for (const name in values) {
                form.append($('<input/>', {
                    type: 'hidden',
                    name: name,
                    value: values[name],
                }))
            }

            form.appendTo('body').submit()
        }

        // 校验优惠券是否可用
        function redeemCoupon() {
            var coupon_sn = $('#coupon_sn').val();
            var goods_price = '{{$goods->price}}';

            $.ajax({
                type: "POST",
                url: "{{url('redeemCoupon')}}",
                async: false,
                data: {_token:'{{csrf_token()}}', coupon_sn:coupon_sn},
                dataType: 'json',
                beforeSend: function () {
                    index = layer.load(1, {
                        shade: [0.7,'#CCC']
                    });
                },
                success: function (ret) {
                    console.log(ret);
                    layer.close(index);
                    $("#coupon_sn").parent().removeClass("has-error");
                    $("#coupon_sn").parent().removeClass("has-success");
                    $(".input-group-addon").remove();
                    if (ret.status == 'success') {
                        $("#coupon_sn").parent().addClass('has-success');
                        $("#coupon_sn").parent().prepend('<span class="input-group-addon"><i class="fa fa-check fa-fw"></i></span>');

                        // 根据类型计算折扣后的总金额
                        var total_price = 0;
                        if (ret.data.type == '2') {
                            total_price = goods_price * ret.data.discount / 10;
                        } else {
                            total_price = goods_price - ret.data.amount;
                            total_price = total_price > 0 ? total_price : 0;
                        }

                        $(".grand-total").text("￥" + total_price);
                    } else {
                        $(".grand-total").text("￥" + goods_price);
                        $("#coupon_sn").parent().addClass('has-error');
                        $("#coupon_sn").parent().remove('.input-group-addon');
                        $("#coupon_sn").parent().prepend('<span class="input-group-addon"><i class="fa fa-remove fa-fw"></i></span>');
                    }
                }
            });
        }

        function createPayment(payment_type, successFunc) {
            var goods_id = '{{$goods->id}}';
            var coupon_sn = $('#coupon_sn').val();

            index = layer.load(1, {
                shade: [0.7,'#CCC']
            });

            $.ajax({
                type: "POST",
                url: "{{url('payment/create')}}",
                async: false,
                data: {_token:'{{csrf_token()}}', goods_id:goods_id, coupon_sn:coupon_sn, payment_type},
                dataType: 'json',
                beforeSend: function () {
                    index = layer.load(1, {
                        shade: [0.7,'#CCC']
                    });
                },
                success: function(ret) {
                    if (ret.status == 'success') {
                        return successFunc(ret.data)
                    }
                    layer.msg(ret.message, {time:2500}, function() {
                        console.log(ret);
                        layer.close(index);
                    });
                },
                error: function (xhr, textStatus, error) {
                    layer.msg("请求失败: " + textStatus, {time:2500}, function() {
                        layer.close(index);
                    });
                },
            });
        }

        function paysapiPay(payment_type) {
            createPayment(payment_type, function (data) {
                nonAjaxSubmit('https://pay.paysapi.com', 'post', data)
            })
        }

        // Stripe payment
        function stripePay(successFunc) {
            createPayment('stripe', function (data) {
                return successFunc(data)
            })
        }

        // 在线支付
        function onlinePay() {
            createPayment('youzan', function (data) {
                window.location.href = '{{url('payment')}}' + "/" + data;
            })
        }

        // 余额支付
        function pay() {
            var goods_id = '{{$goods->id}}';
            var coupon_sn = $('#coupon_sn').val();

            index = layer.load(1, {
                shade: [0.7,'#CCC']
            });

            $.ajax({
                type: "POST",
                url: "/buy/" + goods_id,
                async: false,
                data: {_token:'{{csrf_token()}}', coupon_sn:coupon_sn},
                dataType: 'json',
                beforeSend: function () {
                    index = layer.load(1, {
                        shade: [0.7,'#CCC']
                    });
                },
                success: function (ret) {
                    layer.msg(ret.message, {time:1300}, function() {
                        if (ret.status == 'success') {
                            window.location.href = '{{url('invoices')}}';
                        } else {
                            layer.close(index);
                        }
                    });
                }
            });
        }
    </script>
@endsection
