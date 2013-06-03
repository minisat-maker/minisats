<?php
include("common.php");
if (!( isset($_REQUEST['id_address']) && isset($_REQUEST['command']) && isset($_REQUEST['signed']))) {
  die("eNot enough vars defined");
}

$id_address = $_REQUEST['id_address'];
$command = $_REQUEST['command'];
$signed = $_REQUEST['signed'];

if (!validate_address($bitcoin,$id_address)) {
  die("eInvalid id_address");
}

if (!verify_message($bitcoin,$id_address,urldecode($signed),$command)) {
  die("eSignature verify failed: ".urldecode($signed));
}

$query = "SELECT * FROM `accounts` WHERE id_address = \"".mysql_real_escape_string($id_address)."\"";
$account_result = mysql_query($query)or die("e".mysql_error());
if (!($account_assoc = mysql_fetch_assoc($account_result))) {
  die("eNo account with that id_address found");
}

$command = explode(",",urldecode($command));
if ($command[0]=="tip") {
  $to_address = $command[1];
  if (!validate_address($bitcoin,$to_address)) {
    die("eInvalid to_address");
  }
  $nsats = abs((int)$command[2]);
  $memo = $command[3];
  if (!deduct_funds($id_address,$nsats+$tip_fee)) {
    die("eDeduction failed");//don't forget the tx fee: if you have 2 BTC you can't actually sent 2 BTC, but instead 1.99999....
  }
  $query = "SELECT * FROM `accounts` WHERE id_address = \"".mysql_real_escape_string($to_address)."\"";
  $result = mysql_query($query)or die("eadd_funds:".mysql_error());
  if (mysql_num_rows($result)==0) {
    add_account($bitcoin,$to_address);
  }
  if (!add_funds($to_address,$nsats)) {
    //this may have failed because of the 18 btc cap
    add_funds($id_address,$nsats+$tip_fee);//send back to id_address before dying and refund fee
    die("eDeposit failed; amount returned to id_address");
  }
  $query = "INSERT INTO `tips` (from_id_address, to_id_address, amount, memo) VALUES (\"".mysql_real_escape_string($id_address)."\",
                                                                                      \"".mysql_real_escape_string($to_address)."\",
                                                                                      ".$nsats.",
                                                                                      \"".mysql_real_escape_string(urldecode($memo))."\")";
  mysql_query($query)or die("e".$query." --- ".mysql_error());
  echo "s".mysql_insert_id();
}
elseif ($command[0]=="withdraw") {
  $amount = $command[1];
  if ($amount=="all") {
    $nsats = bcsub($account_assoc['balance'],$tx_fee_nsats);
  }
  else {
    $nsats = (int)$amount;
  }
  $nsats = nsats_floored_to_satoshi($nsats);//Can't send them everything, but send them everything we can
  if ($nsats <= satoshis_to_nsats(5430)) {//to avoid a nonstandard transaction as defined at https://github.com/bitcoin/bitcoin/pull/2577
    die("eWithdraw too small");
  }
  if (!deduct_funds($id_address,bcadd($nsats,$tx_fee_nsats))) {
    die("eDeduct failed");
  }
  $txid = $bitcoin->sendtoaddress($id_address,nsats_to_btc(bcsub($nsats,$tx_fee_nsats)));
  echo "s".$txid;
}
else {
  echo "eCommand not recognized: ".$command[0];
}
?>