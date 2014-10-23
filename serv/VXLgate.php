<?php

        const VXLpartnerId = 1;
        const VXLdefaultLanguageId = 1;
        const VXLdefaultCurrencyCode = 'NOK';
/*

 * VXLgate is an extension of the index.php route, attempting to hide
 * some functionality within static methods for brevity.
 * 
 * It contains the not/able function knock, which provides most of the
 * business logic from the knock/route call. 
 * 
 * Notable functions
 * knock() - Every time a user visists a page with the vxlpay script running, the knock call is fired.
 * Usually, it performs all the nescessay actions, whatever they might be.
 * 
 * grant() - Used by the knock function to determine if the user is allowed to see the content.
 * 
 * pay() - Used by the grant function to determine if the user pays for the content.
 * 
 * createAnonymousAccount - Creates a cookie and a token which can be used to identify the current
 * computer/device.
 * 
 * createView - Used by the knock call to store a view, which allows the knock 
 * function (throug using inViewHistory()) to know if the
 * user accesses the same article twice, and avoid charging a second time.
 * 
 * createContent - Used to create content when the content is not found in the
 * contentObj, subject to the newContentStrategy settings for the given partner.
 * 
 * 
 */

class VXLgate {

    static function getConn() {
        global $conn;
        global $connType;
        global $cloud;
        
        if ($cloud != $connType || !$conn) {
            switch ( $cloud ) {

                case "CLOUD":
                    // Used to conncet to the CLOUD SQL server from the context of the
                    // GAE - this is much faster than CLOUDREMOTE, but uses the same
                    // database.
                    //   $conn = new mysqli(null, DBUser, DBPass, '', null, DBCloud);
                    $conn = new mysqli(
                            null, // host
                            DBUser, // username
                            DBPass, // password
                            DBName, // database name
                            null, 
                            DBCloud
                    );



                    break;
                case "LOCAL":
                    // Used to use the local instance of the MySQL
                    $conn = new mysqli(DBServer, DBUser, DBPass, DBName);
                    break;
                case "CLOUDREMOTE":
                    // Used to access the GAE datastore, but run the API engine
                    // from a local setting. Only use when you need to test
                    // the API locally with actual production data.


                    $conn = new mysqli(DBipAdress, DBUser, DBPass, DBName);
                    break;
            }
        }

        if (!$conn) {
            //  VXLgate::error('getConn()', 'Error connecting to DB ('.$cloud.')', mysqli_connect_error());
            print "Connect error " . mysqli_connect_error();
            die(mysqli_connect_error());
        } else {

            //VXLgate::log('VXLgate::getConn)=(', 'Actually logged in', 'yay');
        }

        $conn->set_charset("utf8");
        return $conn;
    }

    /*
     *  KNOCK 
     * 
     *  This function is the gateway to this static class of methods that process
     *  views and authentication.
     *  It decides on a response to the client depending on 
     *  wether it can authorize, wether the content is premium and so forth
     */

    static function knock($token, $URL, $isFrontPage = false) {
        global $conn, $account, $partner;
        $response = array();
        $conn = VXLgate::getConn();

        if ($conn->connect_error) {
            VXLgate::error('knock', 'Database connection failed: ' . $conn->connect_error, '');
            die();
        }

        $account = null;
        $contentObj = VXLgate::getContent($URL);

        If (!$contentObj) {
            VXLGate::error('VXLGATE.KNOCK', 'GetContent did not provide contentObj for all cases', '');
            return false;
        }

        // Get account or create new anonymous
        if (isset($token)) { // Device has token
            $accountId = VXLgate::lookupToken($token);

            if (!$accountId) {
                // Create new anonymous account
                $acc_token = VXLgate::createAnonymousAccount($contentObj->partnerId);

                $response['message'] = 'Just created an anonymous account';
                $accountId = $acc_token['accountId'];
                VXLGate::log('knock', 'Token found, but no match found on the server', $token);
            }
        } else {  // Device does not have token
            if ($contentObj) {

                $acc_token = VXLgate::createAnonymousAccount($contentObj->partnerId);
            } else {

                $acc_token = VXLgate::createAnonymousAccount(VXLpartnerId);
            }

            $response['message'] = 'Just created an anonymous account ' . $acc_token['accountId'];
        }

        // Account should now exist, with the exception if the token is dangling.

        if (isset($acc_token)) {
            $accountId = $acc_token['accountId'];
        }

        // This checks if the user is on the front page
        // Once the anonymous account is created from a frontpage request,
        // get out and dont hassle the user.

        $account = new Account($accountId);
        if ($isFrontPage) {
            if (isset($acc_token)) {
                $respons['frontPage'] = true;
                $response['token'] = $acc_token['token'];
                return $response;
            }
            // If this is a returnign user, dont return except minmal user data.
            $response['user'] = array("balance" => 'W');
            $response['message'] = 'this is the front page';
            return $response;
        }
        //  VXLgate::log('KNOCK', 'ContentObj has partnerId '.$contentObj->partnerId);

        $partner = new Partner($contentObj->partnerId);
        $response['partner'] = $partner->get();

        // Attempt to create the account.

    

        if (!$account->id) {
            $acc_token = VXLgate::createAnonymousAccount($contentObj->partnerId);
            $account = new Account($acc_token['accountId']);
            if (!$account->id) {
                VXLgate::error('VXLgate::knock()', 'Fatal error. Occurs when the tokenmap is dangling, AND the recovery anonymouscreate failed as well!', 'Delete this token ' . $token);
            }
        }
        // At this stage we can assume that an account object exists.

        if ($account->stage == 'newanon') {
            if (isset($acc_token)) {
                $response['token'] = $acc_token['token'];
            }
            $response['user'] = array("balance" => 0);
            $response['trxn'] = array('cost' => $contentObj->cost);
            $response['costCurrencyEquiv'] = Pack::getCurrencyEquivalent($account->currencyCode, $contentObj->cost);
            $response['rewardCurrencyEquiv'] = Pack::getCurrencyEquivalent($account->currencyCode, $partner->emailCompensation);
            $fm = new FrameManager($account->languageId, 'newanon');
            $response['frame'] = (array) $fm->frame->get($response, $account->slideProgress());

            return $response;
        } else {
            // The user is known, and has returned, he is not anon.
            // Grant or deny access to the content.
            $response = array_merge($response, VXLgate::grant($contentObj));


            /* BALANCE Induced actions */
            if (in_array($account->stage, array('trans', 'paying'))) {
                if ($account->balance == 10) { // For testing purposes
                    if ($account->invoicePending()) {

                        $response['frame'] = array('action' => 'invoice', 'state' => 'reminder', 'spamFolderPic' => $account->getSpamFolderPic());

                        $response['spamFolderPic'] = $account->getSpamFolderPic();
                    } else {

                        $response['frame'] = array('action' => 'invoice', 'state' => 'option', 'spamFolderPic' => $account->getSpamFolderPic());
                    }

                    $fm = new FrameManager($account->languageId, 'balance');
                    $response['frame'] = (array) $fm->frame->get($response, 'runningLow');
                }
                if ($account->balance == 0) { // For testing purposes
                    if ($account->invoicePending()) {
                        $fm = new FrameManager($account->languageId, 'balance');
                        $response['frame'] = (array) $fm->frame->get($response, 'invoiceReminder');
                    } else {
                        $fm = new FrameManager($account->languageId, 'balance');
                        $response['frame'] = (array) $fm->frame->get($response, 'empty');
                    }
                }
            }
        }

        $response['user'] = $account->get();
        return $response;
    }

    /* GRANT()
     * Populates the txn object in the typical response. 
     * This is the function which determines how, and if, you get access
     * to the content. It will subtract VXLs from your account if nescessary.
     */

    static function grant($contentObj) {
        global $conn, $account, $partner;
        $conn = VXLgate::getConn();
        $response = array();
        $deliver = false;
        // No registered content, its free!
        if (!$contentObj) {
            $response['trxn']['text'] = 'Content is free';
        }
        // Content registrered and is free
        else if ($contentObj->free) {
            $response['trxn']['text'] = 'Content is free';
        } else {

            // Get time since last view OR 0 if first view
            $time_since = (VXLgate::inViewHistory($contentObj->URL));
            // Content is viewed before?
            if ($time_since) {
                // Log a new view
                if (VXLgate::createView($account->id, $contentObj)) {
                    $response['trxn']['view'] = $contentObj;
                    if ($time_since < 60000) {
                        // You paid for <i> 
                        $message = Translate::communiqueLookUp(14, $account->languageId) . $contentObj->getHeaderShort() . ' </i> ';
                        $deliver = true;
                        $response['trxn'] = array(
                            'text' => $message,
                            'time_since' => $time_since);
                    } else {
                        //   $message  = You have already paid for </i>
                        $message = Translate::communiqueLookUp(15, $account->languageId) . $contentObj->getHeaderShort() . ' </i>';
                        $deliver = true;
                        $response['trxn'] = array(
                            'text' => $message);
                    }
                }
                // Should never get to here
                else {
                    $deliver = true;
                    $response['trxn']['text'] = 'Not found in the list of users';
                    VXLgate::error('VXLGATE.GRANT', 'Unable to store document view in history. User gets a free ride. (Createview returned false)', '');

                    $response['trxn']['view'] = 'An error occured when we tried to log the view.';
                }
            }
            // First time access to content, pay up!
            else {
                $response = array_merge($response, VXLgate::pay($contentObj));
            }

            if ($deliver) {

                // Check if the delivery requires access to wpPost data.
                // In case it does, this delivery is contingent on the sucessful
                // retrieval of the wpObj.

                if ($partner->wordpressPage) {
                    $wpObj = new WpObj($partner->id, $contentObj->URL);
                    $wpObj->lookupByURL();
                    if (!$wpObj) {
                        Vxlgate::error('VXLGATE.GRANT', "Attempting to deliver wp-content for url:[$contentObj->URL], but the wpObj was not found. Grant operation ended, content was not delivered to user", $account->id);

                        $response['error'] = $message;
                        return $response;
                    }
                    //THe wordpress lookup was successful
                    $response['wpContent'] = $wpObj->post_content;
                }
            }
        }

        return $response;
    }

    /*
     *  CREATE ANONYMOUS ACCOUNT 
     * 
     *  This function creates an anonymous account. 
     *  It requires a lookup on the current partnerId,
     *  to ensure that new anonymous accounts
     *  are given the correct amount of anon compensation and expiration
     *  
     */

    static function createVXL($amount, $partnerId) {
        global $conn, $account, $partner;
        $conn = VXLgate::getConn();

        $sql = "INSERT INTO  VXL (accountId, domain, partnerId, virtual, lastEvent) values (0, 1, ?, 1, 'new')";

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            VXLgate::error('createVXL', 'Wrong SQL: ' . $sql . ' Error: ' . $conn->error, '');
        }

        $stmt->bind_param('i', $partnerId);

        for ($i = 0; $i < $amount; $i++) {
            $stmt->execute();
        }
        return true;
    }

    static function createAnonymousAccount($partnerId) {
        global $conn, $account, $partner;
        if (!$partnerId) {
            $partnerId = wideScribeID;
            VXLgate::error('createAnonymousAccount', 'Did not find this domain registered at VXL. Go to VXL.no and register as a partner to start using VXL:', $partnerId);
        }

        $sql = 'SELECT partnerName, anonCompensation, emailCompensation, languageId, defaultCurrencyCode FROM partner where id =  ?';
        $stmt = $conn->prepare($sql);

        if ($stmt === false) {
            VXLgate::error('createAnonymousAccount', 'Wrong SQL: ' . $sql . ' Error: ' . $conn->error, '');
        }

        $stmt->bind_param('i', $partnerId);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->errno > 0) {
            VXLgate::error('CreateAnonymousAccount', 'SQL error .' . $stmt->error, $stmt->errno);
        }
        $stmt->bind_result($partnerName, $anonCompensation, $emailCompenastion, $languageId, $defaultCurrencyCode);

        if ($stmt->num_rows > 0) {

            if ($stmt->fetch()) {
                
            } else {
                VXLgate::error('createAnonymousAccount', 'Fetch failed ' . $sql . ' Error: ' . $stmt->error, 'partnerId :' . $partnerId);
                $stmt->close();
                return false;
            }
        } else {

            $partnerId = VXLpartnerId;
        }

        // Create anonymous account, return accountId.
        $sql = "INSERT INTO account (balance, reals, virtuals, stage, currencyCode) values (0, 0, 0, 'newanon', ?)";
        $stmt2 = $conn->prepare($sql);
        $stmt2->bind_param('s', $defaultCurrencyCode);
        if ($stmt2 === false) {
            VXLgate::error('createAnonymousAccount', 'Wrong SQL: ' . $sql . ' Error: ' . $conn->error, 'partnerId :' . $partnerId);
            return false;
        }

        $stmt2->execute();
        $stmt2->store_result();
        if ($stmt2->errno > 0) {
            VXLgate::error('CreateAnonymousAccount', 'SQL error .' . $stmt2->error, $stmt2->errno);
        }

        if ($stmt2->insert_id) {

            $accountId = $stmt2->insert_id;

            $stmt2->close();
        } else {
            VXLgate::error('VXLGATE::createAnonymousAccount', 'Unable to insert new account', '');
            return false;
        }
        // Create token for this user
        $token = VXLgate::createTokenMap($accountId, $languageId);

        if (!$token) {
            VXLgate::error('createAnonymousAccount', 'Failed creating token for this new account, got :' . $token, $token);
            return false;
        }


        return Array(
            'accountId' => $accountId,
            'token' => $token,
            'emailCompensation' => $emailCompenastion,
            'anonCompensation' => $anonCompensation
        );
    }

    /*
     *  PAY 
     * 
     *  This function identifies a VXL, repoints it, executes the calculate balance
     *  inserts a view and returns
     *  details from the payment process.
     *  
     */

    static function pay($contentObj) {
        global $conn, $account, $partner;
        $response = array();
        // Defaults to zero cost if not succeeding.

        if ($contentObj->cost > 0) {

            if ($account->balance > 0) {
                VXLgate::log('pay', 'User balance before subtract :' . $account->balance, '');

                $virtualsUsed = $account->pay($contentObj->cost);

                VXLgate::log('pay', 'User balance after subtract :' . $account->balance, '');

                //$response['text'] = 'We just took a VXL ';
                $response['text'] = Translate::communiqueLookUp(10, $account->languageId);
                $response['cost'] = $contentObj->cost;
                $response['privacy'] = 'Les mere';
                //Communique
                //$response['message'] = Translate::communiqueLookup(1, $account->languageId);

                if (VXLgate::createView($account->id, $contentObj, true, $virtualsUsed)) {
                    $response['trxn']['view'] = $contentObj;
                } else {

                    $response['error'] = "Create view returned false";
                }

                return $response;
            } else {

                VXLgate::log('pay', 'User has an empty account', '');

                $fm = new FrameManager($account->languageId, 'balance');
                $response['frame'] = (array) $fm->frame->get($response, 'empty');

                return $response;
            }
        } else {
            // print 'Payment is 0, no need to perform payment';
            return true;
        }
    }

    static function inViewHistory($URL) {
        global $conn, $account, $partner;

        $sql = "SELECT id, (NOW() - timestamp) as time_since from view WHERE accountId = ? AND URL = ? AND pending not in ('recalled', 'unlogged') limit 1 ";
        // $sql = "SELECT * FROM  content ";
        if (!$conn) {

            VXLgate::error('inViewHistory', 'Connection failed', '');
            return false;
        }

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            VXLgate::error('inViewHistory', 'Wrong SQL: ' . $sql . ' Error: ' . $conn->error, '');
            return false;
        }

        $stmt->bind_param('is', $account->id, $URL);

        $stmt->execute();
        $stmt->bind_result($viewID, $time_since);

        if ($stmt->store_result()) {
            $stmt->fetch();
            if ($stmt->num_rows > 0) {
                return $time_since;
            } else {

                return false;
            }
        } else {
            VXLgate::error('inViewHistory', 'Store result failed for ' . $sql, $stmt->error);
            return false;
        }
    }

    static function getContent($URL) {
        global $conn, $account, $partner;
        // Find the first slash after the https:// slashes.
        //  $slashPos = findstr($URL, '/', 9);
        //  $domainPart = substr($URL, 0, $slashPos+9);
        //  $contentPart = substr($URL, $slashPos+9);
        // $sql = "SELECT partnerId, cost, header, provider FROM content WHERE URL = ? AND expiration > UNIX_TIMESTAMP()";
        $sql = "SELECT partnerId, cost, header, provider FROM content WHERE URL = ?  AND expiration > UNIX_TIMESTAMP()";

        if (!$conn) {

            VXLgate::error('getContent', 'Connection failed', '');
            return false;
        }
        $URL = urldecode($URL);
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            VXLgate::error('getContent', 'Wrong SQL: ' . $sql . ' Error: ' . $conn->error, '');
            return false;
        }
        $stmt->bind_param('s', $URL);

        $stmt->execute();
        $stmt->bind_result($partnerId, $cost, $header, $provider);
        if (!$stmt->store_result()) {
            VXLgate::error('getContent', 'Store result failed for ' . $sql, $stmt->error);
            return false;
        }

        if ($stmt->num_rows == 1) {
            $stmt->fetch();
            $stmt->close();
            return new ContentObj($URL, $partnerId, $cost, $header, $provider, false);
        } elseif ($stmt->num_rows == 0) {
            $stmt->close();

            // Need partner object to know what will happens
            if (!$partner) {
                $partner = new Partner(VXLgate::getPartnerIdFromURL($URL));
            }

            if (!$partner) {
                VXLgate::error('VXLgate.getContent', 'Unable to create content or deduce content from URL', VXLgate::getPartnerIdFromURL($URL));
            }

            // Determine the new content policy of the current partner. If it returns true, 
            // the new contenct policy for new URL is to automatically add it.

            switch ($partner->getNewContentPolicy()) {
                case 'create':

                    $header = basename($URL);
                    $header = urldecode($header);

                    $header = ucfirst(str_replace(array('.php', '.aspx', '_', '-'), array('', '', ' ', ' '), $header));

                    $contentObj = VXLgate::createContent($URL, $partner->id, $partner->defaultCost, $header, $partner->partnerName, true);

                    break;
                case 'free':

                    $contentObj = new ContentObj($URL, $partnerId, 0, $provider, '', true);
                    break;
                default:

                    $contentObj = new ContentObj($URL, $partnerId, 0, $provider, '', true);
                    break;
            }
            return $contentObj;
        } else {
            VXLgate::error('getContent', 'Got a result where num rows were > 1, actually ' . $stmt->num_rows . '. SQL: ' . $sql, $stmt->error);
            return false;
        }
    }

    /*
     *  CREATETOKEN 
     * 
     *  This function creates a unique token. Please mind that this token is created
     *  using a prefix. The prefix is set on each individual server.
     *  It is absolutely vital that a token that is created is entirely  unique, and
     *  that there is no collission. 


      }
     */

    static function createToken() {
        global $conn;
        $prefix = 'VXL001';
        $uniquid = uniqid($prefix, true);
        if (VXLgate::lookupToken($uniquid)) {
            return createToken();
        } else {

            return $uniquid;
        }
    }

    static function deleteToken($token) {
        global $conn;
        $sql = "UPDATE token SET deleted = 1 WHERE token = ?  LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            VXLgate::error('remapToken', 'Wrong SQL: ' . $sql . ' Error: ' . $conn->error, '');
        }
        $stmt->bind_param('s', $token);

        $stmt->execute();
        $stmt->store_result();
        if ($stmt->affected_rows == 1) {
            return true;
        } else {
            VXLgate::error('remapToken', 'Error in remapping token, ' . $stmt->affected_rows . ' affected. Received token (' . $token . ') vs accountId (' . $toAccountId . ')');
        }
    }

    static function remapToken($token, $toAccountId) {
        global $conn;
        $sql = "UPDATE token SET accountId = ? WHERE token = ?  LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            VXLgate::error('remapToken', 'Wrong SQL: ' . $sql . ' Error: ' . $conn->error, '');
        }
        $stmt->bind_param('is', $toAccountId, $token);

        $stmt->execute();
        $stmt->store_result();
        if ($stmt->affected_rows == 1) {
            return true;
        } else {
            VXLgate::error('remapToken', 'Error in remapping token, ' . $stmt->affected_rows . ' affected. Received token (' . $token . ') vs accountId (' . $toAccountId . ')');
        }
    }

    static function maskEmail($email) {
        preg_match('/^(.)(.*)(@)(.*)(\..*)$/', $email, $part);
        if ($part) {
            $masked = $part[1] . preg_replace('/./', '*', $part[2]) . $part[3] . preg_replace('/./', '*', $part[4]) . $part[5];
            return $masked;
        }
        return false;
    }

    static function signOutToken($token, $accountId) {
        global $conn;
        $sql = "UPDATE token SET signedIn = 0 WHERE  token = ? AND accountId = ? LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            VXLgate::error('remapToken', 'Wrong SQL: ' . $sql . ' Error: ' . $conn->error, '');
        }
        $stmt->bind_param('si', $token, $accountId);

        $stmt->execute();
        $stmt->store_result();
        if ($stmt->affected_rows == 1) {
            return true;
        } else {
            VXLgate::error('signOutToken', 'Error in remapping token, ' . $stmt->affected_row . ' affected');
        }
    }

    static function createTokenMap($accountId, $languageId) {
        global $conn;
        $token = VXLgate::createToken();

        $sql = "INSERT INTO token (token, accountId, languageId) values (?, ?, ?)";

        $stmt = $conn->prepare($sql);

        if ($stmt === FALSE) {
            VXLgate::error('createTokenMap', 'Wrong SQL: ' . $sql . ' Error: ' . $conn->error, '');
        }

        $stmt->bind_param('sii', $token, $accountId, $languageId);

        $stmt->execute();
        $stmt->store_result();
        if ($stmt->errno > 0) {
            VXLgate::error('createTokenMap', $stmt->error, '');
            return false;
        }
        return $token;
    }

    /*
     *  LOOKUPTOKEN 
     * 
     *  This function looks up a given string returning the accountId if the token is found
     *  If not, it returns false
     */

    static function lookupToken($token) {
        global $conn, $account, $partner;
        $sql = 'SELECT accountId, signedIn from token WHERE token = ? AND deleted = 0';

        /* Prepare statement */
        $stmt = $conn->prepare($sql);

        if ($stmt === false) {
            VXLgate::error('lookupToken', 'Wrong SQL: ' . $sql . ' Error: ' . $conn->error, E_USER_ERROR);
            return false;
        }
        /* Bind parameters. TYpes: s = string, i = integer, d = double,  b = blob */
        $stmt->bind_param('s', $token);

        /* Execute statement */
        $stmt->execute();

        // $stmt->bind_result($accountId);

        if ($stmt->store_result()) {

            if ($stmt->num_rows > 0) {

                $stmt->bind_result($accountId, $signedIn);
                $stmt->fetch();
                if ($signedIn) {
                    return $accountId;
                } else {
                    VXLgate::log('VXLGATE.LOOKUPTOKEN', "User $accountId signed out on ' . $token", '');
                    return false;
                }
            } else {
                $stmt->close();
                VXLgate::log('VXLGATE.LOOKUPTOKEN', 'Cannot find token specified at ' . $token, '');
                return false;
            }
        } else {
            print $stmt->error;
        }
    }

    /*
     *  VERIFY EMAIL CLICKED 
     * 
     *  This function looks up and ensures that the user email has been clicked
     *  
     */

    static function verifyEmailClicked($token) {
        global $conn;
        $sql = 'SELECT accountId from account WHERE emailUnique = ? ';

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $token);

        if ($stmt === false) {
            VXLgate::error('verifyEmailClicked', 'Wrong SQL: ' . $sql . ' Error: ' . $conn->error, E_USER_ERROR);
        }


        $stmt->execute();
        if ($stmt->num_rows > 0) {
            $stmt->bind_result($accountId);
            $stmt->close();
            return $accountId;
        } else {
            $stmt->close();
            return false;
        }
    }

    /*
     *  CREATE CONTENT
     * 
     *  This function created a content visited by a user
     *  
     */

    static function createContent($URL, $partnerId, $cost, $header, $provider) {
        global $conn;
        if (!$conn) {
            $conn = VXLgate::getConn();
        };

        // When creating content from url strings, the url slashes are replaced with spaces
        // However, valid urls may contain non ascii characters. In such cases these are
        // percent encoded. To enable showing these characters including the vxl trinket, 
        // this script convert percent encoded strings into utf8 characters.
        // Convert special characters supplied (such as the vxl trinket) and other
        // non ascii characters to utf prior to storing in the database.

        $encoding = mb_detect_encoding($header);
        //$header = iconv( $encoding,"UTF-8", $header);
        // $header = html_entity_decode($header);
        // $header = EncodingHell::toUtf8($header);
        // EncodingHell::testEncodings($header);

        $wpObj = new WpObj($partnerId, $URL);
        $wpObj->lookupByURL();
        if ($wpObj->found && $wpObj->post_title) {

            //$encoding = mb_detect_encoding($wpObj->post_title);
            $header = $wpObj->post_title;
        }
        VXLgate::log('createContent', 'Title from wpOBj (' . $wpObj->post_title . ')', $URL);
        $sql = "INSERT INTO content (URL, partnerId, cost, header, provider) values (?, ?, ?, ? , ?)";

        $stmt = $conn->prepare($sql);

        if ($stmt === false) {
            VXLgate::error('createContent', 'Wrong SQL: ' . $sql . ' Error: ' . $conn->error, '');
            return false;
        }
        $stmt->bind_param('siiss', $URL, $partnerId, $cost, $header, $provider);

        $stmt->execute();
        $stmt->store_result();



        if ($cost > 0) {
            $free = false;
        } else {
            $free = true;
        }
        // This catches the error occuring on a duplicate primary key on insert
        // This is handled by a lookup providing this contentobject, and updating
        // it if nescessary.

        if ($stmt->errno == 1062) {

            $contentObj = VXLgate::getContent($URL);

            // IMPLEMENT updating of contentobject here, if this changes on the server
            // IMPLEMENT
            If (!$contentObj) {
                VXLGate::error('VXLGATE.CreateContent', 'Could not create contentObj in case where insert content failed', '');
                return false;
            }

            return $contentObj;
        } else if ($stmt->errno > 0) {

            VXLgate::error('createContent', 'The insert was not ok. Error no. ' . $stmt->errno . $stmt->error, '');
            return false;
        }
        return new ContentObj($URL, $partnerId, $cost, $header, $provider, $free, $stmt->insert_id);
    }

    /*
     *  CREATE VIEW
     * 
     *  This function logs a content visited by a user
     *  
     */

    static function createView($accountId, $contentObj, $paid = false, $virtualsUsed = 0) {
        global $conn, $account, $partner;

        $sql = "INSERT INTO view (accountId, URL, partnerId, cost, paid, virtualsUsed) values (?, ?, ?, ?, ?, ?)";
        //VXLgate::log('KNOCK', 'Received contentObj has partnerId '.$contentObj->partnerId,'');
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            VXLgate::error('createVXL', 'Wrong SQL: ' . $sql . ' Error: ' . $conn->error, '');
        }
        $stmt->bind_param('isiibi', $accountId, $contentObj->URL, $contentObj->partnerId, $contentObj->cost, $paid, $virtualsUsed);

        $stmt->execute();
        $stmt->store_result();

        if ($stmt->insert_id > 0) {

            return true;
        } else {
            return false;
        }
    }

    static function logInteractionThread($route, $timeStart, $request, $response) {

        global $conn, $account;
        $timeEnd = time();
        if (is_a($account, 'ACCOUNT')) {
            $accountID = $account->id;
        }
        $duration = ($timeEnd - $timeStart);
        if (is_array($response)) {
            if (array_key_exists('frame', $response) && array_key_exists('action', $response['frame'])) {
                $event = $response['frame']['action'];
            } else {
                $event = 'NONE';
            }
            if (array_key_exists('error', $response)) {
                $event .= '_' . $response['error'];
            }
        } else {
            $event = $response;
        }


        $profile = 1;
        $sql = 'INSERT INTO interactionThread (route, runTime, accountId, event, requestHeader, requestBody, response) values (?, ?, ?, ?, ?, ?, ?)';

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            
            $requestHeader = json_encode($request->headers);
           
            $requestBody = json_encode($request->post());
            if(is_array($response)){
                  $jsonResponse = json_encode($response);
            }
            else{
                $jsonResponse = $response;
            }
          
            $stmt->bind_param('siissss', $route, $duration, $account->id, $event, $requestHeader, $requestBody, $jsonResponse);
        } else {
            print "Problem wit with SQL statement";
        }
        $stmt->execute();
    }

    static function error($funcname, $message, $data = null) {
        global $conn;
        
        try {
            if (!$conn){
                $conn = VXLgate::getConn();
            }
            if (!isset($data)) {
                $data = 'unset';
            }
            if (!isset($message)) {
                $message = 'unset';
            }
            $sql = 'INSERT INTO error (funcname, message, data) values (?, ?, ?)';

            $stmt = $conn->prepare($sql);
            
            if (is_object($data)) {
                $data = serialize($data);
            }
            
            if ($stmt === false) {
                syslog(LOG_WARNING, " $funcname - $message - $data ");
                syslog(LOG_WARNING, "Error on log function - stmt == false ");
                return true;
            }
            
            $stmt->bind_param('sss', $funcname, $message, $data);
            $stmt->execute();
            
        } catch (Exception $e) {

            print 'Error occured when logging error:' . $e->getMessage();
        }
        return true;
    }

    static function log($funcname, $message, $data = null) {
        global $conn;
        try{
        if (!isset($data)) {
            $data = 'unset';
        }
        if (!isset($message)) {
            $message = 'unset';
        }

        $sql = 'INSERT INTO log (funcname, message, data) values (?, ?, ?)';

        $stmt = $conn->prepare($sql);

        if (is_object($data)) {
            $data = serialize($data);
        }
        if ($stmt === false) {
            syslog(LOG_INFO, " $funcname - $message - $data ");
            syslog(LOG_WARNING, "Error on log function - stmt == false ");

            return true;
        }

        $stmt->bind_param('sss', $funcname, $message, $data);

        $stmt->execute();
        
        } catch (Exception $e) {

            print 'Error occured when logging error:' . $e->getMessage();
        }
        
        if ($stmt->errno > 0) {
            return 'Logging returned error message ' . $stmt->error;
        }
        return true;
    }

    static function getPartnerIdFromURL($URL) {
        global $conn;
        if (!$conn) {
            $conn = VXLgate::getConn();
        }
        $domain = str_ireplace('www.', '', parse_url($URL, PHP_URL_HOST));

        $sql = 'SELECT id from partner WHERE domain = ?';

        /* Prepare statement */
        $stmt = $conn->prepare($sql);

        if ($stmt === false) {
            VXLgate::error('getPartnerIdFromURL', 'Wrong SQL: ' . $sql . ' Error: ' . $conn->error, E_USER_ERROR);
        }
        /* Bind parameters. TYpes: s = string, i = integer, d = double,  b = blob */
        $stmt->bind_param('s', $domain);

        /* Execute statement */
        $stmt->execute();

        // $stmt->bind_result($accountId);

        if ($stmt->store_result()) {

            if ($stmt->num_rows > 0) {

                $stmt->bind_result($partnerId);
                $stmt->fetch();

                return $partnerId;
            } else {
                $stmt->close();
                VXLgate::error('VXLGATE.getPartnerIdFromURL', 'Failed to deduce partnerId from URL : (' . $URL . '). Returning standard value', '');
                return VXLpartnerId;
            }
        } else {
            VXLgate::error('VXLGATE.getPartnerIdFromURL', 'Failed to store result, returning VXL id in stead of partner ID : (' . $URL . ').Returning standard value', '');

            return VXLpartnerId;
        }
    }

}

?>