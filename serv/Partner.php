<?php

/*  Partner Class
 * 
 * ORM table : Partner
 * 
 * This is an object mapping of the Partner table with additional methods.
 * 
 * Notably functions
 * 
 * The partner class decides which menus are avaiable to the user (this is for
 * enabling customization of the menus for individual partners).
 * It contains static methods for looking up partners using root domain of
 * an http referer string, useful when the contentObj was not found.
 * 
 * It also keeps the secret which is used to validate curl calls between
 * wordpress sites and the wp/store functionality.
 * 
 */

//namespace kardang\Bid\Controller;
use Symfony\Component\HttpFoundation\Request;

global $conn;

class Partner {

    public $id;
    public $partnerName;
    public $anonCompensation;
    public $emailCompensation;
    public $cardCompensation;
    public $rootDomain;
    public $balance;
    public $languageId;
    public $logosrc;
    public $subsc_cost;
    public $cutOffDate;
  
    public $defaultCost;
    private $secret;
    public $wordpressPage;

    function __construct($id) {
        global $conn;

        /*
          if(is_numeric($identifier == 1)){
          $id = $identifier;
          }else{
          VXLgate::log('Partner.__construct', 'Partner receiving non numeric identifier . '.$identifier.'), and lookup by domain failed.', '');

          $id = $this->getpartnerIdByRootDomain( $identifier);
          if(!$id){
          VXLgate::error('Partner.__construct', 'Partner identifier not numeric, and lookup by domain failed.', '');
          return false;
          }
          }
         */

        $sql = 'SELECT id, partnerName, balance, anonCompensation, emailCompensation, cardCompensation, rootDomain, languageId, cutoffDate, logosrc, defaultCost, secret, wordpressPage FROM partner where id = ?';


        if (!$conn) {

            VXLgate::error('Partner.__CONSTRUCT ', 'Connection failed', '');
            return false;
        }

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            VXLgate::error('Partner.__CONSTRUCT', 'Wrong SQL: ' . $sql . ' Error: ' . $conn->error, $this->id);
            return false;
        }
        $stmt->bind_param("i", $id);
        $stmt->execute();

        $stmt->bind_result($this->id, $this->partnerName, $this->balance, $this->anonCompensation, $this->emailCompensation, $this->cardCompensation, $this->rootDomain, $this->languageId, $this->cutoffDate, $this->logosrc, $this->defaultCost, $this->secret, $this->wordpressPage);

        if ($stmt->store_result()) {
            $stmt->fetch();
            if ($stmt->num_rows == 1) {
                return true;
            } else {
                VXLgate::error('Partner.__CONSTRUCT', 'Partner not found or > 1 found :, for partnerId (' . $id . ') got num rows : (' . $stmt->num_rows . '). SQL: ' . $sql, $stmt->error);
                return false;
            }
        } else {
            VXLgate::error('Partner.__CONSTRUCT', 'Store result failed for ' . $sql, $stmt->error);
            return false;
        }
    }

    /* getNewContentPolicy
      Determines what to do with te new content
     *      */

    function getNewContentPolicy() {
        if ($this->defaultCost > 0) {
            return 'create';
        } else {
            return 'free';
        }
    }

    function getShopCampaign() {
        return array(
            'header' => 'Kjøp løssalg, få igjen VXLs ',
            'description' => 'Kjøp Glimmersrand løssagsutgaven Helgeutgave til 39 kr, og få 39 VXLs innbytte.  Tilbudet gjelder på 7-11, DeliDeLuca og Narvesen',
            'instructions' => 'Klikk på knappen under når du står i kassen, og vis eller si de magiske ordene til betjeningen'
        );
    }

    function toJSON() {

        $temp = $this;

        return $temp;
    }

    /* getCarousel
      Partners can customize their own sales carousel settings.
     */

    function getCarousel($stage, $empty = false) {

        $transPages = array(
            "main",
            "card",
            "account",
            "views",
            "invoice",
            "mobile",
            "trust"
        );
        $anonPages = array(
            "main",
            "emailReward",
            "signIn"
        );
        $newPages = array(
            "newanon",
            "newanon1",
            "newanon2",
            "newanon3",
            "newanon4",
            "main"
        );

        $emptyPages = array(
            "main",
            "invoice",
            "card",
            "account",
            "views",
        );

        if ($empty) {
            return $emptyPages;
        }

        switch ($stage) {
            case 'trans':
                return $transPages;
                break;
            case 'anon':
                return $anonPages;
                break;
            case 'newanon':
                return $newPages;
                break;
        }
    }

    /*
     *  UPDATEVIRTUAL AND REAL BALANCE 
     * 
     *  This function counts the VXL coins and updates the balance for partner. 
     *  It separates the balance on virtual and real, giving the user an uddated view
     *  on the amount of credit at his disposal.
     *  This function can be severly optimized, which will be done later
     *  
     */

    function computeBalance() {
        global $conn;
        $sql = 'SELECT count(id) as balance FROM VXL '
                . 'WHERE domain = 1 AND $id = ?';

        if ($conn) {

            VXLgate::error('PARTNER.COMPUTEBALANCE ', 'Connection failed', '');
            return false;
        }

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            VXLgate::error('PARTNER.COMPUTEBALANCE', 'Wrong SQL: ' . $sql . ' Error: ' . $conn->error, $this->id);
            return false;
        }
        $stmt->bind_param("i", $this->id);
        $stmt->execute();

        $stmt->bind_result($balance);
        $stmt->store_result();
        if ($stmt->num_rows === 0) {

            $this->balance = 0;
            return true;
        }
        if ($stmt->fetch()) {

            $this->balance = $balance;
            return true;
        } else {
            VXLgate::error('PARTNER.COMPUTEBALANCE', 'Unable to compute the number of credits' . $conn->error, '');
            return false;
        }
    }

    function save($context) {
        if (!$conn) {

            VXLgate::error('PARTNER.SAVE ', 'Connection failed', '');
            return false;
        }
        $sql = 'UPDATE partner SET partnerName = ?, balance = ?,  anonCompensation = ?, emailCompensation = ?, cardCompensation, rootDomain = ? , languageId = ? WHERE id = ?';
        $stmt4 = $conn->prepare($sql);

        if ($stmt4->error) {
            VXLgate::error('PARTNER.save', 'Error preparing SQL statement', '');
        }
        $stmt4->bind_param('siiisisi', $this->partnerName, $this->balance, $this->anonCompensation, $this->emailCompensation, $this->cardCompensation, $this->rootDomain, $this->languageId, $this->wordpressPage, $this->id);

        $stmt4->execute();

        $stmt4->store_result();
        // print "Updated the ".$this->id.", affected rows ".$stmt4->affected_rows." balance now ".$this->balance;
        if ($stmt4->error) {
            VXLgate::error('Partner.Save', 'Unable to persist the partner object ' . $stmt4->error, $context);
            $stmt4->close();
            return false;
            ;
        } else {
            VXLgate::log('Partner.SAve', 'Sucessfully saved partner object ' . $stmt4->error, $context);

            return true;
        }
    }

    function get() {
        return array(
            'partnerName' => $this->partnerName,
            'anonCompensation' => $this->anonCompensation,
            'emailCompensation' => $this->emailCompensation,
            'cardCompensation'  => $this->cardCompensation,
            'logosrc' => $this->logosrc
        );
    }

    function getSubscCost() {
        if ($this->subsc_cost) {
            return $this->subsc_cost;
        } else {
            VXLgate::error('Partner.getSubsc_cost', 'Could not return subsc_cost for this partner. Spent not set in account object', '');
            return false;
        }
    }

    function getSubscProgress($spent) {
        return (($spent / $this->subsc_cost) * 100) . '%';
    }

    function getpartnerIdByRootDomain($URL) {
        global $conn;
        if (!$conn) {
            $conn = VXLgate::getConn();
            VXLgate::log('Partner.getpartnerIdByRootDomain ', 'Connection failed', 'Attempting to recover bet VXLgate::getConn()');
            return false;
        }
        $domain = str_ireplace('www.', '', parse_url($URL, PHP_URL_HOST));
        VXLgate::log('Partner.getpartnerIdByRootDomain', 'Looking up  ' . $domain . ' from ' . $URL, '');


        $sql = 'SELECT id FROM partner where domain = ?';

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            VXLgate::error('Partner.getpartnerIdByRootDomain', 'Wrong SQL: ' . $sql . ' Error: ' . $conn->error, $this->id);
            return false;
        }
        $stmt->bind_param("s", $domain);
        $stmt->execute();

        $stmt->bind_result($id);

        if ($stmt->store_result()) {

            if ($stmt->num_rows == 1) {
                $stmt->fetch();

                return $id;
            } else {
                VXLgate::error('Partner.getpartnerIdByRootDomain', 'Partner not found or > 1 found - Got a result where num rows were not 1, for partnerId ' . $id . 'actually ' . $stmt->num_rows . '. SQL: ' . $sql, $stmt->error);
                return false;
            }
        } else {
            VXLgate::error('Partner.getpartnerIdByRootDomain', 'Store result failed for ' . $sql, $stmt->error);
            return false;
        }
        return false;
    }

    function getSecret() {
        return $this->secret;
    }

}
?>