<?php
/*
Plugin Name: CNS Wordpress plugin
Plugin URI: https://github.com/harvardinformatics/cns-wp
Description: Provides a short code for cns training signup
Version: 0.1.0
Author: Aaron Kitzmiller 
Author URI: http://aaronkitzmiller.com
License: GPL2
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
Text Domain: cns
Domain Path:       /languages
*/


function activate_cns_wp() {
}

function deactivate_cns_wp() {
}

function get_base_url() {
    return plugins_url( '', __FILE__ );
}

//Encode the password
function crypto($dowhat,$key,$string){
    if ($dowhat == "encrypt"){
        $res = base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($key), $string, MCRYPT_MODE_CBC, md5(md5($key))));
    }
    if ($dowhat == "decrypt"){
        $res = rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($key), base64_decode($string), MCRYPT_MODE_CBC, md5(md5($key))), "\0");
    }
    return $res;
}

function make_links($text){
    $text = preg_replace('#(script|about|applet|activex|chrome):#is', "\\1:", $text);
    $ret = ' ' . $text;
    $ret = preg_replace("#(^|[\n ])([\w]+?://[\w\#$%&~/.\-;:=,?@\[\]+]*)#is", "\\1<a href=\"\\2\" target=\"_blank\">\\2</a>", $ret);
    $ret = preg_replace("#(^|[\n ])((www|ftp)\.[\w\#$%&~/.\-;:=,?@\[\]+]*)#is", "\\1<a href=\"http://\\2\" target=\"_blank\">\\2</a>", $ret);
    $ret = preg_replace("#(^|[\n ])([a-z0-9&\-_.]+?)@([\w\-]+\.([\w\-\.]+\.)*[\w]+)#i", "\\1<a href=\"mailto:\\2@\\3\">\\2@\\3</a>", $ret);
    $ret = substr($ret, 1);
    return $ret;
}

// Composes and sends the signup message
function send_signup_message($to, $name, $from='info@cns.fas.harvard.edu', $event, $signupid, $when, $documents){

    $subject = sprintf("CNS Training: %s Confirmation Message", $event);

    /* documents message */
    $docmessage = "";
    if (is_array($documents)){ // append links to documents
        $docmessage .= "<br><br>Click the following link(s) to download the related training documentation:<br>";
        foreach ($documents as $d){
            $docmessage .= sprintf('&#8226; <a href="%s">%s</a><br>',$d,$d);
        }
    }

    $cancelurl = sprintf('http://apps.cns.fas.harvard.edu/users/training_cancel.php?rid=%s&em=%s',$signupid, $to);
    /* message */
    $message = sprintf('Dear %s,<br><br>
        We received your registration for the following CNS Training Event:<br>
         <strong>%s on %s</strong><br><br>
        To cancel this reservation <a href="%s">click here</a><br><br>
        Thank you<br><br>The CNS Staff<br><br>
        ________________________________________________<br>
        This is an automatic CNS email confirmation.', $name, $event, $when, $cancelurl);
    send_cns_mail($to, $from, $subject, $message);

}

// Send mail, including bcc to from address
function send_cns_mail($to, $from, $subject, $message){
    /* To send HTML mail, you can set the Content-type header. */
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=iso-8859-1\r\n";
    /* additional headers */
    //$headers .= "To: <".$_POST['userEmail'].">\r\n";
    $headers .= "From: CNS Staff <".$from.">\r\n";
    //$headers .= "Cc: ".$_POST['from']."\r\n";
    $headers .= "Bcc: ".$from."\r\n";
    /* and now mail it */
    mail($to, $subject, $message, $headers);
}

function wdate($dstring){
    $d = strtotime($dstring);
    $w = date('l, F jS, Y',$d);
    return $w;
}

function connect($hostname, $username, $password, $database){
    $db = new mysqli( $hostname, $username, $password, $database);
    return $db;
}

// Used by execSql to get references for calling bind_param
function refValues($arr){
    if (strnatcmp(phpversion(),'5.3') >= 0) //Reference is required for PHP 5.3+
    {
        $refs = array();
        foreach($arr as $key => $value)
            $refs[$key] = &$arr[$key];
        return $refs;
    }
    return $arr;
}


// Returns a single row as an associative array from the specified query
// If $typestr and $vals are not specified, no param bind is done
// $typestr should be the stm->bind_param type string (e.g. "ssi")
// $vals should be an array of values to be bound to the statement
function fetch_row_assoc($db, $sql, $typestr='', $vals=array()){
    if (!is_array($vals)){
        $vals = array($vals);
    }
    $resulth = execSql($db, $sql, $typestr, $vals);
    return $resulth->fetch_assoc();
}

// Executes a SQL statement using bound parameters returning the statement result handle
// If $typestr and $vals are not specified, no param bind is done
// $typestr should be the stm->bind_param type string (e.g. "ssi")
// $vals should be an array of values to be bound to the statement
function execSql($db, $sql, $typestr='', $vals=array()){
    $stm = $db->prepare($sql) or die ("Failed to prepare the SQL statement: " . $db->error);
    if (!$typestr == ''){
        if (!is_array($vals)){
            $vals = array($vals);
        }
        array_unshift($vals, $typestr);
        call_user_func_array(array($stm, 'bind_param'), refValues($vals));     
    }
   
    $stm->execute() or die("Unable to execute statement: " . $db->error);
    $resulth = $stm->get_result() or die($db->error);
    return $resulth;
}

// Return the number of slots available for the given training
function get_taken_slots($db, $zid){
    $row = fetch_row_assoc($db, "SELECT count(atn_id) as c FROM cns_wksbooking WHERE zid = ?", 'i', array($zid));
    return $row['c'];
}
//Login user and get user information
//Username and password should be raw text
function get_user($db, $username, $password){
    $u = [];

    $userinfo = [];
    $rOK = 0;
    $eM = 0;

    $password = crypto("encrypt", getenv("NNIN_CRYPTKEY"), $password);

    // check if user is NNIN registered
    $row_rsL = [];
    if (getenv("NNIN_DEBUG") != ""){
        $row_rsL = fetch_row_assoc($db, 'SELECT * FROM nnin_login WHERE nnin_username = ?', 's', array($username));
    } else {
        $row_rsL = fetch_row_assoc($db, 'SELECT * FROM nnin_login WHERE nnin_username = ? AND nnin_pw = ?', 'ss', array($username, $password));
    }
   
    if (count($row_rsL) > 0) {
        //Legit user, so get information
        # uid
        $uid = $row_rsL['usID'];
        $u['uid']       = $uid;
        $u['active']    = 0;

        $row_rs = fetch_row_assoc($db, "SELECT * FROM nnin_users WHERE id = ?", 's', array($uid));

        if (count($row_rs) > 0) { 
            $u['name']      = sprintf('%s %s', $row_rs['First_Name'], $row_rs['Last_Name']);
            $u['email']     = $row_rs['eMail'];
            $u['phone']     = $row_rs['Phone'];
            $u['active']    = $row_rs['active'];
                
            // check if user has general cns and or LISE cleanroom training
            $NF = execSql($db, "SELECT t.tool_name FROM cns_tools t inner join cns_trainlkp tr on t.master_id = tr.toolid WHERE tr.traineeid = ? AND (toolid=116 OR toolid=144)", 'i', array($uid));
            $u['training'] = [];
            while ($row_NF = $NF->fetch_row()){
                array_push($u['training'], $row_NF[0]);
            }
        } 
    } 
    return $u;
}


//Figure out what action to take
function handle_url( $atts ){
    $out = "";
    try {
        $db = connect(getenv("NNIN_HOSTNAME"), getenv("NNIN_USERNAME"), getenv("NNIN_PASSWORD"), getenv("NNIN_DATABASE"));
        if (!$db){
            throw new Exception("Unable to connect to the CNS database: " . mysqli_connect_error());
        }
        if ((isset($_GET["ZID"]))) {
            $out = registration_form($db, array_merge($_GET, $_POST));
        }
        else {
            $out = show_training_events($db);
        }
    } catch (Exception $e){
        $out = sprintf('<div class="error">%s</div>', $e->getMessage());
    }
    return $out;
}



function show_training_events($db){
   // Leaving out the "davidsHack" piece for now.  Looks like it picks up workshops based on the dedicateLink column value
    // as specified by the "eid" URL parameter
    $eid = 0;
    $davidsHack = "";
    // This gets the month and year from cns_workshops or URL params
    $mm = $db->query("SELECT distinct month(zdate) as mm, year(zdate) as yy FROM cns_workshops WHERE zdate >= '" . date("Y-m-d") . "' ORDER BY zdate ASC");
    $row_mm = $mm->fetch_assoc();
    if (isset($_GET['mo'])){
        $selectedMonth = $db->real_escape_string($_GET['mo']);
    } else {
        $selectedMonth = $row_mm['mm'];
    }

    if (isset($_GET['y'])){
        $selectedYear = $db->real_escape_string($_GET['y']);
    } else {
        $selectedYear = $row_mm['yy'];
    }
    $view = "notall";
    if (isset($_GET['view']) && $_GET['view'] == "all"){
        $view = "all";
    }

    if ($view == "all"){
        $query_labs = "SELECT distinct zdate FROM cns_workshops WHERE zdate >= '" . date("Y-m-d") . "' OR zdate = '0000-00-00' ORDER BY zdate ASC, ztype ASC";
    } else {
        if( $eid > 0 ){ ///-------------> special David Bell Hack
            $query_labs = "SELECT distinct zdate FROM cns_workshops WHERE " . $davidsHack . " ORDER BY zdate ASC, ztype ASC";

        } else {
            $query_labs = "SELECT distinct zdate FROM cns_workshops WHERE zdate >= '" . date("Y-m-d") . "' AND month(zdate) = " . intval($selectedMonth) . " AND year(zdate) = " . intval($selectedYear) . " OR zdate = '0000-00-00' ORDER BY zdate ASC, ztype ASC";
        }
    }
    $labs = $db->query($query_labs) or die($db->error);
    $row_labs = $labs->fetch_assoc();
    $totalRows_labs = $labs->num_rows;


    //Get Month Year selection options
    $options = [];
    $optstring = "";
    do { 
        $curTime = mktime(0,0,0,$row_mm['mm'],1,$row_mm['yy']);
        $selected = "";
        if ($curTime == mktime(0,0,0,intval($selectedMonth),1,$selectedYear)){
            $selected = "selected";
        }
        $opt = sprintf('<option %s value="%s">%s %s</option>', $selected, add_query_arg( array( 'mo' => date("m",$curTime), 'y' => $row_mm['yy'])),  date("F",$curTime), date("Y", $curTime));
        array_push($options, $opt);
    } while ($row_mm = mysqli_fetch_assoc($mm));
    $optstring = implode($options);
    
    // Go through each of the training sessions 
    $c=0;
    $trs = [];
    if ($totalRows_labs > 0){
        $datetrs = [];
        do {
            $c++;
            $dateinfo = "";
            if ($row_labs['zdate'] > '0000-00-00'){
                $dateinfo = sprintf('%s', wdate($row_labs['zdate']));
            } else {
                $dateinfo = 'Date TBD - you can pre-register for this training session.<br><span class="staff-will-notify">CNS staff will notify you when a date has been set.</span>';
            }

            if ($eid > 0){ ///-------------> special David Bell Hack
                $query_lab = "SELECT cns_workshops.*, nnin_admin.First, nnin_admin.Last FROM cns_workshops, nnin_admin WHERE nnin_admin.AID =  cns_workshops.createdBy AND cns_workshops.zdate='" . $row_labs['zdate'] . "' AND (" . $davidsHack . ") ORDER BY zname ASC";
            } else {
                $query_lab = "SELECT cns_workshops.*, nnin_admin.First, nnin_admin.Last FROM cns_workshops, nnin_admin WHERE nnin_admin.AID =  cns_workshops.createdBy AND cns_workshops.zdate='" . $row_labs['zdate'] . "' ORDER BY zname ASC";
            }
            $lab = $db->query($query_lab) or die($db->error);
            $row_lab = $lab->fetch_assoc();
            $totalRows_lab = $lab->num_rows; // --------------------------

            if ($row_labs['zdate'] == '0000-00-00'){
                $pre = "Pre-";
                $bgcolor = "#E1E1E1";
            } else {
                $pre = "";
                $bgcolor = "#FFFFFF";
            }

            //Create rows for subtable
            $ntrs = [];
            array_push($ntrs, sprintf('<tr><td colspan="5" class="training-date-header">%s</td></tr>', $dateinfo));
            do {
                $query_cl = "SELECT count(Atn_ID) as dcount FROM cns_wksbooking WHERE ZID = " . $row_lab['Z_ID'];
                $cl = $db->query($query_cl) or die($db->error);
                $row_cl = $cl->fetch_assoc();
                $totalRows_cl = $cl->num_rows;
                $conto = $row_cl['dcount'];
                $available = ($row_lab['zmax'] - $conto);
                if ($available < 1){
                    $available="FULL";
                }

                if (intval($row_lab['billThis']) == 1){
                    $billThis = 1;
                    $toTID = $row_lab['bill_tool']; 
                } else {
                    $billThis = 0;
                    $toTID = 0; 
                }
                $desc = str_replace(chr(10),"<br>",make_links($row_lab['zdesc']));
                array_push($ntrs,
                    sprintf(
                        '<tr><td colspan="5" class="training-title">%s: %s - <span class="training-location">%s</span></td></tr>',
                        $row_lab['ztype'],
                        $row_lab['zname'],
                        $row_lab['zlocation']
                    )
                );
                array_push($ntrs, sprintf('<tr><td colspan="5" class="training-trainer">Trainer: %s %s</td></tr>',$row_lab['First'],$row_lab['Last']));
                array_push($ntrs, sprintf('<tr><td colspan="5" class="training-desc">%s</td></tr>', $desc));
                // Setup the header row
                $userrestrictionheader = '<strong>Open Event!</strong>';
                if ($row_lab['CNSlimited'] == 1){
                    $userrestrictionheader = 'CNS Users <strong>ONLY</strong>';
                } 
                if ($row_lab['CNSlimited'] == 2) {
                    $userrestrictionheader = 'LISE Cleanroom Users <strong>ONLY</strong>';
                }
                $tds = [
                    '<th colspan="2" align="center" width="*">Time</th>',
                    '<th width="20%" align="center">Max Attendees</th>',
                    '<th width="20%" align="center">Available</th>',
                    sprintf('<th width="30%%" align="center">%s</th>', $userrestrictionheader)
                ];
                array_push($ntrs, sprintf('<tr>%s</tr>', implode($tds)));

                // Data row with registration link
                $time = "";
                $ztime = empty($row_lab['ztime']) ? "" : $row_lab['ztime'];
                $availstyle = $available > 0 ? 'available' : 'notavailable';
                if ($row_lab['start_time'] > '0000-00-00 00:00:00'){
                    $time = sprintf('%s - %s<br/>%s', date("g:i a",strtotime($row_lab['start_time'])), date("g:i a",strtotime($row_lab['end_time'])), $ztime);
                }
                $regtd = '<strong>Registration Closed</strong>';

                if ($available > 0){
                    $reglink = add_query_arg(array( 'ZID' => $row_lab['Z_ID'], 'cEmail' => $row_lab['contactEmail'], 'limited' => $row_lab['CNSlimited'], 'bill' => $billThis, 'toTID' => $toTID));
                    $regtd = sprintf('<a href="%s">%sRegister!</a>', $reglink, $pre);
                }
                $tds = [
                    sprintf('<td colspan="2" align="center" width="30%%">%s</td>',$time),
                    sprintf('<td width="20%%" align="center">%s</td>', $row_lab['zmax']),
                    sprintf('<td width="20%%" align="center" class="%s">%s</td>', $availstyle, $available),
                    sprintf('<td width="30%%" align="center">%s</td>',$regtd)
                ];
                array_push($ntrs, sprintf('<tr>%s</tr>', implode($tds)));
                array_push($ntrs, '<tr><td colspan="5">&nbsp;</td></tr>');
                
            } while ($row_lab = $lab->fetch_assoc());

            $ntable = sprintf('<table width="100%%" border="0" cellpadding="1" cellspacing="1">%s</table>',implode($ntrs));
            $tr = sprintf('<tr class="training-date-row"><td class="training-date" >%s</td></tr>', $ntable);
            array_push($datetrs, $tr);
        } while ($row_labs = $labs->fetch_assoc());
        array_push($trs, sprintf('<table width="100%%" border="0" cellpadding="1" cellspacing="1">%s</table>',implode($datetrs)));
    }

    $rowstr = implode($trs);
    
    $out = <<<EOT
<table width="100%"  border="0" cellpadding="2" cellspacing="6" class="bodytxt">
    <tr>
        <td align="center"><strong>CNS Training Events - Registration Page</strong></td>
    </tr>
    <tr>
        <td valign="top">&nbsp;</td>
    </tr>
    <tr>
        <td valign="top"><p>Registration is currently open for the following training sessions:</p></td>
    </tr>
    <form name="form" id="form">
        <tr>
            <td align="right">
                <strong>Select month:</strong>             
                <select name="jumpMenu" class="form3" id="jumpMenu" onChange="MM_jumpMenu('parent',this,0)">
                    $optstring
                </select>
            </td>
        </tr>
    </form>
$rowstr                                   
</table>
EOT;

    return $out;

}


// Display the registration form
function registration_form($db, $params){

    $zid = $params['ZID'];
    $errorMsg = "";

    $row_lab = fetch_row_assoc($db, "SELECT * FROM cns_workshops WHERE z_id = ?", 'i', array($zid));
 
    $out = "";
    $trs = [];

 
    if (count($row_lab) == 0){
        array_push($trs, '<tr><td><strong>This training event does NOT exist.</strong></td></tr>');
    } elseif (date("Y-m-d", strtotime($row_lab['start_time'])) < date("Y-m-d")){
        array_push($trs, '<tr><td><strong>This training event is in the past.</strong></td></tr>');
    } else {

        // Go ahead and display or process the form
        // Setup the title row
        $starttime = "";
        if ($row_lab['start_time'] > '0000-00-00 00:00:00'){
            $starttime = sprintf('%s - %s', date("g:i a",strtotime($row_lab['start_time'])), date("g:i a",strtotime($row_lab['end_time'])));
        }
        if ($row_lab['start_time'] == '0000-00-00 00:00:00' && !empty($row_lab['ztime'])){
            $starttime = $row_lab['ztime'];
        }
        $titlerow = sprintf(
            '<tr><td align="center" valign="top"><strong>%s Sign-Up</strong><br/><em>on %s @ %s</em></td></tr>',
            $row_lab['zname'],
            wdate($row_lab['zdate']),
            $starttime
        );
        array_push($trs, $titlerow);

        // Doing insert
        if (isset($params['MM_insert']) && $errorMsg == ""){

            // If all the slots are taken...
            $takenslots = get_taken_slots($db, $zid);
            if ($takenslots >= $row_lab['zmax']){
                $errorMsg = '<p><strong>The event you are trying <br/>
                    to subscribe is full. <br />
                    Please try another date.</strong></p>';
            }
            else {

                //Retrieve documents for this zid
                $row_rsDocs = fetch_row_assoc($db, "SELECT zdocs from cns_workshops where z_id = ?", 'i', array($zid));
                if (!empty($row_rsDocs['zdocs'])){
                    $documents = unserialize($row_rsDocs['zdocs']);
                } else {
                    $documents="";
                }


                $cnslimited = $row_lab['CNSlimited'];

                $userinfo = [];
                $uid = 0;

                // Fetch user information
                if ($cnslimited > 0){
                    $userinfo = get_user($db, $params['txtUsername'], $params['txtPassword']);
                    if (count($userinfo) > 0){
                        $uid = $userinfo['uid'];
                    } 
                } elseif (isset($params['Full_Name']) && strlen($params['Full_Name']) < 33 && trim($params['Full_Name']) !== "" && trim($params['email']) !== "" && strlen($params['email']) < 33 && trim($params['Phone']) !== "" && strlen($params['Phone']) < 15) {
                    $userinfo['name']   = $db->escape_string(trim($params['Full_Name']));
                    $userinfo['email']  = $db->escape_string(trim($params['email']));
                    $userinfo['phone']  = $db->escape_string(trim($params['Phone']));
                } else {
                    header("Location: " . add_query_arg(array('ZID' => 'abcde')));
                }
                
                $out = "";

                // User failure messages
                if ($cnslimited > 0) {
                    if ($params['txtUsername'] == "" || $params['txtPassword'] == ""){
                        $errorMsg = "<strong>Both user name and password are required.</strong>";
                    }
                    elseif (count($userinfo) == 0){
                        $errorMsg = "<strong>Either your User Name or Password are incorrect.</strong><br><em>Please try again.</em><br>";
                    }
                    elseif ($userinfo['active'] != 1){
                        $errorMsg = "<strong>Your user account is no longer active.<br>Please call 617-496-9632 for support.</strong>";
                    }
                    elseif ($cnslimited == 2 && !array_key_exists('LISE Safety Training' , $userinfo['training'])){
                        $errorMsg = "<strong>You are a valid CNS user but <u>NOT</u> a trained LISE cleanroom user.<br>
                            You need formal Cleanroom training to attend this event.<br>
                            Please call 617-496-9632 for further explanations.</strong><br>";
                    }
                    else {
                        // Already registered?
                        $result = fetch_row_assoc($db, 'SELECT count(atn_id) as c FROM cns_wksbooking WHERE zid = ? and uid = ?', 'ii', array($zid, $uid));
                        if ($result['c'] > 0){
                            $errorMsg = '<strong>You were already registered for this event.<br />
                                No action was taken.<br />
                                CNS <u>did NOT bill you again</u> for this registration.</strong>';
                        }
                    }
                } else {
                    if ($userinfo['name'] == "" || $userinfo['email'] == "" || $userinfo['phone'] == ""){
                        $errorMsg = '<strong>Please enter your full name, email, and phone.</strong>';
                    } else {
                        // Already registered
                        $result = fetch_row_assoc($db, 'SELECT count(atn_id) as c FROM cns_wksbooking WHERE zid = ? and atemail = ?', 'is', array($zid, $userinfo['email']));
                        if ($result['c'] > 0){
                            $errorMsg = '<strong>You were already registered for this event.<br />
                                No action was taken.</strong>';
                        }
                    }
                }

                // If no errors, register the user
                if ($errorMsg == ""){

                    $stm = $db->prepare('INSERT INTO cns_wksbooking (atname, atemail, atphone, uid, zid) VALUES (?, ?, ?, ?, ?)');
                    $stm->bind_param('sssii', $userinfo['name'], $userinfo['email'], $userinfo['phone'], $uid, $zid);
                    $stm->execute() or die($db->error);
                    $newrid = $db->insert_id;
                        // mysql_select_db($database_NNIN, $NNIN);
                        // $Result1 = mysql_query($insertSQL, $NNIN) or die(mysql_error());
                        // $newRID = mysql_insert_id();
                        // $updateStrings = "UPDATE cns_wksbooking SET atName = REPLACE(atName,'_',' '), atEmail = REPLACE(atEmail,'...','.') WHERE Atn_ID = ".$newRID."";
                        // $Result2 = mysql_query($updateStrings, $NNIN) or die(mysql_error());
                    $closeW="yes";
                        
                    // --- invoicing module ---------------- 
                    // if($params['billThis'] == 1 && $uid > 0){
                    //     // require_once("training_invoicing.php");
                    // }
                    
                    // ---------- send eMail --------------------------------------------------
                    send_signup_message($userinfo['email'], $userinfo['name'], $row_lab['contactEmail'], $params['event'], $newrid, $params['when'], $documents);
                    array_push($trs,
                        sprintf(
                            '
                            <tr>
                                <td valign="top">
                                    <table width="100%%" height="150" border="0" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td align="center" valign="middle">
                                                <p><strong>Thank you!</strong></p>
                                                <p><strong> A confirmation message<br />
                                                    has been sent to<br />
                                                    %s</strong>
                                                </p>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            ', 
                            $userinfo['email']) 
                    );
                        
                }  
            }
        } // End doing registration insert

        if (!(isset($params['MM_insert'])) or $errorMsg != "") { 

            //Display the registration form
            $totid = intval($params['toTID']);

            // Ignore the GET parameter and rely on the value of totid
            $bill = 0;
            if ($totid > 299 and $totid < 303){
                $bill = 1;
            }


            $formrows = [];

            // If there is an error, show the message and the form field values
            if ($errorMsg !== "") {
                array_push($formrows, sprintf('<tr><td colspan="2">%s</td></tr>', $errorMsg));
            }

            // Add the hidden form fields
            array_push($formrows, sprintf('<input type="hidden" name="ZID" value="%s"/>', $zid));
            array_push($formrows, sprintf('<input type="hidden" name="billThis" value="%s"/>', $bill));
            array_push($formrows, sprintf('<input type="hidden" name="bill_tool" value="%s"/>', $totid));
            array_push($formrows, sprintf('<input type="hidden" name="event" value="%s"/>', $row_lab['zname']));
            array_push($formrows, sprintf('<input type="hidden" name="zmax" value="%s"/>', $row_lab['zmax']));
            array_push($formrows, sprintf('<input type="hidden" name="zdate" value="%s"/>', $row_lab['zdate']));
            array_push($formrows, sprintf('<input type="hidden" name="zStart" value="%s"/>', $row_lab['start_time']));
            array_push($formrows, sprintf('<input type="hidden" name="zStop" value="%s"/>', $row_lab['end_time']));
            array_push($formrows, sprintf('<input type="hidden" name="when" value="%s @ %s"/>', wdate($row_lab['zdate']), $starttime));
            array_push($formrows, sprintf('<input type="hidden" name="desc" value="%s"/>', $row_lab['zdesc']));
            array_push($formrows, sprintf('<input type="hidden" name="CNSlimited" value="%s"/>',$row_lab['CNSlimited']));


            if ($row_lab['CNSlimited'] > 0){

                // If this is billable
                if ($bill > 0 or ($totid > 299 && $totid < 303)){
                    //Fetch the fees
                    $query_userfees = "SELECT HFee, NHFee, NAFee, IPPFee FROM cns_toolbillspecs WHERE toolMasterID = ?";
                    $stm = $db->prepare($query_userfees);
                    $stm->bind_param('i', $totid);
                    $stm->execute() or die($db->error);
                    $userfees = $stm->get_result();
                    $row_userfees = $userfees->fetch_assoc();
                    $totalRows_userfees = $userfees->num_rows;

                    $feesAr = array();
                    $feesAr[] = number_format(($row_userfees['HFee'] * $row_lab['duration'] / $row_lab['zmax']), 2);
                    $feesAr[] = number_format(($row_userfees['NHFee'] * $row_lab['duration'] / $row_lab['zmax']), 2);
                    $feesAr[] = number_format(($row_userfees['IPPFee'] * $row_lab['duration'] / $row_lab['zmax']), 2);
                    $feesAr[] = number_format(($row_userfees['NAFee'] * $row_lab['duration'] / $row_lab['zmax']), 2);

                    array_push(
                        $formrows, 
                        '<tr>
                            <td colspan="2">
                                <table width="100%" border="0" cellspacing="0" cellpadding="3">
                                    <tr>
                                        <td valign="top"><strong>Please Note:</strong> according to your CNS user status, you will be charged a fee for this training session. See fee prospectus below:
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>'
                    );

                    $feerows = [];
                    array_push(
                        $feerows,
                        sprintf(
                            '<tr>
                                <td width="30%%" height="25" align="right"><strong>Academic** </strong></td>
                                <td width="20%%" height="25">$%01.2f</td>
                                <td width="30%%" height="25" align="right"><strong>ClubNano</strong></td>
                                <td width="20%%" height="25">$%01.2f</td>
                            </tr>', 
                            $feesAr[0],
                            $feesAr[2]
                        )
                    );
                    array_push(
                        $feerows,
                        sprintf(
                            '<tr>
                                <td height="25" colspan="2" valign="top"><strong>**</strong> If you are not paying with a Harvard billing code, an extra 30%% overhead will be charged</td>
                                <td width="30%%" height="25" align="right"><strong>Standard Non Academic</strong></td>
                                <td width="20%%" height="25">$%01.2f</td>
                            </tr>',
                            $feesAr[3]
                        )
                    );

                    $feesrow = sprintf(
                        '<tr>
                            <td colspan="2">
                                <table width="100%%" border="0" cellpadding="2" cellspacing="1">
                                    %s
                                </table>
                            </td>
                        </tr>',
                        implode($feerows)
                    );
                    array_push($formrows, $feesrow);

                } // End of billable rows

                // Add login rows
                array_push($formrows,
                    '<tr>
                        <td colspan="2" align="center">Please sign up using your CNS user login credentials:</td>
                    </tr>'
                );
                array_push($formrows,
                    '<tr>
                        <td colspan="2" align="center">
                            <table width="250" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td>
                                        <table width="250" border="0" cellpadding="2" cellspacing="1">
                                            <tr>
                                                <td width="80"><strong>User Name*</strong></td>
                                                <td><input name="txtUsername" type="text" class="form3" id="txtUsername" size="20" /></td>
                                            </tr>
                                            <tr>
                                                <td width="80"><strong>Password*</strong></td>
                                                <td><input name="txtPassword" type="password" class="form3" id="txtPassword" size="10" /></td>
                                            </tr>
                                            <tr>
                                                <td colspan="2">* <em>required</em></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>'
                );
                array_push($formrows,
                    '<tr>
                        <td colspan="2" align="center"><strong>By signing up for this training you agree to pay the fee correspondent to your CNS user status.</strong></td>
                    </tr>'
                );
                // end of login rows
            } else {
                // Open to the public
                array_push($formrows, 
                    '
                    <tr>                   
                        <td colspan="2">&nbsp;</td>       
                    </tr>
                    <tr>
                        <td width="30%"><strong>Full&nbsp;Name*</strong></td>
                        <td width="70%"><input name="Full_Name" type="text" class="form3" id="Full_Name" size="30" /></td>
                    </tr>
                    <tr>
                        <td width="30%"><strong>eMail*</strong></td>
                        <td width="70%"><input name="email" type="text" class="form3" id="email" size="30" /></td>
                    </tr>     
                    <tr>
                        <td width="30%"><strong>Phone*</strong></td>
                        <td width="70%"><input name="Phone" type="text" class="form3" id="Phone" size="15" /></td>
                    </tr>
                    <tr>
                        <td colspan="2">* <em>required</em> </td>
                    </tr>
                    '
                );
            } // End of cns limited or not

            array_push($formrows, 
                '
                <tr>
                    <td colspan="2">&nbsp;</td>
                </tr> 
                <tr>
                    <input type="hidden" name="MM_insert" value="reg" />
                    <td colspan="2" align="center"><input type="submit" class="button2" value="sign-up" /></td>
                </tr>
                '
            );
            $formstr = sprintf(
                '<form id="reg" method="POST" action="" name="reg" onsubmit="return document.MM_returnValue"><table width="100%%" border="0" cellpadding="0" cellspacing="4">%s</table></form>',
                implode($formrows)
            );
            array_push($trs, sprintf('<tr><td valign="top">%s</td></tr>', $formstr));

        }


    $out = sprintf('<table width="350" border="0" align="center" cellpadding="0" cellspacing="5" class="bodytxt">%s</table>', implode($trs));
    return $out;        

    } // Training OK

}

// Allows me to test outside of wordpress environment
if (function_exists('add_shortcode')){
    register_activation_hook( __FILE__, 'activate_cns_wp' );
    register_deactivation_hook( __FILE__, 'deactivate_cns_wp' );

    add_shortcode( 'training_events', 'handle_url' );
    add_action('wp_enqueue_scripts', 'add_scripts');
    function add_scripts() {
        wp_register_style( 'cnswp', get_base_url() . '/cnswp.css' );
        wp_enqueue_style( 'cnswp' );
        wp_enqueue_script( 'cnswp', get_base_url() . '/cnswp.js', array( 'jquery' ) );
    }
}
