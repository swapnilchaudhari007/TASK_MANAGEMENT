<?php
include 'db_connect.php';
$qry = $conn->query("SELECT * FROM project_list where id = ".(int)$_GET['id'])->fetch_array();
foreach($qry as $k => $v){
	$$k = $v;
}
include 'new_project.php';
?>