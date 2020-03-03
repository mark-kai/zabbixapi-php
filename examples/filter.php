<?php
require_once("../src/Zabbixapi.php");

print "Zabbix API Example\n";
print " Connect to API, check certificate/hostname and get number of hosts\n";
print "=====================================================\n";

$zabUrl ='https://my.zabbixurl.com/zabbix';
$zabUser = 'myusername';
$zabPassword = 'mypassword';

$zbx = new ZabbixApi();
try {
	// default is to verify certificate and hostname
	$zbx->login($zabUrl, $zabUser, $zabPassword);
	
	//this is similar to: $result = $zbx->call('apiinfo.version');
	$result = $zbx->getApiVersion();
	print "Remote Zabbix API Version:$result\n";
	
	print "====================================================================\n";	
	// Get hosts and other information available to this useraccount, but filtered and limited
	$limit = 5;
	$params = array(
		'output' => array('hostid', 'host', 'name', 'status', 'maintenance_status', 'description'),
		'filter' => array('status' => 0, 'maintenance_status' => 0, 'type' => 1),
		'selectGroups' => array('groupid', 'name'),
		'selectInterfaces' => array('interfaceid', 'main', 'type', 'useip', 'ip', 'dns', 'port'),
		'selectInventory' => array('os', 'contact', 'location'),
		'selectMacros' => array('macro', 'value'),
		'limit' => $limit
	);

	$result = $zbx->call('host.get',$params);
	print "==== Filtered hostlist with groups and macros ====\n";
	foreach($result as $host) {		
		printf("HostId:%d - Host:%s\n", $host['hostid'], $host['host']);
		foreach($host['groups'] as $group) {
			printf("    - GroupId:%d - Group:%s\n", $group['groupid'], $group['name']);
		}
		foreach($host['macros'] as $macro) {
			printf("    - Macro:%s - Value:%s\n", $macro['macro'], $macro['value']);
		}
		
	}
	
} catch (Exception $e) {
	print "==== Exception ===\n";
	print 'Errorcode: '.$e->getCode()."\n";
	print 'ErrorMessage: '.$e->getMessage()."\n";
	exit;
}