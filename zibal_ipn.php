<?php
require_once '../../../wp-load.php';
require_once 'utilities.php';
// insert this request into debug payments table
if (get_option('ihc_debug_payments_db')) {
    ihc_insert_debug_payment_log('zibal', $_POST);
}
function common($url, $params)
{
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $error = curl_errno($ch);

    curl_close($ch);

    $output = $error ? false : json_decode($response);

    return $output;
}

global $wpdb;
$data = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "indeed_members_payments WHERE `u_id`='" . $_GET['uid'] . "';");
$is_duplicate_tid = 0;

foreach ($data as $k => $v) {
    $data_payment = json_decode($v->payment_data);
    if (isset($data_payment->trackId)) {
        if ($data_payment->trackId == $_POST['trackId']) {
            $is_duplicate_tid = 1;
            $message = 'شماره تراکنش تکراری است.';
            wp_die($message);
            exit;
        } else {
            $is_duplicate_tid = 0;
        }
    }
}
if (isset($_POST['trackId']) && $is_duplicate_tid == 0) {

    if (ihc_get_level_by_id($_GET['lid'])) {
        $level_data = ihc_get_level_by_id($_GET['lid']);
        if ($level_data['payment_type'] == 'free' || $level_data['price'] == '') header('location:' . get_home_url());
    } else {
        header('location:' . get_home_url());
        exit();
    }

    $r_url = get_option('ihc_zibal_return_page');

    if (!$r_url || $r_url == -1) {
        $r_url = get_option('page_on_front');
    }

    $r_url = get_permalink($r_url);
    if (!$r_url) {
        $r_url = get_home_url();
    }

    $merchant = get_option('ihc_zibal_key');
    $currency = get_option('ihc_currency');

    $trackId = $_POST['trackId'];
    $orderId = $_POST['orderId'];

    $amount = $level_data['price'];


    if ($currency != 'IRR') {
        $amount = $amount * 10;
    }

    $debug = FALSE;
    $path = str_replace('zibal_ipn.php', '', __FILE__);
    $log_file = $path . 'zibal.log';

    $params = array(

        'merchant' => $merchant,
        'trackId' => $trackId
    );

    $result = common('https://gateway.zibal.ir/verify', $params);

    if ($result && isset($result->result) && $result->result == 100) {

        $_POST['ihc_payment_type'] = 'zibal';
        $_POST['amount'] = $amount;
        $_POST['currency'] = $currency;
        $_POST['level'] = $_GET['lid'];
        $_POST['description'] = $level_data['description'];

        if ($amount == $result->amount) {

            ihc_update_user_level_expire($level_data, $_GET['lid'], $_GET['uid']);
            ihc_send_user_notifications($_GET['uid'], 'payment', $_GET['lid']);
            ihc_switch_role_for_user($_GET['uid']);
            $_POST['payment_status'] = 'Completed';
            ihc_insert_update_transaction($_GET['uid'], $trackId, $_POST);
            header('location:' . $r_url);

        } else {
            $_POST['payment_status'] = 'Failed';
            ihc_insert_update_transaction($_GET['uid'], $trackId, $_POST);
            $message = 'رقم تراكنش با رقم پرداخت شده مطابقت ندارد';
            wp_die($message);
            exit;
        }
    } else {
        $_POST['payment_status'] = 'Failed';
        $message = 'در ارتباط با وب سرویس زیبال و بررسی تراکنش خطایی رخ داده است';
        $message = isset($result->message) ? $result->message : $message;
        wp_die($message);
        exit;
    }
} else {
    //non zibal tries to access this file
    header('Status: 404 Not Found');
    exit();
}
