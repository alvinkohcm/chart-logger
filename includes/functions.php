<?php

ini_set("display_errors",true);

include(__DIR__."/../../_settings/config.php");
include(__DIR__."/../classes/ChartLogger.class.php");
include(__DIR__."/../classes/Symbol.class.php");
include(__DIR__."/composer/vendor/autoload.php");

/******************************************************************************
* PDO DATABASE
******************************************************************************/
try
{
 $PDO = new PDO("mysql:dbname={$database[database]}; host={$database[host]}",
                $database[username],
                $database[password]);
}
catch (PDOException $e)
{
 echo "Database Connection Error: " . $e->getMessage();
}

/******************************************************************************
* DATAFEED DATABASE
******************************************************************************/
try
{
 $PDO_DATAFEED = new PDO("mysql:dbname={$datafeed[database]}; host={$datafeed[host]}",
                $datafeed[username],
                $datafeed[password]);
}
catch (PDOException $e)
{
 echo "Database Connection Error: " . $e->getMessage();
}

/******************************************************************************
* TEMPLATE
******************************************************************************/
$template = new Smarty();

/******************************************************************************
* FUNCTIONS
******************************************************************************/
function requireRotation($PDO)
{
 $query = "SELECT IF (NOW() > STR_TO_DATE(value,'%Y-%m-%d'),1,0) AS require_rotation FROM cron WHERE cronid = 'rotationdate'";
 $row = $PDO->query($query)->fetch();

 return $row[require_rotation] == 0 ? false : true; 
}

//-----------------------------------------------------------------------------
function rotatePartitions($PDO, $tablename, $prune_days = 30, $placeholder_days = 3)
{
 $range = array();
 $query = "SELECT UTC_DATE() AS utcdate"; 
 $row = $PDO->query($query)->fetch();
 $today = $row[utcdate];
 
 //### DETERMINE PARTITIONS TO RETAIN/CREATE
 //Note: prune_days <- [TODAY] -> placeholderdays
 $parts = explode("-",$today);
 $startdate['year'] = $parts[0];
 $startdate['month'] = $parts[1];
 $startdate['day'] = ($parts[2] - $prune_days);
 
 $totaldays = ($prune_days + $placeholder_days);
 
 if ($totaldays > 0)
 {
  for ($i = 0; $i <= $totaldays; $i++)
  {
   $id = date("Ymd", gmmktime(0,0,0, $startdate['month'], ($startdate['day'] + $i), $startdate['year']));
   $range[] = $id;
  }  
 }
 
 //### FETCH EXISTING PARTITIONS
 $partitions = array();
 $query = "SELECT PARTITION_NAME, TABLE_ROWS FROM INFORMATION_SCHEMA.PARTITIONS WHERE TABLE_NAME=:tablename";
 $stmt = $PDO->prepare($query);
 $stmt->execute(array('tablename'=>$tablename));
 while ($row = $stmt->fetch())
 {
  if (preg_match("/p([0-9]{8})/i",$row['PARTITION_NAME'], $matches))
  {
   $partitions[] = $matches[1];
  }
 }
 
 //###ADD PARTITIONS 
 $additions = array_diff($range, $partitions);
 if (count($additions)>0)
 {
  $list = array();
  foreach ($additions AS $id)
  {
   if ($id > $partitions[count($partitions)-1])
   {
    $list[] = "PARTITION P{$id} VALUES LESS THAN (TO_DAYS({$id})+1)";
   }
  }
  
  if (count($list)>0)
  {   
   try
   {    
    $query = "ALTER TABLE $tablename DROP PARTITION PMAX";
    $stmt = $PDO->prepare($query);
    $result = $stmt->execute();
    
    $list[] = "PARTITION PMAX VALUES LESS THAN MAXVALUE";
    $query = "ALTER TABLE $tablename ADD PARTITION (". implode(", " . PHP_EOL,$list) .");";  
    $stmt = $PDO->prepare($query);
    $result = $stmt->execute();
   }
   catch (PDOException $e)
   {
    exit($e->getMessage());
   }
  }
 }
 
 
 //###TRUNCATE PARTITIONS
 $deletions = array_diff($partitions, $range);
 if (count($deletions)>0)
 {
  $list = array();
  foreach ($deletions AS $id)
  {
   $list[] = "P{$id}";
  }
  
  if (count($list)>0)
  {
   try
   {
    $query = "ALTER TABLE $tablename DROP PARTITION ". implode(",",$list) . ";";   
    $stmt = $PDO->prepare($query);
    $result = $stmt->execute();
   }
   catch (PDOException $e)
   {
    exit($e->getMessage());    
   }
  }
 }
 
 //###UPDATE ROTATION date
 $query = "UPDATE cron SET value = DATE_FORMAT(UTC_DATE(),'%Y-%m-%d') WHERE cronid = 'rotationdate'";
 $PDO->query($query);
 
 return;
}


?>