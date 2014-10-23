<?php

/* Wordpress Routes
 * Endpoint for receiving REST calls to VXL interfaces.
 * URL calls can be made to these routes to get JSON data for all user related calls
 * To test the api for operation use get route vxlpay.appspot.com/test
 */

/* wp/store 
 * /* wpvxl/store
 * 
 * This route is called from a wordpress enabled site from the wp plugin, attached
 * to the post_update events on the wordpress sites. It sends the contents of the article
 * to vxlpay, where it is stored and attached to a contentObj for this user.
 *
 */

$app->post('/wp/store', function() use ($app) {
    global $conn, $account, $partner;
    $time_start = microtime(true);
    $partnerId = $app->request->params('partnerId');
    $wpId = $app->request->params('wpId');
    //The secret has been salted, but the salt is provided. 
    $secret = $app->request->params('secret');
    $nonce = $app->request->params('nonce');




    VXLgate::log('wpvxl/store', 'Received a post call from wordpress site', json_encode($_GET));

    if (!$partnerId) {
        $response['status'] = 'Request did not supply partnerId (required)';
        VXLgate::error('wpvxl/store', $response['status'], $partnerId);
        print json_encode($response); return;
          
      
    }

    $partner = new Partner($partnerId);
    if (!$partner) {
        $response['status'] = "PartnerID ( $partnerId )  is not valid";
        VXLgate::error('wpvxl/store', $response['status'], $partnerId);
        print json_encode($response); return;
          
      
    }

    if (!$secret) {
        $response['status'] = 'Store request did not supply hashed secret code';
        VXLgate::error('wpvxl/store', $response['status'], $secret);
        print json_encode($response); return;
          
      
    }

    if ($secret != sha1($partner->getSecret() . $nonce)) {
        $response['status'] = 'Error in secret code, are you are who you say you are?';
        VXLgate::error('wpvxl/store', $response['status'], $secret);
        print json_encode($response); return;
          
      
    }

    $wp_post_obj = new StdClass();
    $wp_post_obj->wpId = $wpId;
    $wp_post_obj->partnerId = $partnerId;
    $wp_post_obj->post_name = $app->request->params('post_name');
    $wp_post_obj->post_content = $app->request->params('post_content');
    $wp_post_obj->post_title = $app->request->params('post_title');
    $wp_post_obj->post_status = $app->request->params('post_status');
    $wp_post_obj->post_type = $app->request->params('post_type');
    $wp_post_obj->permaLink = $app->request->params('permaLink');

    $wpPost = new WpObj($partnerId, $wpId);

    $wpPost->populate(
            $wp_post_obj->post_name, $wp_post_obj->post_content, $wp_post_obj->post_title, $wp_post_obj->post_status, $wp_post_obj->post_type, $wp_post_obj->permaLink
    );


    if (!$wpPost->save('store')) {
        $response['status'] = 'Unable to persist wpPost on vxlpay.';
        VXLgate::error('wpvxl/store', $response['status']);
        print json_encode($response); return;
          
      
    }

    $response['wp_obj'] = $wpPost->get();



    $response['status'] = 'success';

    print json_encode($response); return;
      
  
});

/* wp/test Wordpress test route for checking that post calls to vxlpay
 * works and communication is possible. This interface is used to test
 * the health of the connection used to synchronize content update calls, 
 * which enable vxlpay to serve the content to the database.
 */


$app->post('/wp/voucher', function() use ($app) {
    global $conn, $account, $partner;
    $time_start = microtime(true);
    $wpId = $app->request->params('wpId');
    $partnerId = $app->request->params('partnerId');
    $secret = $app->request->params('secret');
    $nonce = $app->request->params('nonce');
    $voucherCode = $app->request->params('voucherCode');
    $email = $app->request->params('email');
    $firstname = $app->request->params('firstname');
    $firstname = $app->request->params('lastname');

    VXLgate::log('wpvxl/voucher', 'Received a post call from wordpress site', json_encode($_GET));

    if (!$partnerId) {
        $response['status'] = 'Request Did not supply partnerId (required)';
        VXLgate::error('wpvxl/voucer', $response['status'], $partnerId);
        print json_encode($response); return;
          
      
    }

    $partner = new Partner($partnerId);
    if (!$partner) {
        $response['status'] = "PartnerID ( $partnerId )  is not valid";
        VXLgate::error('wpvxl/voucher', $response['status'], $partnerId);
        print json_encode($response); return;
          
      
    }

    if (!$secret) {
        $response['status'] = 'Store request did not supply hashed secret code';
        VXLgate::error('wpvxl/voucher', $response['status'], $secret);
        print json_encode($response); return;
          
      
    }

    if ($secret != sha1($partner->getSecret() . $nonce)) {
        $response['status'] = 'Error in secret code, are you are who you say you are?';
        VXLgate::error('wpvxl/voucher', $response['status'], $secret);
        print json_encode($response); return;
          
      
    }

    if(!$email){
        $response['status'] = 'You need to specify an email adress';
        VXLgate::error('wpvxl/voucher', $response['status'], $secret);
        print json_encode($response); return;
          
      
        
    }
    // Look up the account id using the provided email adddress.
    $accountId = VXLactions::getAccountByEmail($email);
    if(!$accountId){
        $acc_token = VXLgate::createAnonymousAccount();
        $account = new Account($acc_token['accountId']);
        $account->setEmail($email);
        if($firstname){
            $account->firstname = $firstname;
        }
        if($lastName){
            $account->lastname = $lastname;
        }
        $account->save();
    }
    else{
        $account = new Account($accountId);
    }
    
    if(!$account->id){
       
        $response['status'] = 'An error occurred looking up your account';
        print json_encode($response); return;
  
  
    }
    
    // Redeem the voucher code.
 
    $redeemed = VXLactions::redeemVoucher($voucherCode);
    if(!$redeemed){
        $response['status'] = 'That voucher code is an invalid. Try another';
    }
    else{
        $response['status'] = "Congratulations! You have redeemed ".$redeemed. ' credits using the vouchercode '.$voucherCode;
    }

    print json_encode($response); return;
  
  
    
});

$app->post('/wp/test', function() use ($app) {
    global $conn, $account, $partner;
    $time_start = microtime(true);
    $wpId = $app->request->params('wpId');
    $partnerId = $app->request->params('partnerId');
    $secret = $app->request->params('secret');
    $nonce = $app->request->params('nonce');

    VXLgate::log('wp/test', 'Received a post call from wordpress site', json_encode($_GET));

    if (!$partnerId) {
        $response['error'] = 'Could not authenticate with WideScribe.com';
        $response['action'] = 'Please provide partnerId by adjusting the paywall settings';
        $response['status'] = 'Request did not supply partnerId (required). ';
        VXLgate::error('wp/test', $response['status'], $partnerId);
        print json_encode($response); return;
        return;
    }

    $partner = new Partner($partnerId);
    if (!$partner) {
        $response['error'] = 'Could not authenticate with WideScribe.com';
        $response['action'] = ' Please review partnerId by adjusting the paywall settings';
        $response['status'] = "PartnerId ( $partnerId )  is not valid";
        VXLgate::error('wpvxl/test', $response['status'], $partnerId);
        print json_encode($response); return;
        return;
      
    }

    if (!$secret) {
         $response['error'] = 'Could not authenticate with WideScribe.com';
        $response['action'] = 'Please check that your have added your secret code.';
        $response['status'] = 'Store request did not supply hashed secret code';
        VXLgate::error('wp/test', $response['status'], $secret);
        print json_encode($response); return;
        return;  
      
    }

    if ($secret != sha1($partner->getSecret() . $nonce)) {
        $response['error'] = 'Could not authenticate with WideScribe.com';
        $response['action'] = 'Please check that your have provided the correct  secret code.';
        $response['status'] = 'Error in secret code, are you are who you say you are?';
        VXLgate::error('wp/test', $response['status'], $secret);
        print json_encode($response); return;
        return;
    }

    //$wpPost = new WpObj($partnerId, $wpId);

    /*
    if (!$wpPost) {
        $response['status'] = 'Unable to create wpPost';
        VXLgate::error('wpvxl/test', $response['status']);
        print json_encode($response); return;
          
      
    }
    // This the template for the wpObj sent by the ;
    */
      $response['status'] = 'success';

    
      print json_encode($response); return;
    
    return;
   
});

?>