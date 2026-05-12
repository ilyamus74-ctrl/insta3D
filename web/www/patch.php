<?php
session_start();
/*
$smarty->template_dir = "../templates";
$smarty->compile_dir = "../templates_c";
$smarty->config_dir = "../configs";
$smarty->cache_dir = "../cache";
*/
//session_start();

// Используем относительные пути от директории www
$smarty->setTemplateDir('/home/makler/web/templates/');
$smarty->setCompileDir('/home/makler/web/templates_c/');
$smarty->setConfigDir('/home/makler/web/configs/');
$smarty->setCacheDir('/home/makler/web/cache/');
//echo "patch";

?>