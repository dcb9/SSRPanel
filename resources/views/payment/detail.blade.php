@extends('user.layouts')

@section('css')

@endsection
@section('title', trans('home.panel'))
@section('content')
    <!-- BEGIN CONTENT BODY -->
    <div class="page-content" style="padding-top:0;">
        <!-- BEGIN PAGE BASE CONTENT -->
        <div class="portlet light bordered">
            <div class="portlet-body">
                @if($payment->pay_way == 5 || $payment->pay_way == 6)
                    <div class="alert alert-info" style="text-align: center;">
                        订单支付状态
                    </div>
                @else
                    <div class="alert alert-info" style="text-align: center;">
                        请使用<strong style="color:red;">支付宝、QQ、微信</strong>扫描如下二维码
                    </div>
                @endif
                <div class="row" style="text-align: center; font-size: 1.05em;">
                    <div class="col-md-12">
                        <div class="table-scrollable">
                            <table class="table table-hover table-light">
                                <tr>
                                    <td align="right" width="50%">服务名称：</td>
                                    <td align="left" width="50%">{{$payment->order->goods->name}}</td>
                                </tr>
                                <tr>
                                    <td align="right">应付金额：</td>
                                    <td align="left">{{$payment->amount}} 元</td>
                                </tr>
                                <tr>
                                    <td align="right">有效期：</td>
                                    <td align="left">{{$payment->order->goods->days}} 天</td>
                                </tr>
                                @if($payment->pay_way == 5 || $payment->pay_way == 6)
                                    <tr>
                                        <td colspan="2">正在获取支付结果，请稍后……</td>
                                    </tr>
                                @else
                                    <tr>
                                        <td colspan="2">
                                            扫描下方二维码进行付款（可截图再扫描）
                                            <br>
                                            请于15分钟内支付，到期未支付订单将自动关闭
                                            <br>
                                            支付后，请稍作等待，账号状态会自动更新
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" align="center">
                                            <img src="{{$payment->qr_local_url}}"/>
                                        </td>
                                    </tr>
                                @endif
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- END PAGE BASE CONTENT -->
    </div>
    <!-- END CONTENT BODY -->
@endsection
@section('script')
    <script src="/js/layer/layer.js" type="text/javascript"></script>
    <script type="text/javascript">
        // 每800毫秒查询一次订单状态
        $(document).ready(function(){
            setTimeout(getStatus, 800);
        });

        const sn = '{{$payment->sn}}';

        // 检查支付单状态
        function getStatus () {

            $.get("{{url('payment/getStatus')}}", {sn}, function (ret) {
                console.log(ret);
                if (ret.status == 'fail') {
                    // waiting for user's payment
                    setTimeout(getStatus, 800);
                    return;
                }

                if (ret.status == 'success') {
                    layer.msg(ret.message, {time:800}, function() {
                        window.location.href = '{{url('invoices')}}';
                    });
                } else if(ret.status == 'error') {
                    layer.msg(ret.message, {time:1500}, function () {
                        window.location.href = '{{url('invoices')}}';
                    })
                }
            });
        }
    </script>
@endsection
