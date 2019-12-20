<?php

# provides mysql object
include "mysql.inc.php";

snmp_set_valueretrieval(1);
snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);

$comm = 'SNMPGroup';
$base_oid = '1.3.6.1.2.1.1.1.0';
$vendor_oid = '1.3.6.1.2.1.1.2.0';
$uptime_oid = '1.3.6.1.2.1.1.3.0';

$calc_uptime = 0;
$output = '';

$cisco = array(
   
    'cong_drops' => '1.3.6.1.4.1.9.9.276.1.1.1.1.11',
    'avg_rx' => '1.3.6.1.4.1.9.2.2.1.1.6',
    'avg_tx' => '1.3.6.1.4.1.9.2.2.1.1.8',
    'lldp_neigh' => '1.0.8802.1.1.2.1.4.1.1.9',
); 

$extreme = array(
    
    'stack_ports' => '1.3.6.1.4.1.1916.1.33.3.1.4',
    'memory' => '1.3.6.1.4.1.1916.1.32.2.2.1',
    'cpu' => '1.3.6.1.4.1.1916.1.32.1.4.1',
    'slots' => '1.3.6.1.4.1.1916.1.1.2.2.1',
    'cong_drops' => '1.3.6.1.4.1.1916.1.4.14.1.1',
    #'avg_tx' => '1.3.6.1.4.1.1916.1.4.5.1.1',
    #'avg_rx' => '1.3.6.1.4.1.1916.1.4.5.1.2',
    'avg_tx' => '1.3.6.1.4.1.1916.1.4.11.1.5',
    'avg_rx' => '1.3.6.1.4.1.1916.1.4.11.1.6',
    'edp_neigh' => '1.3.6.1.4.1.1916.1.13.2.1.3',
);

$rfc_constant = array(
	'int_status_1' => 'up',
    'int_status_2' => 'down',
    'int_status_6' => 'not present',
);


function check_status($device){
    # status check of the device to prevent internal DOS on SNMP or multiple requests at same time
    # statsu 1: no snmp found on device
    # status 0: added
    # status 1: polling
    # status 2: polled, timestamp @updated_at
    global $mysqli;
    if (!($stmt = $mysqli->prepare("select *, TIMESTAMPDIFF(MINUTE, `updated_at`, CURRENT_TIMESTAMP()) as timedelta from devices where name = ?"))) {
        echo "Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error;
        exit();
    }
    if (!$stmt->bind_param('s', $device)) {
        echo "Binding parameters failed: (" . $stmt->errno . ") " . $stmt->error;
        exit();
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_array();

    if (!$row){
        #echo "device not listed yet, addded<br />";
        $ins_stmt = $mysqli->prepare("insert into devices (name, status) values (?, 0)");
        if (!$ins_stmt) { echo "Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error; }
        $ins_stmt->bind_param('s', $device);
        $ins_stmt->execute();
        $status = 0;
    } else {
        if ($row['status'] == 1){
            $status = 1;
        }
        # if status was polled, check the time delta before repolling
        if ($row['status'] == 2){
        	#echo "timedelta " . $row['timedelta'] . "<br />";
            if ($row['timedelta'] < 5 ){
            	#echo "device polled " . $row['timedelta'] . ", cool down phase<br />";
                $status = 2;
            } else {
                $status = 0;
            }
        }
    }
    return $status;
} // check_status

# update the status to prevent DOS on the device
function update_status($device, $status){
    global $mysqli;
    if (!($stmt = $mysqli->prepare("update devices set status = ? where name = ?"))) {
        echo "Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error;
        exit();
    }
    if (!($stmt->bind_param('ds', $status, $device ))) {
        echo "Binding parameters failed: (" . $stmt->errno . ") " . $stmt->error;
        exit();
    }
    $stmt->execute();

}


function _snmp_get($oid){
    global $host, $comm;
    return snmp2_get($host, $comm, $oid);
}


function _snmp_walk($oid){
    global $host, $comm;
    $res = snmp2_real_walk($host, $comm, $oid);
    #var_dump($res);
    return $res;

}


function rfc_interfaces(){
	global $system_uptime, $calc_uptime;
    $base = '1.3.6.1.2.1.2.2.1.';
    $ext = array(
        'ifIndex' => $base . '1',
        'ifDescr' => '1.3.6.1.2.1.31.1.1.1.1',
        #'ifDescr' => $base . '2',
        'ifMtu' => $base . '4',
        'ifSpeed' => $base . '5', # 1.3.6.1.2.1.31.1.1.1.15 could be a better on, based on MBPS
        'ifAdmin' => $base . '7',
        'ifStatus' => $base . '8',
        'ifInErrors' => $base . '14',
        'ifOutErrors' => $base . '20',
        'ifDisplay' => '1.3.6.1.2.1.31.1.1.1.18',
        'ifStatusChange' => '1.3.6.1.2.1.2.2.1.9',
    );

    foreach($ext as $k => $v){
            foreach(_snmp_walk($v) as $idx => $val){
            	# .1.3.6.1.2.1.2.2.1.2.20 -> ifindex 20
            	$tmp = explode(".", $idx);
            	$ifindex = end($tmp);
                $res[$ifindex][$k] = $val;
                if ($k == 'ifStatusChange' && !$calc_uptime && $val > $system_uptime  ){
                	$calc_uptime = $system_uptime + pow(2,32);
                }    
            };
    }
    if ($calc_uptime == 0){
    	$calc_uptime = $system_uptime;
    }
    return $res;
}


function dotheshit($device){
	#$output .= $device . "<br />";
	# set device status to be polled
	update_status($device, 1);
    # mark device as polled
    update_status($device, 2);
    return $device;
}



if ( !isset($_GET['host']) ){
	$output .= '
	<form method="get">
	<div class="form-group">
    <label for="host">device name</label>
    <input class="form-control input-lg" id="host" name="host" type="text">
  </div>
</form>
	';
	@include("layout.inc.php");
	exit();
}



$host = mb_strtolower(trim($_GET['host']));

# force update with status=0
if ( !isset($_GET['status']) ){
	$status = check_status($host);
} else {
	$status = $_GET['status'];
}

#$output .= "status: " . $status ."<br />";

if ($status == -1){
	echo "device failed on last fetch<br />";
	exit();
}
if ($status == 0){
	#echo "device added to poll<br />";
	dotheshit($host);
}
if ($status == 1){
	echo "device now polling, report will be <a href=\"out/" . mb_strtolower($host) . ".html\">here</a><br />";
	exit();
}
if ($status == 2){
	echo "device polled, cool down phase, <a href=\"out/" . mb_strtolower($host) . ".html\">output of last check</a><br />";
	exit();
}

$output .= "Status date: " . date(DATE_ATOM);
$output .= ', <a href="out/' . mb_strtolower($host) . '.html">report file</a><br />';
$output .= "Device: " . mb_strtoupper($host) . "<br />";








$vendor_check = _snmp_get($vendor_oid);
$system_uptime = _snmp_get($uptime_oid);



if ($vendor_check == false ){
    echo "fail in checking ....";
    exit();
}

# interfaces are RFC1213 same for any device
$interfaces = rfc_interfaces();

# cisco special
if ( strpos($vendor_check, '1.3.6.1.4.1.9') !== false){
    $vendor = 'cisco';
    foreach(_snmp_walk($cisco['cong_drops']) as $k => $v){
    	$tmp = explode('.', $k);
    	$ifindex = end($tmp);
    	$interfaces[$ifindex]['cong_drops'] = $v;
        #$interfaces[$k + $offset]['cong_drops'] = $v;
    }

    foreach(_snmp_walk($cisco['avg_rx']) as $k => $v){
    	$tmp = explode('.', $k);
    	$ifindex = end($tmp);
        $interfaces[$ifindex]['avg_rx'] = $v;
    }
    foreach(_snmp_walk($cisco['avg_tx']) as $k => $v){
    	$tmp = explode('.', $k);
    	$ifindex = end($tmp);
        $interfaces[$ifindex]['avg_tx'] = $v;
    }

    foreach(_snmp_walk($cisco['lldp_neigh']) as $k => $v){
    	$ifindex = explode('.', $k)[13];
        $interfaces[$ifindex]['neigh'] = $v ."(wrong interface TODO)";
    }


}

# extreme specific stuff
if ( strpos($vendor_check, '1.3.6.1.4.1.1916') !== false){
    $vendor = 'extreme';
    include "extreme-models.php";
    $hardware = _snmp_walk($extreme['slots']);
    for ($i = 1; $i <= 8; $i++){
        $slots[$i]['name'] = $hardware[".1.3.6.1.4.1.1916.1.1.2.2.1.2." . $i];
        $slots[$i]['model'] = $hardware[".1.3.6.1.4.1.1916.1.1.2.2.1.3." . $i];
        $slots[$i]['model-name'] = $models[$slots[$i]['model']];
        $slots[$i]['status'] = $hardware[".1.3.6.1.4.1.1916.1.1.2.2.1.5." . $i];
        $slots[$i]['serial'] = $hardware[".1.3.6.1.4.1.1916.1.1.2.2.1.6." . $i];
    }
    
    $offset = 0;
/*
    if ($interfaces[0]['ifDescr'] == 'Management Port'){
        $interfaces[0]['cong_drops'] = 0;
        $interfaces[0]['avg_rx'] = 0;
        $interfaces[0]['avg_tx'] = 0;
        $offset = 1;
    }    
*/
    foreach(_snmp_walk($extreme['cong_drops']) as $k => $v){
    	$tmp = explode('.', $k);
    	$ifindex = end($tmp);
    	$interfaces[$ifindex]['cong_drops'] = $v;
    }

    foreach(_snmp_walk($extreme['avg_rx']) as $k => $v){
    	$tmp = explode('.', $k);
    	$ifindex = end($tmp);
        $interfaces[$ifindex]['avg_rx'] = $v*8;
    }

    
    foreach(_snmp_walk($extreme['avg_tx']) as $k => $v){
    	$tmp = explode('.', $k);
    	$ifindex = end($tmp);
        $interfaces[$ifindex]['avg_tx'] = $v*8;
    }

    foreach(_snmp_walk($extreme['edp_neigh']) as $k => $v){
    	$ifindex = explode('.', $k)[13];
        $interfaces[$ifindex]['neigh'] = $v;
    }
    
}

#var_dump($interfaces);

$output .= $vendor . '<br />';
$output .= 'uptime at least ' . number_format(($calc_uptime/100)/86400) . " days";
if ( $calc_uptime > $system_uptime) {
    	$output .= " (1 clock overflow seen)";
}
$output .= "<br />";

# extreme slots
if ($vendor == 'extreme' && isset($slots)){
    $output .= "
    <table>
        <tr>
            <th>Slot</th>
            <th>Model</th>
            <th>Status</th>
            <th>Serial</th>
        </tr>
    ";
    foreach($slots as $k => $v){
        if ($v['model'] != 1) {
            $output .= "<tr>";
            $output .= "<td>" . $v['name'] . "</td>";
            $output .= "<td>" . $v['model-name'] . "</td>";
            $output .= "<td>" . $slot['status'][$v['status']] . "</td>";
            $output .= "<td>" . $v['serial'] . "</td>";
            $output .= "</tr>";
        }
    }
    $output .= "</table>";
}  // extreme stack


$output .= '<table class="table-dark table-striped table-bordered table-hover table-sm">';
$output .= "<thead ><tr><th>Interface</th>
        <th>status</th>
        <th>last changed<br />days ago</td>
        <th>speed</th>
        <th>MTU</th>
        <th>errors<br />rx/tx</th>
        <th>drops</th>";

$output .= "<th>utilization<br />rx/tx (bps)</th>";
$output .= "<th>neighbor</th>";
$output .= "</tr></thead><tbody>";

foreach($interfaces as $k => $v){
    $output .= "<tr";
    if ($v['ifAdmin'] == 2){
    	$output .= ' class="text-secondary	"';
    }
    $output .= "><td>" . $v['ifDescr'];
    # (" . $v['ifIndex'] . ")
    $output .= "<br /><small>" . $v['ifDisplay']  ."</small></td>";
    $output .= "<td";
    if ($v['ifAdmin'] == 2){
    	$output .= '>admin down';
    } else {
        $output .= '>' . $rfc_constant['int_status_'.$v['ifStatus']];
    }
    $output .= "</td>";


	$output .= "<td>";
	# if change happened before overflow, to some math
	if ( $v['ifStatusChange'] > $system_uptime) {
		$change_calc = ( $system_uptime + pow(2,32) - $v['ifStatusChange'] ) / 100 / 86400;
	} else {
		$change_calc =  ($system_uptime - $v['ifStatusChange'])/100/86400;
	}
    $output .= number_format($change_calc,2);

	$output .= "</td>";


    $output .= "<td><small>";
    switch($v['ifSpeed']) {
        #case '10000000':
        #    $output .= "10M";
        #    break;
        #case '100000000':
        #    $output .= "100M";
        #    break;
        #case '1000000000':
        #    $output .= "1G";
        #    break;
        #case '10000000000':
        #    $output .= "10G";
        #    break;
        case '4294967295': # cisco 10G
        	$output .= "10G";
        	break;
        default:
		    $speed = str_replace(
		    	array("000000000", "000000", "000"),
		    	array("G", "M", "K"),
		    	strrev($v['ifSpeed'])
		    );
		    #var_dump($speed);
        	$output  .= strrev($speed);
        	break;
    }

    $output .= "</small></td>";

    $output .= "<td><small>" . $v['ifMtu'] . "</small></td>";

    $output .= "<td>" ;
    if (isset($v['ifInErrors'])){
        $output .= number_format($v['ifInErrors']);
    } else {
        $output .= '0';
    }
    $output .= "&nbsp;/&nbsp;";
    if (isset($v['ifOutErrors'])) {
        $output .= number_format($v['ifOutErrors']);
    } else {
        $output .= '0';
    }
    $output .= "</td>";

    $output .= "<td>";
    if (isset($v['cong_drops'])){
        $output .= number_format($v['cong_drops']);
    }
    $output .= "</td>";    


    $output .= "<td>";
    if (isset($v['avg_rx']) || isset($v['avg_tx'])){
        if (isset($v['avg_rx'])){
            $output .= number_format($v['avg_rx']);
        }
        $output .= '&nbsp;/&nbsp;';
        if (isset($v['avg_tx'])){
            $output .= number_format($v['avg_tx']);
        }
    }
    $output .= "</td>";   

    $output .= "<td>";
    if (isset($v['neigh'])){
            $output .= $v['neigh'];
    }
    $output .= "</td>";
    
    $output .= "</tr>";
}
$output .= "</tbody></table>";


$output .= "<br />";

file_put_contents("out/" . mb_strtolower($host) . ".html", $output);
@include("layout.inc.php");
?>
