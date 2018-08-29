<?php

include("includes/functions.php");

/******************************************************************************
* PARTITIONING
******************************************************************************/
if (requireRotation($PDO))
{
 rotatePartitions($PDO, "seconds", 3, 3);
 rotatePartitions($PDO, "intraday", 7, 3);
}

/******************************************************************************
* FETCH ALL PRICES
******************************************************************************/
$logger = new ChartLogger($PDO);
$logger->setDatafeed($PDO_DATAFEED);
$logger->logPrices();
$output = $logger->getOutput();

/******************************************************************************
* STATS
******************************************************************************/
$display[host] = $database[host];
$display[datafeed] = $datafeed[host];
$display[lag] = $logger->getLag();

/******************************************************************************
* DISPLAY
******************************************************************************/
$template->assign("stats",$logger->getStats());
$template->assign("display",$display);
$template->assign("output",$output);
$template->display("cron.htm");


?>