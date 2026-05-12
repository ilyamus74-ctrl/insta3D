<?php
session_start();
//print_r($_SERVER['GET']);
//echo "start";
/*
include_once("setlocale/locale.php");
require_once("../../libs/Smarty.class.php");
//$smarty = new Smarty;
$smarty = new \Smarty\Smarty;

require_once("../patch.php");
$theme="/templates";
//$smarty->force_compile = true;
$smarty->debugging = false;
$smarty->caching = false;
$smarty->cache_lifetime = 0;
//$smarty->setErrorReporting(E_ALL & ~E_WARNING & ~E_NOTICE);
$smarty->assign("THEME",$theme);

$smarty->assign("xlang",$_SESSION['locale_c']);
*/
//#include_once("config.php");
//#include_once("../connect_opp.php");
print_r($_SERVER);
//print_r($_SESSION);
/*
if(!empty($_SESSION['admin_user']['id'])){
    $smarty->assign("SESSION",$_SESSION);
    $smarty->display('NiceAdmin/index.html');
}
else{
    header("Location: /ABRA");
}
*/
//$url_razborka=explode("/",$_SERVER['REQUEST_URI']);

//print_r($url_razborka);
//	phpinfo();
/*
if(empty($_SESSION['user']))
	{
//	if($_SERVER['PHP_SELF'] != $_SERVER['REQUEST_URI']) 	header("Location: ".$_SESSION['domain_name']."/index.php");

//	$smarty->assign(SESSION,$_SESSION);
//	$smarty->display('pages-login.html');
	
	if(in_array('aboutus', $url_razborka, true)){
//	echo "PROVIDER";
		include_once("aboutus.php");
//	phpinfo();
	}
//	print_r($_SERVER);

	else if(in_array('index.html', $url_razborka, true)){
	//echo "PROVIDER";
		include_once("main.php");
	}
	else if(in_array('service', $url_razborka, true)){
	//echo "PROVIDER";
		include_once("service.php");
	}
	else if(in_array('all-cars', $url_razborka, true)){
	//echo "PROVIDER";
		include_once("all-cars.php");
	}
	else if(in_array('new-cars', $url_razborka, true)){
	//echo "PROVIDER";
		include_once("new-cars.php");
	}
	else if(in_array('contact', $url_razborka, true)){
	//echo "PROVIDER";
		include_once("contact.php");
	}
	else if(in_array('ABRA', $url_razborka, true)){
	//echo "PROVIDER";
		include_once("abra.php");
	}
	else if(in_array('zvidki-deshevshe-prignati-avto.html', $url_razborka, true)){
	//echo "PROVIDER";
		include_once("zvidki-deshevshe-prignati-avto.php");
	}
	else if(in_array('kupit-avto-dlya-zsu-nedorogo.html', $url_razborka, true)){
	//echo "PROVIDER";
		include_once("kupit-avto-dlya-zsu-nedorogo.php");
	}
	else if(in_array('poshuk-i-pokupka-avto-dlya-zsu.html', $url_razborka, true)){
	//echo "PROVIDER";
		include_once("poshuk-i-pokupka-avto-dlya-zsu.php");
	}
	else if(in_array('prodag-ta-kupivlya-avto-dlya-zsu.html', $url_razborka, true)){
	//echo "PROVIDER";
		include_once("prodag-ta-kupivlya-avto-dlya-zsu.php");
	}
	else if(in_array('avto-dlya-zsu.html', $url_razborka, true)){
	//echo "PROVIDER";
		include_once("avto-dlya-zsu.php");
	}
	else if(!empty($url_razborka[1])){
		//include_once("404.php");
		header('Location: /404.php');
	}
	else {include('main.php');}

	}

elseif(!empty($_SESSION['user'])){
//	$_SESSION['control_short_hash']=md5(microtime(true));
	$smarty->assign("SESSION",$_SESSION);
	include('main.php');

//	перенес во вложенные файлики
	}

else 	{
//	echo "c $test";
	include_once('404.php');
	//echo "c";
	 }
//	echo"d";



//	include('404.php');
*/

?>
