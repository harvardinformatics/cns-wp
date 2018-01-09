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

//Figure out what action to take
function handle_url( $atts ){
    $out = '';
    try {
        $db = connect(getenv("NNIN_HOSTNAME"), getenv("NNIN_USERNAME"), getenv("NNIN_PASSWORD"), getenv("NNIN_DATABASE"));
        if (!$db){
            throw new Exception("Unable to connect to the CNS database: " . mysqli_connect_error());
        }
        $params = array_merge($_GET, $_POST);

        if (isset($_GET["ZID"])) {
            $out = registration_form($db, $params);
        }
        elseif (array_key_exists('rid', $params)) {
            $out = training_cancel($db, $params);
        }
        else {
            $out = show_training_events($db);
        }
    } catch (Exception $e){
        $out = sprintf('<table class="signup-table"><tr><td class="cns-error">%s</td></tr></table>', $e->getMessage());
    }
    $preamble = '<script type="text/javascript">var cnswp=true;</script>';
    return $preamble . $out;
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

    $cancelurl = sprintf('%s?rid=%s&em=%s',get_permalink($post->ID),$signupid, $to);
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

// Send cancellation email
function send_cancellation_email($to, $from, $name, $event, $eventdate, $invid){

    $invoicenotice = "";
    if ($invid > 0){
        $invoicenotice = "You will NOT be charged for this canceled training event.";        
    }

    $subject = sprintf("CNS Training: %s Your Reservation Has Been Deleted", $event);
    $message = sprintf(
        "Dear %s, <br/><br/>
        Your reservation for the %s CNS Training Event on %s has been deleted.<br/>
        %s<br/>
        Thank you,<br/><br/>
        The CNS Staff.<br/><br/>
        ________________________________________________<br>
        This is an automatic CNS email confirmation.
        ",
        $name,
        $event,
        $eventdate,
        $invoicenotice
    );
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

// Used by exec_sql to get references for calling bind_param
function ref_values($arr){
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
    $resulth = exec_sql($db, $sql, $typestr, $vals);
    return $resulth->fetch_assoc();
}

// Executes a SQL statement using bound parameters returning the statement result handle
// If $typestr and $vals are not specified, no param bind is done
// $typestr should be the stm->bind_param type string (e.g. "ssi")
// $vals should be an array of values to be bound to the statement
function exec_sql($db, $sql, $typestr='', $vals=array()){
    $stm = $db->prepare($sql);
    if (!$stm){
        error_log(sprintf("Error preparing SQL statement: %s\n%s\n%s", $sql, $typestr, implode('---', $vals)));
        throw new Exception("Failed to prepare the SQL statement: " . $db->error);
    } 
    if (!$typestr == ''){
        if (!is_array($vals)){
            $vals = array($vals);
        }
        array_unshift($vals, $typestr);
        call_user_func_array(array($stm, 'bind_param'), ref_values($vals));     
    }
   
    if (!$stm->execute()){
        error_log(sprintf("Error executing SQL statement %s\n%s",$sql,implode("---", $vals)));
        throw new Exception("Unable to execute statement: " . $db->error);
    }
    $resulth = $stm->get_result();
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
            $NF = exec_sql($db, "SELECT t.tool_name FROM cns_tools t inner join cns_trainlkp tr on t.master_id = tr.toolid WHERE tr.traineeid = ? AND (toolid=116 OR toolid=144)", 'i', array($uid));
            $u['training'] = [];
            while ($row_NF = $NF->fetch_row()){
                array_push($u['training'], $row_NF[0]);
            }
        } 
    } 
    return $u;
}

// Create an invoice number of the form:
// YYYYMMinvoice id
function create_invoice_number($year, $month, $invID){

    // Calculate fiscal year
    if (intval($month) > 6){
        $fy = date("y", mktime(0,0,0,0,0,($year + 2)));
    } else {
        $fy = date("y", mktime(0,0,0,0,0,($year + 1)));
    }

    // ? fiscal month ?
    if (intval($month) < 10){
        $fm = date("m", mktime(0,0,0,($month+1),0,0));
    } else {
        $fm = $month;
    }

    if (strpos($invID,"-") === false){
        $invID = substr($invID,-4,4);
    } else {
        $ext = substr($invID,strpos($invID,"-"),strlen($invID));
        $tempID = substr($invID,0,strpos($invID,"-"));
        $tempID = substr($tempID,-4,4);
        $invID = $tempID.$ext;
    }
    $newNO = $fy.$fm.$invID;
    return $newNO;
}

// Charge for training
// $db = database handle
// $uid =  user id
// $tid = "tool" to be billed
// $rid = id of the training registration
// $zid = the event to which the user is registered
// $zdate = date of the event
// $zstart = start time of the event
// $zstop = end time of the event
// $eventinfo = event info
function make_training_invoice($db, $uid, $tid, $rid, $zid, $zdate, $zstart, $zstop, $eventinfo ){

    //Get workshop data
    $rowwshop = fetch_row_assoc($db, "SELECT * FROM cns_workshops WHERE Z_ID = ?", 'i', array($zid));

    //Get user cap
    $rowuser = fetch_row_assoc($db, "SELECT * FROM nnin_users WHERE ID = ?",'i',array($uid));
    $usercap = $rowuser['Cap'];

    //Determine user fee based on the Cap values
    $row_fees = fetch_row_assoc($db, "SELECT HFee, NHFee, NAFee, IPPFee FROM cns_toolbillspecs WHERE toolMasterID = ?", 'i', array($tid));

    $feeArray = array();
    $feeArray[2000] = $row_fees['HFee'];
    $feeArray[2600] = $row_fees['NHFee'];
    $feeArray[11999] = $row_fees['IPPFee'];
    $feeArray[12000] = $row_fees['NAFee'];

    $userfee = number_format(($feeArray[$usercap] * $rowwshop['duration'] / $rowwshop['zmax']), 2);

    // Harvard or non-Harvard billing
    if ($cap != 2000 && $cap != 11999){
        $billcode = $rowuser['PO_number'];
    } else {
        $billcode = $rowuser['Billing_Code'];
    }

    $invid = make_invoice($db, $tid, $uid, $rowuser['PIID'], $billcode, $zdate, 1, $userfee, 0, $userfee, 0, $zstart, $zstop, 0, $eventinfo, "", $rowwshop['duration'], "use", 3, date("Y-m-d H:i:s"));

    exec_sql($db, "UPDATE cns_wksbooking SET invoiceid = ? WHERE atn_id = ?", 'ii', array($invid, $rid));
        

}

// Insert an invoice record, record the transaction and return the new invoice id
// $db = Database handle
// $tid = Tool id
// $uid = User id
// $piid = PI id
// $account = Harvard billing code or PO number
// $datestring = Date of the thing that is being billed for
// $hours
// $normrate
// $aurate
// $total
// $auhours
// $zstart = check in time  used in cns_billing_ops
// $zstop = check out time ued in cns_biling_ops
// $runcap = ?
// $notes = free text notes about invoiced thing
// $intnotes ?
// $realhours = actual duration
// $billunit = ?
// $lastaid ?
// $lts ?
function make_invoice($db, $tid, $uid, $piid, $account, $datestring, $hours, $normrate, $aurate, $total, $auhours, $zstart, $zstop, $runcap, $notes, $intnotes, $realhours, $billunit, $lastaid, $lts){

    $year = date("Y",strtotime($datestring)); //iYear
    $month = date("n",strtotime($datestring)); //iMonth
    $dated = date("j",strtotime($datestring)); // "usage" day
    $datelast = date("t",strtotime($datestring));
    $lastofmonth = $year . "-" . date("m",strtotime($datestring)) . "-" . $datelast; // invoice real date
    $quarter = ceil($month/3);

    $vals = array($tid, $uid, $piid, $account, $datestring, $hours, $normrate, $aurate, $total, $auhours, $month, $year, $quarter, $runcap, $notes, $intnotes, $lastofmonth, $realhours, $billunit, $lastaid, $lts);
    exec_sql($db, "INSERT INTO CNS_Invoices (iTID, iUID, iPIID, iAccount, iDates, iHours, iNormRate, iAuRate, iTotal, iAuHours, iMonth, iYear, iQuarter, runCap, iNotes, iIntNotes, realDate, realhours, billUnit, lastAID, lts) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",'iiissdddddiiiisssdsis',$vals);
            
    $newinvid = $db->insert_id;

    //----- calculate cumulative monthly invoice number -------------
    $datestring = date("Ym");
    $l = strlen($uid);
    $d = 5-$l;
    $zeros = "";
    for ($i = 0; $i < $d; $i++){
        $zeros .= "0";
    }
    $cumulative = $datestring.$zeros.$uid;

    // ---- calculate invoice number ---------------------
    $in = create_invoice_number($year, $month, $newinvid);

    // ------------- update invoice to include the invoice number -----------------
    exec_sql($db, "UPDATE CNS_Invoices SET inv_ref = ?, inv_no = ?, inv_cumno = ? WHERE inv_id = ?", 'issi', array($newinvid, $in, $cumulative, $newinvid));

    // Record the transation in cns_billing_ops
    $opsNoCap = record_transaction($db, 0, 0, $uid, 0, $tid, $newinvid, $month, $year, 0, $zstart, $zstop,"" ,0 , $zstart, $zstop, "", $notes, $billunit, $dated, $total, $billcode, 1, $total, $realhours, 0, 0);
    return $newinvid;
}

// Insert into cns_billing_ops
function record_transaction($db, $calendarid, $cleanid, $userid, $adminid, $toolid, $invoiceid, $month, $year, $clean_usetype, $clean_checkin, $clean_checkout, $clean_notes="", $clean_walkup, $calendar_start, $calendar_end, $calendar_notes="", $invNotes="", $billingUseType, $days, $rate, $account, $billableHours, $amount, $realHrs, $cap_eligible, $capped){ 

    $vals = array( $calendarid, $cleanid, $userid, $adminid, $toolid, $invoiceid, $month, $year, $clean_usetype, $clean_checkin, $clean_checkout, $clean_notes, $clean_walkup, $calendar_start, $calendar_end, $calendar_notes, $invNotes, $billingUseType, $days, $rate, $account, $billableHours, $amount, $realHrs, $cap_eligible, $capped
    );
    exec_sql($db, "INSERT INTO cns_billing_ops (calendarid,cleanid,userid,adminid,toolid,invoiceid,oMonth,oYear,clean_usetype,clean_checkin,clean_checkout,clean_notes,clean_walkup,calendar_start,calendar_end,calendar_notes,invNotes,billingUseType,days,rate,account,billableHours,amount,realHrs,cap_eligible,capped) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", 'iiiiiiiiissbissbbssdsdddii', $vals);

    $newOpsID = $db->insert_id;
    return $newOpsID;
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
                $row_cl = fetch_row_assoc($db, "SELECT count(Atn_ID) as dcount FROM cns_wksbooking WHERE ZID = ?", 'i', $row_lab['Z_ID']);
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
                    $reglink = add_query_arg(array( 'ZID' => $row_lab['Z_ID'], 'toTID' => $toTID));
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
        <td valign="top"><h2>Registration is currently open for the following training sessions:</h2></td>
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
            '<tr>
                <td align="center">
                    <span class="training-title">%s Sign-Up</span><br/>
                    <span class="training-subtitle">%s @ %s</span>
                </td>
            </tr>',
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
                $row_rsDocs = fetch_row_assoc($db, "SELECT zdocs FROM cns_workshops WHERE z_id = ?", 'i', array($zid));
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
                }
                
                $out = "";

                // User failure messages
                if ($cnslimited > 0) {
                    if ($params['txtUsername'] == "" || $params['txtPassword'] == ""){
                        $errorMsg = 'Both user name and password are required.';
                    }
                    elseif (count($userinfo) == 0){
                        $errorMsg = "Either your User Name or Password are incorrect.<br><em>Please try again.</em><br>";
                    }
                    elseif ($userinfo['active'] != 1){
                        $errorMsg = 'Your user account is no longer active.<br>Please contact <a href="mailto:info@cns.fas.harvard.edu">info@cns.fas.harvard.edu</a> to reactivate.';
                    }
                    elseif ($cnslimited == 1 && !in_array('LISE Safety Training' , $userinfo['training'])) {
                        $errorMsg = 'You are a valid CNS user but need LISE Safety Training (NF05).<br>
                            Please contact CNS staff (<a href="mailto:info@cns.fas.harvard.edu?subject=NF05%20Training%20needed">info@cns.fas.harvard.edu</a>) for further explanation.<br/><br/>';
                    }
                    elseif ($cnslimited == 2) {
                        if (!in_array('LISE Safety Training' , $userinfo['training']) && !in_array('General Nanofabrication Facility Access' , $userinfo['training'])){
                            $errorMsg = 'You are a valid CNS user but need LISE Safety Training (NF05) and General Nanofabrication Facility Access Training (NF01) to attend this event<br>
                                Please contact CNS staff (<a href="mailto:info@cns.fas.harvard.edu?subject=NF05%20and%20NF01%20Training%20needed">info@cns.fas.harvard.edu</a>) for further explanation.<br/><br/>';
                        }
                        elseif (!in_array('General Nanofabrication Facility Access' , $userinfo['training'])){
                            $errorMsg .= 'You are a valid CNS user but <u>NOT</u> a trained LISE cleanroom user.<br>
                                You need formal General Nanofabrication Facility Access Training (NF01) to attend this event.<br>
                                Please contact CNS staff (<a href="mailto:info@cns.fas.harvard.edu?subject=NF01%20Training%20needed">info@cns.fas.harvard.edu</a>) for further explanation.<br/><br/>';
                        }
                    }
                    else {
                        // Already registered?
                        $result = fetch_row_assoc($db, 'SELECT count(atn_id) as c FROM cns_wksbooking WHERE zid = ? and uid = ?', 'ii', array($zid, $uid));
                        if ($result['c'] > 0){
                            $errorMsg = 'You were already registered for this event.<br />
                                No action was taken.<br />
                                CNS <u>did NOT bill you again</u> for this registration.';
                        }
                    }
                } else {
                    if (!$userinfo['name'] || !$userinfo['email'] || !$userinfo['phone']){
                        $errorMsg = 'Please enter your full name, email, and phone.';
                    } else {
                        // Already registered
                        $result = fetch_row_assoc($db, 'SELECT count(atn_id) as c FROM cns_wksbooking WHERE zid = ? and atemail = ?', 'is', array($zid, $userinfo['email']));
                        if ($result['c'] > 0){
                            $errorMsg = 'You were already registered for this event.<br />
                                No action was taken.';
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
                        
                    // bill if necessary
                    if ($params['billThis'] == 1 && $uid > 0){
                        $zdate  = $params['zdate'];
                        $zstart = $params['zStart'];
                        $zstop  = $params['zStop'];
                        $tid    = $params['bill_tool'];
                        $eventinfo = sprintf("CNS Training: %s - %s", $params['event'], $params['when']);
                        make_training_invoice($db, $uid, $tid, $newrid, $zid, $zdate, $zstart, $zstop, $eventinfo);
                    }
                    
                    // ---------- send eMail --------------------------------------------------
                    send_signup_message($userinfo['email'], $userinfo['name'], $row_lab['contactEmail'], $params['event'], $newrid, $params['when'], $documents);
                    array_push($trs,
                        sprintf(
                            '
                            <tr>
                                <td valign="top" class="training-note">
                                    Thank you!<br/>
                                    A confirmation message has been sent to %s.
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
                array_push($formrows, sprintf('<tr><td class="cns-error" colspan="2">%s</td></tr>', $errorMsg));
            }

            // Add the hidden form fields
            array_push($formrows, sprintf('<input type="hidden" name="ZID" value="%s"/>', $zid));
            array_push($formrows, sprintf('<input type="hidden" name="billThis" value="%s"/>', $bill));
            array_push($formrows, sprintf('<input type="hidden" name="bill_tool" value="%s"/>', $totid));
            array_push($formrows, sprintf('<input type="hidden" name="event" value="%s"/>', $row_lab['zname']));
//            array_push($formrows, sprintf('<input type="hidden" name="zmax" value="%s"/>', $row_lab['zmax']));
            array_push($formrows, sprintf('<input type="hidden" name="zdate" value="%s"/>', $row_lab['zdate']));
            array_push($formrows, sprintf('<input type="hidden" name="zStart" value="%s"/>', $row_lab['start_time']));
            array_push($formrows, sprintf('<input type="hidden" name="zStop" value="%s"/>', $row_lab['end_time']));
            array_push($formrows, sprintf('<input type="hidden" name="when" value="%s @ %s"/>', wdate($row_lab['zdate']), $starttime));
            array_push($formrows, sprintf('<input type="hidden" name="desc" value="%s"/>', $row_lab['zdesc']));
//            array_push($formrows, sprintf('<input type="hidden" name="CNSlimited" value="%s"/>',$row_lab['CNSlimited']));


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
                            <td colspan="2" class="training-note">
                                <strong>Please Note:</strong> according to your CNS user status, you will be charged a fee for this training session. See fee prospectus below:
                            </td>
                        </tr>'
                    );

                    $feerows = [];
                    array_push(
                        $feerows,
                        sprintf(
                            '<tr>
                                <th align="right">
                                    Academic with Harvard billing code<br/>
                                    <span class="small-note">(w/out billing code, add 30%%)</span>
                                </th>
                                <td width="20%%">$%01.2f</td>
                            </tr>', 
                            $feesAr[0]
                        )
                    );
                    array_push(
                        $feerows,
                        sprintf(
                            '<tr>
                                <th align="right">ClubNano</th>
                                <td>$%01.2f</td>
                            </tr>',
                            $feesAr[2]
                        )
                    );
                    array_push(
                        $feerows,
                        sprintf(
                            '<tr>
                                <th align="right">Standard Non Academic</th>
                                <td width="20%%" height="25">$%01.2f</td>
                             </tr>',
                            $feesAr[3]
                        )
                    );
                    $feesrow = sprintf(
                        '<tr>
                            <td colspan="2" style="border-top: 1px solid grey; border-bottom: 1px solid grey">
                                <table class="fee-table">
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
                        <td colspan="2" align="center" class="training-note">Please sign up using your CNS user login credentials:</td>
                    </tr>'
                );
                array_push($formrows,
                    '<tr>
                        <td colspan="2" align="center">
                            <table>
                                <tr>
                                    <td>
                                        <table class="fee-table">
                                            <tr>
                                                <th>User Name*</th>
                                                <td width="50%"><input name="txtUsername" type="text" class="form3" id="txtUsername" size="20" /></td>
                                            </tr>
                                            <tr>
                                                <th>Password*</th>
                                                <td><input name="txtPassword" type="password" class="form3" id="txtPassword" size="10" /></td>
                                            </tr>
                                            <tr>
                                                <td>&nbsp;</td>
                                                <td class="small-note">* required</td>
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
                        <td>&nbsp;</td>
                        <td>* <em>required</em> </td>
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
                    <td colspan="2" align="center"><input class="cns-button" type="submit" value="Sign up" /></td>
                </tr>
                '
            );
            $formstr = sprintf(
                '<form id="reg" method="POST" action="" name="reg" onsubmit="return document.MM_returnValue">
                    <table class="form-table">%s</table>
                </form>',
                implode($formrows)
            );
            array_push($trs, sprintf('<tr><td valign="top">%s</td></tr>', $formstr));

        }


    $out = sprintf('<div id="class-list-link"><a href="%s">Back to training</a></div><table class="signup-table">%s</table>', get_permalink($post->ID), implode($trs));
    return $out; 
    } // Training OK

}

// Handle training cancel URL
function training_cancel($db, $params){
    
    $out = "";
    $trs = [];
    $errorMsg = "";

    $rid = $params['rid'];

    if (array_key_exists('docancel', $params)){
        // Go ahead and delete the booking record and associated billing / invoices

        // Setup invoice / billing info
        $opsid = $params['opsID'];
        $invid = 0;
        if ($opsid > 0){
            $result = fetch_row_assoc($db, "SELECT invoiceid FROM cns_billing_ops WHERE Ops_ID = ?", 'i', array($opsid));
            if (count($result) > 0){
                $invid = $result['invoiceid'];
            }
        } else {
            $invid = intval($params['invoiceID']);
        }

        // Delete booking
        $result = exec_sql($db, "DELETE FROM cns_wksbooking WHERE Atn_ID = ?",'i', array($rid)); 

        // Delete invoice
        $result = exec_sql($db, "DELETE FROM cns_invoices WHERE Inv_ID = ?", 'i', array($invid));             
        if ($opsid > 0){
            $result = exec_sql($db, "DELETE FROM cns_billing_ops WHERE Ops_ID = ?", 'i', array($opsid));
        } else {
            $result = exec_sql($db, "DELETE FROM cns_billing_ops WHERE invoiceid = ?", 'i', array($invid));
        }


        
        // Send email confirmation if possible.
        if (!array_key_exists('userEmail', $params) || trim($params['userEmail']) == "" ){
            $errorMsg = "Email address is missing for some reason.  Though your reservation was removed, you will not get a confirmation notice.";
        } else {

            $to         = trim($params['userEmail']);
            $from       = trim($params['contactEmail']);
            $name       = trim($params['userName']);
            $event      = trim($params['evName']);
            $eventdate  = trim($params['evDate']);

            // Email notification 
            send_cancellation_email($to, $from, $name, $event, $eventdate, $invid);
        }
        array_push($trs,
            '<tr>
                <td class="training-note">Your reservation has been canceled.</td>
            </tr>'
        );

    } else {
        // Setup the cancellation form
        $email = $params['em'];
        if (!is_numeric($rid)){
            $errorMsg = "Invalid booking ID";
        }
        if (trim($email) == ""){
            $errorMsg = "Email is empty";
        }

        if ($errorMsg == ""){
            $rid = intval($rid);
            $row_rs = fetch_row_assoc(
                $db, 
                "SELECT cns_wksbooking.*, cns_workshops.zname, cns_workshops.zdate, cns_workshops.start_time, cns_workshops.contactEmail 
                FROM cns_wksbooking, cns_workshops 
                WHERE cns_wksbooking.Atn_ID = ? AND cns_wksbooking.atEmail = ? AND cns_wksbooking.ZID=cns_workshops.Z_ID",
                'is',
                array($rid, $email)
            );
            if (count($row_rs) > 0){
                if ($row_rs['start_time'] > date("Y-m-d H:i:s")){

                    // Display the confirmation form
                    $formrows = [];
                    array_push(
                        $formrows,
                        '<tr>
                            <td class="training-title">Cancel Your Training Reservation</td>
                        </tr>'
                    );
                    array_push(
                        $formrows, 
                        '<input name="docancel" type="hidden" value="yes"/>'
                    );
                    array_push(
                        $formrows, 
                        sprintf('<input name="rid" type="hidden" value="%s"/>',$rid)
                    );
                    array_push(
                        $formrows,
                        sprintf('<input type="hidden" name="userEmail" value="%s"/>',$email)
                    );
                    array_push(
                        $formrows,
                        sprintf('<input type="hidden" name="userName" value="%s"/>', $row_rs['atName'])
                    );
                    array_push(
                        $formrows,
                        sprintf('<input type="hidden" name="opsID" value="%s"/>',$row_rs['opsID'])
                    );
                    array_push(
                        $formrows,
                        sprintf('<input type="hidden" name="invoiceID" value="%s"/>', $row_rs['invoiceID'])
                    );
                    array_push(
                        $formrows,
                        sprintf('<input type="hidden" name="contactEmail" value="%s"/>', $row_rs['contactEmail'])
                    );
                    array_push(
                        $formrows,
                        sprintf('<input type="hidden" name="evDate" value="%s"/>', date("M j, Y", strtotime($row_rs['zdate'])))
                    );
                    array_push(
                        $formrows,
                        sprintf('<input type="hidden" name="evName" value="%s"/>', $row_rs['zname'])
                    );
                    array_push(
                        $formrows,
                        sprintf(
                            '<tr>
                                <td class="training-note">Are you sure you want to cancel your reservation for the &quot;%s&quot; event on %s?</td>
                            </tr>',
                            $row_rs['zname'],
                            date("M j, Y", strtotime($row_rs['zdate']))
                        )
                    );
                    array_push(
                        $formrows,
                        '<tr>
                            <td align="center"><input class="cns-button" type="submit" value="Yes"></td>
                        </tr>'
                    );

                    // Add the form to the output trs
                    array_push(
                        $trs,
                        sprintf(
                            '<tr>
                                <td>
                                    <table>
                                        <form name="delReservation" method="post">
                                            %s
                                        </form>
                                    </table>
                                </td>
                            </tr>',
                            implode($formrows)
                        )
                    );
                }
                else {
                    $errorMsg = "You can NOT cancel past reservations.";
                }
            }
            else {
                $errorMsg = "The reservation you are looking for does NOT exist.";
            }
        }

    }
    if ($errorMsg != ""){
        // If there is an error, put it on the top
        array_unshift($trs, 
            sprintf(
                '<tr>
                    <td class="cns-error">%s</td>
                </tr>'
            , $errorMsg)
        );
    }

    $out = sprintf('<table class="signup-table">%s</table>', implode($trs));
    return $out;
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
        wp_enqueue_script( 'cnswp', get_base_url() . '/cnswp.js');
    }
}
