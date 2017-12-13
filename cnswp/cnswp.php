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

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

function activate_cns_wp() {
}

function deactivate_cns_wp() {
}

register_activation_hook( __FILE__, 'activate_cns_wp' );
register_deactivation_hook( __FILE__, 'deactivate_cns_wp' );

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

function wdate($dstring){
    $d = strtotime($dstring);
    $w = date('l, F jS, Y',$d);
    return $w;
}

//Login user and get user information
//Username and password should be raw text
function get_user($db, $username, $password, $cnslimited){

    $userinfo = array( 'rOK' => 0, 'eM' => 0);

    $username = $db->escape_string(trim($username));
    $password = crypto("encrypt", getenv("NNIN_CRYPTKEY"), $password);

    // check if user is NNIN registered
    if (getenv("NNIN_DEBUG") != ""){
        $query_rsL = 'SELECT * FROM nnin_login WHERE nnin_username = ?';
        $stm = $db->prepare($query_rsL);
        $stm->bind_param('s', $username);
    } else {
        $query_rsL = 'SELECT * FROM nnin_login WHERE nnin_username = ? AND nnin_pw = ?';
        $stm = $db->prepare($query_rsL);
        $stm->bind_param('ss', $username, $password);
    }
    $rsL = $stm->execute() or die($db->error);
    $row_rsL = $rsL->fetch_assoc();
    $totalRows_rsL = $rsL->num_rows;

    if ($totalRows_rsL > 0) {
        //Legit user, so get information
        $userinfo['username'] = $username;
        $userinfo['uid'] = $row_rsL['usID'];

        $query_rs = "SELECT * FROM nnin_users WHERE id = ? AND active = 1";
        $stm = $db->prepare($query_rs);
        $stm->bind_param('s',$userinfo['uid']);
        $rs = $stm->execute() or die($db->error);
        $row_rs = $rs->fetch_assoc();
        $totalRows_rs = $rs->num_rows;

        if ($totalRows_rs > 0) { 
            $userinfo['fullname'] = sprintf('%s %s', $row_rs['First_Name'], $row_rs['Last_Name']);
            $userinfo['email'] = $row_rs['eMail'];  
            $userinfo['phone'] = $row_rs['Phone'];
 
            if ($cnslimited == 1){
                $query_NF = "SELECT count(TT_ID) as conta FROM cns_trainlkp WHERE traineeid = ? AND toolID=144"; // check if user has NF-05 training
            } elseif ($cnslimited == 2) {
                $query_NF = "SELECT count(TT_ID) as conta FROM cns_trainlkp WHERE traineeid = ? AND (toolID=116 OR toolID=144)"; // check if user has NF-05 training
            }
            $stm = $db->prepare($query_NF);
            $stm->bind_param('i',$userinfo['uid']);
            $NF = $stm->execute() or die($db->error);

            $row_NF = $NF->fetch_assoc();
            $totalRows_NF = $NF->num_rows;
            if (intval($row_NF['conta']) > 0){
                $userinfo['rOK'] = 1;
            } else {
                $userinfo['eM'] = 3;
            }
            mysql_free_result($NF);
        } else {
            $userinfo['eM'] = 3; //Presumably not active
        }
        $rs->free();
    } else {
        $userinfo['eM'] = 4;
    } // end NNIN login check

    return $userinfo;
}


//Figure out what action to take
function handle_url( $atts ){
    $out = "";
    $db = new mysqli(
        getenv("NNIN_HOSTNAME"),
        getenv("NNIN_USERNAME"),
        getenv("NNIN_PASSWORD"),
        getenv("NNIN_DATABASE")
    );
    if ((isset($_POST["MM_insert"])) && ($_POST["MM_insert"] == "reg")) { 
        $out = register_user($db, $_POST);
    }
    else {
        $out = show_training_events($db);
    }
    return $out;
}

// Actually register the user
// Originally from training_reg.php
function register_user($db, $params){


    $cnslimited = intval($db->escape_string($params['CNSlimited']));

    if ($cnslimited > 0 && $username !== ""){
        $userinfo   = get_user($db, $params['txtUsername'], $params['txtPassword'], $cnslimited);
        $uid        = $userinfo['uid'];
        $fullname   = $userinfo['fullname'];
        $email      = $userinfo['email'];
        $phone      = $userinfo['phone'];
        $eM         = $userinfo['eM'];
        $rOK        = $userinfo['rOK'];
    } elseif (isset($params['Full_Name']) && strlen($params['Full_Name']) < 33 && trim($params['Full_Name']) !== "" && trim($params['email']) !== "" && strlen($params['email']) < 33 && trim($params['Phone']) !== "" && strlen($params['Phone']) < 15) {
        $fullname   = $db->escape_string(trim($params['Full_Name']));
        $email      = $db->escape_string(trim($params['email']));
        $phone      = $db->escape_string(trim($params['Phone']));
        $eM         = 0;
        $rOK        = 0;
        $uid        = 0;
    } else {
        header("Location: " . add_query_arg(array('ZID' => 'abcde')));
    }

    //Retrieve documents for this zid
    $zid = $db->escape_string($params['ZID']);
    $stm = $db->prepare("SELECT zdocs from cns_workshops where z_id = ?");
    $stm->bind_param('i',$zid);
    $stm->execute() or die($db->error);
    $rsDocs = $stm->get_result();
    $row_rsDocs = $rsDocs->fetch_assoc();
    $totalRows_rsDocs = $rsDocs->num_rows;

    if (!empty($row_rsDocs['zdocs'])){
        $documents = unserialize($row_rsDocs['zdocs']);
    } else {
        $documents="";
    }

    $out = "";
    if ($rOK == 1 || $cnslimited == 0){
        //Register the user
        $query_ch = "SELECT count(atn_id) as c FROM cns_wksbooking WHERE zid = ?";
        $stm = $db->prepare($query_ch);
        if (!$stm){
            return "That thing failed.--" . $db->error;
        }
        $stm->bind_param('i',$zid);
        $stm->execute() or die($db->error);
        $ch = $stm->get_result();
        $row_ch = $ch->fetch_assoc();
        $totalRows_ch = $ch->num_rows;

        $query_ch2 = 'SELECT count(atn_id) FROM cns_wksbooking WHERE zid = ? and (uid = ? or atemail = ?)';
        $stm = $db->prepare($query_ch2);
        $stm->bind_param('iis',$zid, $uid, $email);
        $stm->execute() or die($db->error);
        $result = $stm->get_result();
        $totalRows_ch2 = $result->fetch_row()[0];
        if ($totalRows_ch2 == 0){
            $go = 1;
        } else {
            $go = 2;
        }


        if ($row_ch['c'] < $params['zmax'] && $go == 1){
            $registration = "OK";
            $stm = $db->prepare('INSERT INTO cns_wksbooking (atname, atemail, atphone, uid, zid) VALUES (?, ?, ?, ?, ?)');
            $stm->bind_param('sssii', $fullname, $email, $phone, $uid, $zid);
            $stm->execute() or die($db->error);

                // mysql_select_db($database_NNIN, $NNIN);
                // $Result1 = mysql_query($insertSQL, $NNIN) or die(mysql_error());
                // $newRID = mysql_insert_id();
                // $updateStrings = "UPDATE cns_wksbooking SET atName = REPLACE(atName,'_',' '), atEmail = REPLACE(atEmail,'...','.') WHERE Atn_ID = ".$newRID."";
                // $Result2 = mysql_query($updateStrings, $NNIN) or die(mysql_error());
            $closeW="yes";
                
            // --- invoicing module ---------------- 
            if($_POST['billThis'] == 1 && $uid > 0){
                // require_once("training_invoicing.php");
            }
            
            // ---------- send eMail --------------------------------------------------
            /* recipients */
            $to  = $email;
            /* subject */
            $subject = sprintf("CNS Training: %s Confirmation Message",$params['event']);

            /* documents message */
            $docmessage = "";
            if (is_array($documents)){ // append links to documents
                $docmessage .= "<br><br>Click the following link(s) to download the related training documentation:<br>";
                foreach ($documents as $d){
                    $docmessage .= sprintf('&#8226; <a href="%s">%s</a><br>',$d,$d);
                }
            }
            $cancelurl = sprintf('http://apps.cns.fas.harvard.edu/users/training_cancel.php?rid=%s&em=%s',$newrid, $email);
            /* message */
            $message = sprintf('Dear %s,<br><br>We received your registration for the following CNS Training Event:<br> <strong>%s on %s"</strong><br><br>To cancel this reservation <a href="%s">click here</a><br><br>Thank you<br><br>The CNS Staff<br><br>________________________________________________<br>This is an automatic CNS email confirmation.', $fullname, $params['event'], $params['when'], $cancelurl);

            
            /* To send HTML mail, you can set the Content-type header. */
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=iso-8859-1\r\n";
            /* additional headers */
            //$headers .= "To: <".$_POST['userEmail'].">\r\n";
            $headers .= "From: CNS Staff <".$emailaddress.">\r\n";
            //$headers .= "Cc: ".$_POST['from']."\r\n";
            $headers .= "Bcc: ".$emailaddress."\r\n";
            /* and now mail it */
            mail($to, $subject, $message, $headers);
                
        } else {
            if ($go == 0){
                $registration = "already registered";
            } elseif ($go == 2){
                $registration = "already registered free";
            } else {
                $registration = "full";
            }
        }
        $out = $registration;
    } else {
        if ($eM == 1){
            $errorMsg="<strong>Your user account is no longer active.<br>Please call 617-496-9632 for support.</strong>";
        }
        if ($eM == 2){
            $errorMsg="<strong>You are NOT a trained LISE cleanroom user.<br>Please call 617-496-9632 for further explanations.</strong><br>";
        }
        if ($eM == 3){
            $errorMsg="<strong>You are a valid CNS user but <u>NOT</u> a trained LISE cleanroom user.<br>You need formal Cleanroom training to attend this event.<br>Please call 617-496-9632 for further explanations.</strong><br>";
        }
        if ($eM == 4){
            $errorMsg="<strong>Either your User Name or Password are incorrect.</strong><br><em>Please try again.</em><br>";
        }
        $out = $errorMsg;
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
    if (isset($_GET['m'])){
        $selectedMonth = $db->real_escape_string($_GET['m']);
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
        $opt = sprintf('<option %s value="%s">%s %s</option>', $selected, add_query_arg( array( 'm' => date("m",$curTime), 'y' => $row_mm['yy'])),  date("F",$curTime), date("Y", $curTime));
        array_push($options, $opt);
    } while ($row_mm = mysqli_fetch_assoc($mm));
    $optstring = implode($options);

    
    // Go through each of the training sessions 
    $c=0;
    $trs = [];
    if ($totalRows_labs > 0){
        do {
            $c++;
            $dateinfo = "";
            if ($row_labs['zdate'] > '0000-00-00'){
                $dateinfo = sprintf('<strong>%s</strong>', wdate($row_labs['zdate']));
            } else {
                $dateinfo = '<strong>Date TBD - </strong><span class="smallblack"><em><strong><font color="#92070A">you can pre-register for this training session</font></strong></em><br>CNS staff will notify you when a date has been set.</span>';
            }
            array_push($trs, sprintf('<tr><td>%s</td></tr>', $dateinfo));

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
                        '<tr><td colspan="5" bgcolor="#B0FFFF"><strong>%s- <em><font color="#92070A">%s</font></em> - %s<br>Trainer: <font color="#92070A">%s %s</font><br></strong>%s</td></tr>',
                        $row_lab['ztype'],
                        $row_lab['zname'],
                        $row_lab['zlocation'],
                        $row_lab['First'],
                        $row_lab['Last'],
                        $desc
                    )
                );

                // Setup the header row
                $userrestrictionheader = '<strong><font color="#009900">Open Event!</font></strong>';
                if ($row_lab['CNSlimited'] == 1){
                    $userrestrictionheader = '<font color="#92070A">CNS Users <strong>ONLY</strong></font>';
                } 
                if ($row_lab['CNSlimited'] == 2) {
                    $userrestrictionheader = '<font color="#92070A">LISE Cleanroom Users <strong>ONLY</strong></font>';
                }
                $tds = [
                    sprintf('<td width="10%%" bgcolor="%s">&nbsp;</td>',$bgcolor),
                    sprintf('<td width="30%%" bgcolor="%s"><strong>Time</strong></td>',$bgcolor),
                    sprintf('<td width="20%%" align="center" bgcolor="%s"><strong>Max Attendees </strong></td>',$bgcolor),
                    sprintf('<td width="20%%" align="center" bgcolor="%s"><strong>Available</strong></td>',$bgcolor),
                    sprintf('<td width="20%%" align="center" bgcolor="#FFFFCC">%s</td>', $userrestrictionheader)
                ];
                array_push($ntrs, sprintf('<tr>%s</tr>', implode($tds)));

                // Data row with registration link
                $time = "";
                $ztime = empty($row_lab['ztime']) ? "" : $row_lab['ztime'];
                $availcolor = $available > 0 ? '#009900' : '#FF0000';
                if ($row_lab['start_time'] > '0000-00-00 00:00:00'){
                    $time = sprintf('%s - %s<br/>%s', date("g:i a",strtotime($row_lab['start_time'])), date("g:i a",strtotime($row_lab['end_time'])), $ztime);
                }
                $regtd = '<strong><font color="#FF0000">Registration Closed</font></strong>';

                if ($available > 0){
                    $reglink = add_query_arg(array( 'ZID' => $row_lab['Z_ID'], 'cEmail' => $row_lab['contactEmail'], 'limited' => $row_lab['CNSlimited'], 'bill' => $billThis, 'toTID' => $toTID));
                    $regtd = sprintf('<a href="%s">%sRegister!</a>', $reglink, $pre);
                }
                $tds = [
                    sprintf('<td width="10%%" bgcolor="%s">&nbsp;</td>',$bgcolor),
                    sprintf('<td width="30%%" bgcolor="%s">%s</td>',$bgcolor,$time),
                    sprintf('<td width="20%%" align="center" bgcolor="%s">%s</td>', $bgcolor, $row_lab['zmax']),
                    sprintf('<td width="20%%" align="center" bgcolor="%s"><strong><font color="%s">%s</font></strong></td>', $bgcolor, $availcolor, $available),
                    sprintf('<td width="20%%" align="center" bgcolor="#FFFFFF">%s</td>',$regtd)
                ];
                array_push($ntrs, sprintf('<tr>%s</tr>', implode($tds)));
                array_push($ntrs, '<tr><td colspan="5" bgcolor="#E1E1E1">&nbsp;</td></tr>');
                
                //Empty row?
                $tds = [
                    sprintf('<td width="10%%" bgcolor="%s">&nbsp;</td>',$bgcolor),
                    sprintf('<td width="30%%" bgcolor="%s">&nbsp;</td>',$bgcolor),
                    sprintf('<td width="20%%" bgcolor="%s">&nbsp;</td>',$bgcolor),
                    sprintf('<td width="20%%" align="center" bgcolor="%s">&nbsp;</td>',$bgcolor),
                    sprintf('<td width="20%%" bgcolor="%s">&nbsp;</td>',$bgcolor),
                ];
                array_push($ntrs, sprintf('<tr>%s</tr>', implode($tds)));

            } while ($row_lab = $lab->fetch_assoc());

            $ntable = sprintf('<table width="100%%" border="0" cellpadding="1" cellspacing="1" class="smallblack">%s</table>',implode($ntrs));
            $tr = sprintf('<tr><td bgcolor="#92070A">%s</td></tr>', $ntable);
            array_push($trs, $tr);
            $tr = '<tr><td>&nbsp;</td></tr>';
            array_push($trs, $tr);
        } while ($row_labs = $labs->fetch_assoc());
    }

    $rowstr = implode($trs);

    $out = <<<EOT
<script language="JavaScript" type="text/JavaScript">
function MM_jumpMenu(targ,selObj,restore){ //v3.0
  eval(targ+".location='"+selObj.options[selObj.selectedIndex].value+"'");
  if (restore) selObj.selectedIndex=0;
}
</script>    
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
            <td align="right" bgcolor="#000000">
                <strong><font color="#FFFFFF">select month:</font></strong>             
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

add_shortcode( 'training_events', 'handle_url' );