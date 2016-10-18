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


?>