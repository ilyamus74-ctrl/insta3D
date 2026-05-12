<?php
session_start();

//print_r($_POST);
//echo "NULLL \r\n";
if($_POST['secCode'] == $_SESSION['secCode']){

//echo "fiers \r\n";
//print_r($_POST);
if(empty($_POST['name']) || empty($_POST['subject']) || empty($_POST['message']) ||empty($_POST['secCode'])|| !empty($_POST['email'])) {
    //http_response_code(500);
    //exit();
//    echo "second\r\n";
    $_SESSION['secCode']=md5(microtime(true));
    print_r($_SESSION['secCode']);
	require_once('../../configs/connectDB.php');
//	$sqlUpd="UPDATE `zs_announce_auto` SET `active_announce`='A' $addon  WHERE `id` = '".$dbcnx->real_escape_string($_POST['id'])."' "; 
	$insert="INSERT INTO `renesecKontakt` (`dateInput`,`NameLastName`,`email`,`thema`,`msg`) VALUES (now(),'".$dbcnx->real_escape_string($_POST['name'])."','".$dbcnx->real_escape_string($_POST['subject'])."','".$dbcnx->real_escape_string($_POST['message'])."','".$dbcnx->real_escape_string($_POST['email'])."')";
	//echo $insert;
	$dbcnx->query($insert);
    }

}
else{
  http_response_code(500);

}
/*
$name = strip_tags(htmlspecialchars($_POST['name']));
$email = strip_tags(htmlspecialchars($_POST['email']));
$m_subject = strip_tags(htmlspecialchars($_POST['subject']));
$message = strip_tags(htmlspecialchars($_POST['message']));

$to = "info@example.com"; // Change this email to your //
$subject = "$m_subject:  $name";
$body = "You have received a new message from your website contact form.\n\n"."Here are the details:\n\nName: $name\n\n\nEmail: $email\n\nSubject: $m_subject\n\nMessage: $message";
$header = "From: $email";
$header .= "Reply-To: $email";	

if(!mail($to, $subject, $body, $header))
  http_response_code(500);
*/
?>
