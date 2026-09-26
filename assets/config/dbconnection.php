<?php
// Enter your Host, username, password, database below.
// I left password empty because i do not set password on localhost.
//$db = mysqli_connect("95.177.167.212","fvacademy_fvacademydemo","Cx@rPWjS5L0=","fvacademy_healthtraining");
$db = mysqli_connect("localhost","olfs_aghulam","SjjJOub8?d3*Iok@","olfs_app");
mysqli_set_charset($db, 'utf8');
// Check connection
if (mysqli_connect_errno())
  {
  echo "Failed to connect to MySQL: " . mysqli_connect_error();
  }

date_default_timezone_set('Asia/Riyadh');

// Turn off error reporting
error_reporting(1);

?>