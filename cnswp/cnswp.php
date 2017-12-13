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

//Figure out what action to take
function handle_url( $atts ){
    $out = "";
    $db = mysqli_connect(
        getenv("NNIN_HOSTNAME"),
        getenv("NNIN_USERNAME"),
        getenv("NNIN_PASSWORD"),
        getenv("NNIN_DATABASE")
    );
    if ((isset($_POST["MM_insert"])) && ($_POST["MM_insert"] == "reg")) { 
        $out = register_user($db);
    }
    else {
        $out = show_training_events($db);
    }
    return $out;
}

// Actually register the user
// Originally from training_reg.php
function register_user($db){

    $zid = mysqli_escape_string($db, $_POST['ZID']);
    $stm = mysqli_prepare($db, "SELECT zdocs from cns_workshops where Z_ID = ?");
    $stm->bind_param()
    $query_rsDocs =  ".safestring($_POST['ZID'])."";
    $rsDocs = mysql_query($query_rsDocs, $NNIN) or die(mysql_error());
    $row_rsDocs = mysql_fetch_assoc($rsDocs);
    $totalRows_rsDocs = mysql_num_rows($rsDocs);
    if(!empty($row_rsDocs['zdocs'])){
     $documents = unserialize($row_rsDocs['zdocs']);
    }else{
     $documents="";
    }
    "
}


function show_training_events($db){

    // Leaving out the "davidsHack" piece for now.  Looks like it picks up workshops based on the dedicateLink column value
    // as specified by the "eid" URL parameter
    $eid = 0;
    $davidsHack = "";

    // This gets the month and year from cns_workshops or URL params
    $mm = mysqli_query($db, "SELECT distinct month(zdate) as mm, year(zdate) as yy FROM cns_workshops WHERE zdate >= '" . date("Y-m-d") . "' ORDER BY zdate ASC");
    $row_mm = mysqli_fetch_assoc($mm);
    if (isset($_GET['m'])){
        $selectedMonth = mysqli_real_escape_string($db, $_GET['m']);
    } else {
        $selectedMonth = $row_mm['mm'];
    }

    if (isset($_GET['y'])){
        $selectedYear = mysqli_real_escape_string($db, $_GET['y']);
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
    $labs = mysqli_query($db, $query_labs) or die(mysqli_error());
    $row_labs = mysqli_fetch_assoc($labs);
    $totalRows_labs = mysqli_num_rows($labs);


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
            $lab = mysqli_query($db, $query_lab) or die(mysqli_error());
            $row_lab = mysqli_fetch_assoc($lab);
            $totalRows_lab = mysqli_num_rows($lab); // --------------------------

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
                $cl = mysqli_query($db, $query_cl) or die(mysqli_error());
                $row_cl = mysqli_fetch_assoc($cl);
                $totalRows_cl = mysqli_num_rows($cl);
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

            } while ($row_lab = mysqli_fetch_assoc($lab));

            $ntable = sprintf('<table width="100%%" border="0" cellpadding="1" cellspacing="1" class="smallblack">%s</table>',implode($ntrs));
            $tr = sprintf('<tr><td bgcolor="#92070A">%s</td></tr>', $ntable);
            array_push($trs, $tr);
            $tr = '<tr><td>&nbsp;</td></tr>';
            array_push($trs, $tr);
        } while ($row_labs = mysqli_fetch_assoc($labs));
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