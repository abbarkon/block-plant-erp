<?php
$host='localhost'; $db='block_plant'; $user='root'; $pass=''; $charset='utf8mb4';
try{$pdo=new PDO("mysql:host=$host;dbname=$db;charset=$charset",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
catch(PDOException $e){die("Database connection failed: ".htmlspecialchars($e->getMessage()));}
