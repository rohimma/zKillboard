<?php

use cvweiss\redistools\RedisQueue;
use cvweiss\redistools\RedisTtlCounter;

require_once "../init.php";

$guzzler = new Guzzler(25, 100);
$rows = $mdb->getCollection("crestmails")->find();
$esimails = $mdb->getCollection("esimails");

$redis->sort("esi2Fetch", ['sort' => 'desc']);

$mdb->set("crestmails", ['processed' => ['$exists' => false]], ['processed' => false], ['multi' => true]);
$mdb->set("crestmails", ['processed' => ['$ne' => true]], ['processed' => false]);

$minute = date("Hi");
while ($minute == date("Hi")) {
    if ($redis->get("tqStatus") != "ONLINE") break;

    $row = $mdb->findDoc("crestmails", ['processed' => false], ['killID' => -1]);
    if ($row != null) {
        $killID = $row['killID'];
        $hash = $row['hash'];

        $mdb->set("crestmails", $row, ['processed' => 'fetching']);

        $url = "https://esi.tech.ccp.is/v1/killmails/$killID/$hash/";
        $params = ['row' => $row, 'mdb' => $mdb, 'redis' => $redis, 'killID' => $killID, 'esimails' => $esimails];
        $guzzler->call($url, "success", "fail", $params);
    }
    $guzzler->tick();
}
$guzzler->finish();

function fail($guzzler, $params, $ex) {
    $row = $params['row'];
    $redis = $params['redis'];

    Util::out("esi fetch failure: ($raw) " . $ex->getMessage());
    Status::addStatus('esi', false);
}

function success(&$guzzler, &$params, &$content) {
    $mdb = $params['mdb'];
    $row = $params['row'];

    $esimails = $params['esimails'];
    $doc = json_decode($content, true);
    $esimails->insert($doc);

    $mdb->set("crestmails", $row, ['processed' => true]);

    $queueProcess = new RedisQueue('queueProcess');
    $queueProcess->push($params['killID']);
    $killsLastHour = new RedisTtlCounter('killsLastHour');
    $killsLastHour->add($row['killID']);

    Status::addStatus('esi', true);
}