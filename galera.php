<?php

function convertToSecs($str) { 
    $exp = explode(',',$str);
    $total_uptime = 0;
    foreach ( $exp as $e => $f ) { 
        $val = explode(' ',$f);
        if ( $val[1] =='Day' and $val[0]>0) {
            $total_uptime += $val[0] * 86400 ;
        } elseif ( $val[1] =='Hour' and $val[0]>0 ) { 
            $total_uptime += $val[0] * 3600 ;
        } elseif ( $val[1] =='Min' and $val[0]>0 ) { 
            $total_uptime += $val[0] * 60 ;
        } elseif ( $val[1] =='Sec' and $val[0]>0 ) { 
            $total_uptime += $val[0] ;
        }
    }
    return $total_uptime;
}

$conf = parse_ini_file("nodes.conf", true);
$nodes_item = $conf["nodes"];
$access = $conf["access"];

$node = array();
foreach ($nodes_item as $key => $host) {

    $dict_wsrep = array();
    $dict_wsrep['active'] = 'OK';
    $dict_wsrep['hostname'] = $host;
    $dict_wsrep['wsrep_local_state'] = 0;
    $dict_wsrep['wsrep_local_state_comment'] = 'Error';

    $cnx = new mysqli($host, $access['user'], $access['pass'], 'mysql', $access['port']);
    if ($cnx->connect_errno) {
        $dict_wsrep['wsrep_local_state_comment'] = mb_strcut($cnx->connect_error, 0, 10);
        $dict_wsrep['active'] = 'NO';
        $node[] = $dict_wsrep;
        continue;
    }

    $result = $cnx->query("SELECT @@hostname as hostname;");
    if ($result->num_rows > 0){
        $row = @$result->fetch_assoc();
        $dict_wsrep['hostname'] = $row['hostname'];
    }
    
    $result = $cnx->query("SHOW STATUS LIKE 'wsrep%';");
    if ($result->num_rows > 0){
        while ($row = @$result->fetch_row()) {
            $dict_wsrep[$row[0]] = $row[1];
        }
    }
    $result = $cnx->query("SHOW VARIABLES LIKE 'wsrep%';");
    if ($result->num_rows > 0){
        while ($row = @$result->fetch_row()) {
            $dict_wsrep[$row[0]] = $row[1];
        }
    }

    $result = $cnx->query("SHOW GLOBAL STATUS;");
    if ($result->num_rows > 0){
        while ($row = @$result->fetch_row()) {
            if ( $row[0] == 'Uptime') {
                $dict_wsrep['UptimeSeconds'] = $row[1];
                $dtF = new \DateTime('@0');
                $dtT = new \DateTime("@$row[1]");
                $date_string = $dtF->diff($dtT)->format('%a Day,%h Hour,%i Min,%s Sec');
                $row[1] = $date_string;
            }
            $dict_wsrep[$row[0]] = $row[1];
        }
    }
    
    $cnx->close();

    $node[] = $dict_wsrep;
}
?>
<html>
<head>
    <style type="text/css">
        table { 
            border:1px solid #cc00cc;
        }
        table th { 
            background-color:#0a46a8;
            color:#fff;
            font-weight:bold;
            padding:3px;
            font-size:12px;
        }
        table td { 
            background-color:#e1e6ef;
            color:#000;
            padding:3px;
            font-size:12px;
        }
        .resp { 
            font-weight:bold;
            color:#3d9105;
        }
        .resp-alert { 
            font-weight:bold;
            color:#e8eaed;
            background-color:#e80b0b;
        }
    </style>
</head>
<body>
<table>
    <tr>
        <th>
            Instance 
        </th>
        <th>
            MySQL Status
        </th>
        <th colspan="3">
            Galera Stats
        </th>
        <th>
            Server Stats
        </th>
        <th width="20%">
            Overall State 
        </th>
    </tr>
<?php
//print_r($node);
foreach ( $node as $n=>$d) { 
    $overallState = array();
    $totalWrites = $d['Com_insert']+$d['Com_insert_select']+$d['Com_update']+$d['Com_update_multi'];
    $totalReads = $d['Com_select'];
    $totalWritePerSeconds = round ( $totalWrites / $d['UptimeSeconds'] , 2 ); 
    $totalReadPerSeconds = round ( $totalReads / $d['UptimeSeconds'] , 2 )  ; 

    
    $pItems = explode(";",$d['wsrep_provider_options']);
    
    $pOptions = array();
    foreach ( $pItems as $v ) { 
        $vItem = explode("=",$v);
        $pOptions[trim($vItem[0])] = $vItem[1];
    }
    
    if ( $d['wsrep_node_address'] == '' ) { 
        $overallState[] = 'Node Connectivity Failed ! ';
    }

    if ( $d['wsrep_local_state'] !=4 ) { 
        $overallState[] = 'Node Cluster State is invalid' ;
    }

    if ( $d['wsrep_local_recv_queue_avg'] > 0.001 ) { 
        $overallState[] = 'Node Operate Too slow replace or desync ' ;
    }

    if ( $d['wsrep_local_send_queue_avg'] > 0.001 ) { 
        $overallState[] = 'Node has serious network issue ' ;
    }

    if ( $d['wsrep_flow_control_paused'] > 0.001 ) { 
        $overallState[] = 'Node has serious IO problem ' ;
    }

    if ( $d['wsrep_cert_deps_distance'] > $d['wsrep_slave_threads'] ) { 
        $overallState[] = 'Node need more slave_threads ' ;
    }
    if ( $d['wsrep_cluster_status'] != 'Primary') { 
        $overallState[] = 'Node was in inconsistent(non-Primary) cluster ';
    }

    if ( $d['UptimeSeconds'] < 86400 ) { 
        $overallState[] = '<b>Node has uptime lower than 24 hours and alerts may be false</b>';
    }

    if ( count($overallState) == 0 ) { 
        $overallState[] = 'OK';
    }
    echo "
    <tr>
        <td>
            {$d['hostname']}
            <br />
            [<span class='resp'> {$d['wsrep_node_address']} </span>]
        </td>
        <td>
            <b>State </b>: <br /><span class='resp'> {$d['wsrep_local_state_comment']} </span> [ {$d['wsrep_local_state']} ]  <br />
            {$d['active']} <br />
            Port : 3306 
        </td>
        <td>
            wsrep_local_send_queue : 
            ".$d['wsrep_local_send_queue']." | 
                <span class='resp".($d['wsrep_local_send_queue_avg'] > 0.09 ? '-alert' : '')."'> {$d['wsrep_local_send_queue_avg']}</span>  <br />
            wsrep_local_recv_queue : 
            ".$d['wsrep_local_recv_queue']." | 
                <span class='resp".($d['wsrep_local_recv_queue_avg'] > 0.09 ? '-alert' : '')."'> {$d['wsrep_local_recv_queue_avg']}</span>  <br />
            
            wsrep_cert_deps_distance : <span class='resp'> {$d['wsrep_cert_deps_distance']}</span>  <br />
            wsrep_last_committed : <span class='resp'> {$d['wsrep_last_committed']}</span>  <br />

        </td>
        <td>
            wsrep_flow_control_sent : [<a href='#modal'>~</a>] <span class='resp'> {$d['wsrep_flow_control_sent']}</span>  <br />
            wsrep_flow_control_recv : <span class='resp'> {$d['wsrep_flow_control_recv']}</span>  <br />
            wsrep_flow_control_paused : <span class='resp".($d['wsrep_flow_control_paused'] > 0.09 ? '-alert' : '')."'> {$d['wsrep_flow_control_paused']}</span>  <br />
            wsrep_ready : <span class='resp'> {$d['wsrep_ready']}</span>  <br />
        </td>
        <td>
            
            
            gcache.page_size : <span class='resp'>".$pOptions['gcache.page_size']."</span> <br />
            gcache.size : <span class='resp'>".$pOptions['gcache.size']."</span> <br />
            gcs.fc_factor : <span class='resp'>".$pOptions['gcs.fc_factor']."</span> <br />
            gcs.fc_limit : <span class='resp'>".$pOptions['gcs.fc_limit']."</span> <br />
            

        </td>
        <td>
            Queries /s : <span class='resp'>".round($d['Queries']/$d['UptimeSeconds'],2)."</span>  <br /> 
            Questions /s :  <span class='resp'>".round($d['Questions']/$d['UptimeSeconds'],2)."</span>  <br /> 
            

            Total Writes /s : <span class='resp'>".$totalWritePerSeconds."</span> <br />
            Total Reads /s : <span class='resp'>".$totalReadPerSeconds."</span>
            

        </td>
        <td>
            <ul><li>
        ".implode('</li><li>',$overallState)."
            </li></lul>
        </td>
    </tr>
    ";
    //var_dump($d);
}

?>
</table>
</body>
</html>