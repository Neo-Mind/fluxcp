<?php
if (!defined('FLUX_ROOT')) exit;

$title = 'List Logins';

$sqlpartial = '';
$bind       = array();

$dateAfter  = $params->get('log_after_date');
$dateBefore = $params->get('log_before_date');
$ipAddress  = trim($params->get('ip'));
$username   = trim($params->get('user'));
$logMessage = trim($params->get('log'));
$response   = trim($params->get('rcode'));

if ($dateAfter) {
	$sqlpartial .= 'AND time >= ? ';
	$bind[]      = $dateAfter;
}

if ($dateBefore) {
	$sqlpartial .= 'AND time <= ? ';
	$bind[]      = $dateBefore;
}

if ($ipAddress) {
	$sqlpartial .= 'AND ip LIKE ? ';
	$bind[]      = "%$ip%";
}

if ($username) {
	$sqlpartial .= 'AND user LIKE ? ';
	$bind[]      = "%$username%";
}

if ($logMessage) {
	$sqlpartial .= 'AND log LIKE ? ';
	$bind[]      = "%$logMessage%";
}

if ($response) {
	$sqlpartial .= 'AND response = ? ';
	$bind[]      = $response;
}

$sql = "SELECT COUNT(time) AS total FROM {$server->logsDatabase}.loginlog WHERE 1=1 $sqlpartial";
$sth = $server->connection->getStatementForLogs($sql);
$sth->execute($bind);

$paginator = $this->getPaginator($sth->fetch()->total);
$paginator->setSortableColumns(array(
	'time' => 'desc', 'ip', 'user', 'log', 'rcode'
));

$sql = "SELECT time, ip, user, rcode, log FROM {$server->logsDatabase}.loginlog WHERE 1=1 $sqlpartial";
$sql = $paginator->getSQL($sql);
$sth = $server->connection->getStatementForLogs($sql);
$sth->execute($bind);

$logins = $sth->fetchAll();

if ($logins) {
	$usernames = array();
	foreach ($logins as $_tmplogin) {
		$usernames[] = $_tmplogin->user;
	}
	
	$sql  = "SELECT userid, account_id FROM {$server->loginDatabase}.login WHERE ";
	$sql .= "sex != 'S' AND level >= 0 AND userid IN (".implode(',', array_fill(0, count($usernames), '?')).")";
	$sth  = $server->connection->getStatement($sql);
	$sth->execute($usernames);
	
	$data = $sth->fetchAll();
	if ($data) {
		$accounts = array();
		foreach ($data as $row) {
			$accounts[$row->userid] = $row->account_id;
		}
		
		foreach ($logins as $_tmplogin) {
			if (array_key_exists($_tmplogin->user, $accounts)) {
				$_tmplogin->account_id = $accounts[$_tmplogin->user];
			}
		}
	}
}
?>