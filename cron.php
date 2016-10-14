<?php

include("includes/functions.php");

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
$template->assign("display",$display);
$template->assign("output",$output);
$template->display("cron.htm");

?>