<?php
require '../../../../wp-load.php';

function common($url, $params)
{

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response  = curl_exec($ch);

    $error = curl_errno($ch);

    curl_close($ch);

    $output = $error ? false : json_decode($response);

    return $output;
}

if (extension_loaded('curl')) {

    $merchant = get_option('ihc_zibal_key');
    $is_direct = get_option('ihc_zibal_direct');
    $currency = get_option('ihc_currency');
    $levels = get_option('ihc_levels');
    $r_url = get_option('ihc_zibal_return_page');

    if (!$r_url || $r_url == -1) {
        $r_url = get_option('page_on_front');
    }
    $r_url = get_permalink($r_url);
    if (!$r_url) {
        $r_url = get_home_url();
    }

    $err = false;

    if (isset($levels[$_GET['lid']])) {
        $level_arr = $levels[$_GET['lid']];
        if ($level_arr['payment_type'] == 'free' || $level_arr['price'] == '') $err = true;
    } else {
        $err = true;
    }
    if (isset($_GET['uid']) && $_GET['uid']) {
        $uid = $_GET['uid'];
    } else {
        $uid = get_current_user_id();
    }
    if (!$uid) {
        $err = true;
    }

    if ($err) {
        header('location:' . $r_url);
        exit();
    }

    $callback = str_replace('public/', 'zibal_ipn.php?lid=' . $_GET['lid'] . '&uid=' . $uid, plugin_dir_url(__FILE__));

    $reccurrence = FALSE;
    if (isset($level_arr['access_type']) && $level_arr['access_type'] == 'regular_period') {
        $reccurrence = TRUE;
    }

    $coupon_data = array();
    if (!empty($_GET['ihc_coupon'])) {
        $coupon_data = ihc_check_coupon($_GET['ihc_coupon'], $_GET['lid']);
    }

    if ($coupon_data) {
        $level_arr['price'] = ihc_coupon_return_price_after_decrease($level_arr['price'], $coupon_data);
    }

    if ($currency != 'IRR') {
        $level_arr['price'] = $level_arr['price'] * 10;
    }

    $orderId = md5(uniqid(rand(), true));
    $params = array(
        'merchant' => $merchant,
        'amount' => $level_arr['price'],
        'callbackUrl' => urlencode($callback),
        'mobile' => null,
        'description' => $level_arr['description'],
    );
    $result = common('https://gateway.zibal.ir/request', $params);

    if ($result && isset($result->result) && $result->result == 100) {
        $gateway_url = 'https://gateway.zibal.ir/start/' . $result->trackId. ($is_direct?'/direct':'');
        header('location:' . $gateway_url);
        exit;
    } else {
        $message = 'در ارتباط با وب سرویس زیبال خطایی رخ داده است';
        $message = isset($result->errorMessage) ? $result->message : $message;
        echo $message;
        wp_die($message);
        exit;
    }
} else {
    $message = 'تابع cURL در سرور فعال نمی باشد';
    wp_die($message);
    exit;
}
	
	
	