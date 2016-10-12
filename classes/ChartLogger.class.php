<?php

class ChartLogger
{
 public $symbols;
 
 private $lastpriceid;
 private $lastunixtime;
 
 private $timezone;
 private $datafeed_unixtime;
 private $DB;
 private $DB_DATAFEED;
 
 //-----------------------------------------------------------------------------
 public function __construct($DB)
 {
  $this->timezone = "+00:00"; // UTC TIME
  $this->DB = $DB;
  
  $query = "SET time_zone = '{$this->timezone}'";
  $this->DB->query($query);  
 }
 
 //-----------------------------------------------------------------------------
 public function setDatafeed($DB_DATAFEED)
 { 
  $this->DB_DATAFEED = $DB_DATAFEED;
  
  $query = "SET time_zone = '{$this->timezone}'";
  $this->DB_DATAFEED->query($query);
  
  $query = "SELECT UNIX_TIMESTAMP() AS datafeed_unixtime";
  $stmt = $this->DB_DATAFEED->query($query);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $this->datafeed_unixtime = $row[datafeed_unixtime];
 }
 
 //-----------------------------------------------------------------------------
 public function logPrices()
 {
  $this->fetchLastPriceOffset();
  $this->fetchSymbols();  
  
  if ($this->symbols)
  {   
   $query = "SELECT
             priceid,
             symbol,
             last AS close,
             UNIX_TIMESTAMP(time) AS unixtime,
             low, high, open
             FROM quotelog
             WHERE
             symbol IN ('".implode("','",array_keys($this->symbols))."')
             AND priceid > {$this->lastpriceid}
             AND time > (NOW()- INTERVAL 10 MINUTE)
             AND time > FROM_UNIXTIME({$this->lastunixtime})
             LIMIT 2000
             ";
   
   $stmt = $this->DB_DATAFEED->query($query);   
            
   while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
   {
    //### Only capture non-duplicate unique prices (unixtime/close)
    if ($duplicatecheck[$row[symbol]][unixtime] != $row[unixtime] || $duplicatecheck[$row[symbol]][close] != $row[close]) 
    {
     //### Group prices by timerange (10 second intervals)
     $row[timerange] = substr($row[unixtime],0,-1) . "0"; // Group by 10 seconds
     
     $prices[$row[symbol]][$row[timerange]][] = $row[close];
     $duplicatecheck[$row[symbol]] = $row;
    }
    
    // Use last row for updating:
    // 1) Daily price
    // 2) Last priceid
    $dailyprices[$row[symbol]] = $row;    
    
    $lastunixtime = $row[unixtime]; // Used to record last price recorded       
    $lastpriceid = $row[priceid]; // Used to record last price recorded
   }
  }
  
  /******************************************************************************
  * PRICE CACHE (fetch last cache - in case prices in the same range gets missed out)
  ******************************************************************************/
  if ($prices)
  {   
   $query = "SELECT * FROM pricecache";

   $stmt = $this->DB->query($query);  
   while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
   {
    $pricecache[$row[counterid]] = $row;
   }
  }
  
  /******************************************************************************
  * PREPARE QUOTES
  ******************************************************************************/
  if ($prices)
  {
   foreach ($prices AS $symbol => $price)
   {   
    $quote = array();
    
    foreach ($price AS $timerange => $values)
    {
     if ($timerange == $pricecache[$counterid][timerange])
     {
      $quote[open] = $pricecache[$counterid][open];
      $quote[low] = $pricecache[$counterid][low];
      $quote[high] = $pricecache[$counterid][high];
     }
     
     $quote[open] = $quote[open] ? $quote[open] : current($values); // First element
     $quote[low] = ($quote[low] && $quote[low] < min($values)) ? $quote[low] : min($values);
     $quote[high] = ($quote[high] && $quote[high] > max($values)) ? $quote[high] : max($values);
     $quote[close] = end($values); // Last element
       
     $quotes[$symbol][$timerange] = $quote;    
    }   
   }   
  }
  
  /******************************************************************************
  * UPDATE DAILY PRICES
  ******************************************************************************/  
  if ($dailyprices)
  {
   foreach ($dailyprices AS $symbol => $dailyprice)
   {
    $query = "REPLACE INTO
              dailyprice
              SET
              symbol = '$symbol',
              gmdate = FROM_UNIXTIME($dailyprice[timerange]),
              unixtime = '$dailyprice[timerange]',
              close = '$dailyprice[close]',
              high = '$dailyprice[high]',
              low = '$dailyprice[low]',
              open = '$dailyprice[open]'        
              ";
    $this->DB->query($query);
   }      
  }
  
  /******************************************************************************
  * INSERT PRICES
  ******************************************************************************/
  if ($quotes)
  {   
   foreach ($quotes AS $symbol => $counter)
   {
    foreach ($counter AS $timerange => $quote)
    {
     $insert = array();
     $insert[symbol] = $symbol;
     $insert[unixtime] = $timerange;
     $insert[close] = $quote[close];
     $insert[low] = $quote[low];
     $insert[high] = $quote[high];
     $insert[open] = $quote[open];
     $inserts[] = "('" . implode("','",$insert) . "')";
    }
   }

   $query = "REPLACE INTO intraday
             (symbol, unixtime, close, low, high, open)
             VALUES
             " . implode(",",$inserts);
   $this->DB->query($query);
  }

  /******************************************************************************
  * PRICECACHE (Store last set of prices for overlapping of timerange)
  ******************************************************************************/
  if ($quotes)
  {
   foreach ($quotes AS $symbol => $quote)
   {
    $timerange = max(array_keys($quote));
    $finalquote = end($quote); // Get the last quote
    
    $query = "REPLACE INTO pricecache
              SET
              symbol = '$symbol',
              timerange = '$timerange',
              close = '$finalquote[close]',
              high = '$finalquote[high]',
              low = '$finalquote[low]',
              open = '$finalquote[open]'
              ";
    $this->DB->query($query);           
   }
  }

  /******************************************************************************
  * PRICE FLAG
  ******************************************************************************/
  if (!$lastpriceid) // No prices found, use lastpriceid from datafeed quotelog
  {
   $query = "SELECT
             MAX(priceid) AS lastpriceid,
             UNIX_TIMESTAMP(MAX(time)) AS lastunixtime
             FROM quotelog";
   $stmt = $this->DB_DATAFEED->query($query);
   $row = $stmt->fetch(PDO::FETCH_ASSOC);
   $lastpriceid = $row[lastpriceid];
   $lastunixtime = $row[lastunixtime];   
  }
  $this->updateLastPriceOffset($lastpriceid, $lastunixtime);
  
  return $quotes;
 }
 
 //-----------------------------------------------------------------------------
 public function getLag()
 {
  return ($this->datafeed_unixtime - $this->lastunixtime);
 }
 
 //-----------------------------------------------------------------------------
 private function fetchSymbols()
 {
  $query = "SELECT * FROM counter WHERE active = '1'";
  $stmt = $this->DB_DATAFEED->query($query);
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
  {
   $this->symbols[$row[symbol]] = $row;
  }
 }
 
 //-----------------------------------------------------------------------------
 private function fetchCronValue($cronid)
 {
  $query = "SELECT
            value AS $cronid
            FROM cron
            WHERE
            cronid = '$cronid'";
  $stmt = $this->DB->query($query);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row[$cronid];  
 }
 
 //-----------------------------------------------------------------------------
 private function fetchLastPriceOffset()
 {
  $this->lastpriceid = $this->fetchCronValue('lastpriceid');  
  
  $query = "SELECT
            IF(value, value, UNIX_TIMESTAMP()) AS lastunixtime
            FROM cron
            WHERE
            cronid = 'lastunixtime'
            ";
            
  $stmt = $this->DB->query($query);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $this->lastunixtime = $row[lastunixtime];  
 }
 
 //-----------------------------------------------------------------------------
 private function updateLastPriceOffset($lastpriceid, $lastunixtime = "")
 {
  $this->lastpriceid = $lastpriceid;
  $query = "UPDATE cron SET value = $lastpriceid WHERE cronid = 'lastpriceid'";
  $this->DB->query($query);    
  
  if ($lastunixtime)
  {
   $this->lastunixtime = $lastunixtime;
   $query = "UPDATE cron SET value = $lastunixtime WHERE cronid = 'lastunixtime'";
   $this->DB->query($query);  
  }
 } 

}

?>