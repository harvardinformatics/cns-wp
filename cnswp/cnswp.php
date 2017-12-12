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

function training_events( $atts ){


    if (isset($_GET['m'])){
        $thisMonth = mysql_real_escape_string($_GET['m']);
    } else {
        $thisMonth = date("m",strtotime(date("Y-m-d")));
    }
    if (isset($_GET['y'])){
        $thisYear = mysql_real_escape_string($_GET['y']);
    } else {
        $thisYear = date("Y",strtotime(date("Y-m-d")));
    }

    // This gets the month and year from cns_workshops
    $db = mysqli_connect(
        getenv("NNIN_HOSTNAME"),
        getenv("NNIN_USERNAME"),
        getenv("NNIN_PASSWORD"),
        getenv("NNIN_DATABASE")
    );

    // Leaving out the "davidsHack" piece for now.  Looks like it picks up workshops based on the dedicateLink column value
    // as specified by the "eid" URL parameter
    $eid = 0;
    $davidsHack = "";


    $mm = mysqli_query($db, "SELECT distinct month(zdate) as mm, year(zdate) as yy FROM cns_workshops WHERE zdate >= '" . date("Y-m-d") . "' ORDER BY zdate ASC");
    $row_mm = mysqli_fetch_assoc($mm);
    if (!isset($_GET['m'])){
        $tm = $row_mm['mm'];
    } else {
        $tm = intval($_GET['m']);
    }


    $view = "notall";
    if(isset($_GET['view']) && $_GET['view'] == "all"){
        $view = "all";
    }

    if ($view == "all"){
        $query_labs = "SELECT distinct zdate FROM cns_workshops WHERE zdate >= '" . date("Y-m-d") . "' OR zdate = '0000-00-00' ORDER BY zdate ASC, ztype ASC";
        $labs = mysqli_query($db, $query_labs) or die(mysqli_error());
        $row_labs = mysqli_fetch_assoc($labs);
    } else {
        if( $eid > 0 ){ ///-------------> special David Bell Hack
            $query_labs = "SELECT distinct zdate FROM cns_workshops WHERE " . $davidsHack . " ORDER BY zdate ASC, ztype ASC";
            $labs = mysqli_query($db, $query_labs) or die(mysqli_error());
            $row_labs = mysqli_fetch_assoc($labs);
        } else {
            $query_labs = "SELECT distinct zdate FROM cns_workshops WHERE zdate >= '" . date("Y-m-d") . "' AND month(zdate) = " . intval($tm) . " AND year(zdate) = " . intval($thisYear) . " OR zdate = '0000-00-00' ORDER BY zdate ASC, ztype ASC";
            $labs = mysqli_query($db, $query_labs) or die(mysqli_error());
            $row_labs = mysqli_fetch_assoc($labs);
        }
    }

    //Get Month Year selection options
    $options = [];
    $optstring = "";
    do { 
        $curTime = mktime(0,0,0,$row_mm['mm'],1,$row_mm['yy']);
        $selected = "";
        if ($curTime == mktime(0,0,0,intval($thisMonth),1,$thisYear)){
            $selected = "selected";
        }
        $opt = '<option ' . $selected . ' value="training_events.php?m=' .  date("m",$curTime) . '&y=' . $row_mm['yy'] . '>' . date("F",$curTime) . ' ' . date("Y", $curTime) . '</option>';
        array_push($options, $opt);
    } while ($row_mm = mysqli_fetch_assoc($mm));

    $optstring = implode($options);

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
            <td align="right" bgcolor="#000000">
                <strong><font color="#FFFFFF">select month:</font></strong>             
                <select name="jumpMenu" class="form3" id="jumpMenu" onChange="MM_jumpMenu('parent',this,0)">
                    $optstring
                </select>
            </td>
        </tr>
    </form>                                      
</table>
EOT;


    return $out;
}

add_shortcode( 'training_events', 'training_events' );