<?php

$myfile = fopen("listCity.txt", "r") or die("Unable to open file!");
$input = fread($myfile,filesize("listCity.txt"));
//$input=str_replace("</option>","</option>\n",$input);

$input=strip_tags($input);
print_r($input);
fclose($myfile);

?>