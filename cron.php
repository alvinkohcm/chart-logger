<?php

include("includes/functions.php");

/******************************************************************************
* FETCH ALL PRICES
******************************************************************************/
$logger = new ChartLogger($PDO);
$logger->setDatafeed($PDO_DATAFEED);
$quotes = $logger->logPrices();

/******************************************************************************
* STATS
******************************************************************************/
$display[host] = $database[host];
$display[datafeed] = $datafeed[host];
$display[lag] = $logger->getLag();
if ($quotes)
{
 ksort($quotes);
}

/******************************************************************************
* DISPLAY
******************************************************************************/
$template->assign("display",$display);
$template->assign("quotes",$quotes);
$template->display("cron.htm");

?>