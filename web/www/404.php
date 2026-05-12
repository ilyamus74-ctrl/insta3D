<?php
session_start();
//print_r($_POST);
//print_r($_GET);
//echo "start";

//require('patch.php');


$data['main_text']="<p><span lang='de' dir='ltr'>Entschuldigung. Diese Seite existiert nicht.</span></p> ";
$data['main_text_h1']="Fehler 404";
$data['description']="Fehler 404";
$data['title']="Fehler 404 - CargoCells";
$data['keywords']="Fehler,Fehler 404";

$smarty->assign("data",$data);


//echo "aaaa";
//$smarty->assign("pageView","404");
//$smarty->display("404.html");

echo "404";

?>
