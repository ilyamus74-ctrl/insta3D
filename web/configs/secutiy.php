<?php
session_start();
//if($_SESSION['user']['adminLevel']=="" && $_SERVER['PHP_SELF'] != "/secure.php"){
//header('Location: /secure.php');
//echo "dDDDDD";
//}
//print_r(md5(microtime(true)));
$_SESSION['secCode']=md5(microtime(true));
?>
