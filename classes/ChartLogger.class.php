<?php

class ChartLogger
{
 public $symbols;
 
 private $lastpriceid;
 private $lastunixtime;
  
 private $timezone;
 private $datafeed_unixtime;
 private $pricecache;

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
  $this->fetchSymbols();
  $this->fetchLastPriceOffset(); // Last priceid/unixtime as price offset
  $this->fetchPriceCache(); // Last captured prices stored in DB as cache
      
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
     //### Group prices by interval (seconds, 10s, minutes)         
     $interval[second] = $row[unixtime];
     $interval[minute] = floor($row[unixtime]/60)*60;          
     $interval[day] = floor($row[unixtime]/86400)*86400;          
     
     $prices[$row[symbol]][second][$interval[second]][] = $row[close]; // Seconds
     $prices[$row[symbol]][minute][$interval[minute]][] = $row[close]; // Minutes
     $prices[$row[symbol]][day][$interval[day]][] = $row[close]; // Day
     
     $this->adjustDailyPriceCache($row, $interval[day]);
     
     $duplicatecheck[$row[symbol]] = $row;
    }            
    $lastunixtime = $row[unixtime]; // Offset unixtime       
    $lastpriceid = $row[priceid]; // Offset priceid
   }
  }
  
  /******************************************************************************
  * PREPARE QUOTES
  ******************************************************************************/
  if ($prices)
  {
   foreach ($prices AS $symbol => $intervals)
   {   
    foreach ($intervals AS $interval => $price)
    {
     $quote = array();
     
     foreach ($price AS $unixtime => $values)
     {         
      // If symbol/unixtime exists in cache, use open/high/low from cache
      if ($unixtime == $this->pricecache[$symbol][$interval][unixtime])
      {
       $quote[open] = $this->pricecache[$symbol][$interval][open];
       $quote[low] = $this->pricecache[$symbol][$interval][low];
       $quote[high] = $this->pricecache[$symbol][$interval][high];
      }
      
      // 1. Open - Use cache value if exists always
      // 2. High/Low - Use lower/higher value if exists
      // 3. Close - Always use last element in list
      $quote[open] = $quote[open] ? $quote[open] : current($values); // First element
      $quote[low] = ($quote[low] && $quote[low] < min($values)) ? $quote[low] : min($values);
      $quote[high] = ($quote[high] && $quote[high] > max($values)) ? $quote[high] : max($values);
      $quote[close] = end($values); // Last element
        
      $quotes[$symbol][$interval][$unixtime] = $quote;    
     } 
    }
   }   
  }
    
  /******************************************************************************
  * INSERT PRICES
  ******************************************************************************/
  if ($quotes)
  {
   //### INTRADAY   
   foreach ($quotes AS $symbol => $intervals)
   {
    foreach ($intervals[second] AS $unixtime => $quote)
    {
     $insert = array();
     $insert[symbol] = $symbol;
     $insert[unixtime] = $unixtime;
     $insert[datetime] = gmdate("Y-m-d H:i:s",$unixtime);
     $insert[close] = $quote[close];
     $insert[low] = $quote[low];
     $insert[high] = $quote[high];
     $insert[open] = $quote[open];
     $inserts[] = "('" . implode("','",$insert) . "')";
    }
    
    $query = "REPLACE INTO intraday
              (symbol, unixtime, datetime, close, low, high, open)
              VALUES
              " . implode(",",$inserts);
    $this->DB->query($query);
    unset($inserts);    
    
    //-----------------------------------------------------------------------------
    foreach ($intervals[minute] AS $unixtime => $quote)
    {
     $insert = array();
     $insert[symbol] = $symbol;
     $insert[unixtime] = $unixtime;
     $insert[close] = $quote[close];
     $insert[low] = $quote[low];
     $insert[high] = $quote[high];
     $insert[open] = $quote[open];

     $query = "INSERT INTO
               minuteprice
               SET
               symbol = '$insert[symbol]',
               unixtime = $insert[unixtime],
               datetime = FROM_UNIXTIME($insert[unixtime]),
               close = '$insert[close]',
               high = '$insert[high]',
               low = '$insert[low]',
               open = '$insert[open]'    
                 ON DUPLICATE KEY
                 UPDATE
                 close = '$insert[close]',
                 high = '$insert[high]',
                 low = '$insert[low]',
                 open = '$insert[open]'                                                
               ";    
     $this->DB->query($query);         
     unset($insert);      
    }      
    
    //-----------------------------------------------------------------------------
    foreach ($intervals[day] AS $unixtime => $quote)
    {
     $insert = array();
     $insert[symbol] = $symbol;
     $insert[unixtime] = $unixtime;
     $insert[close] = $quote[close];
     $insert[low] = $quote[low];
     $insert[high] = $quote[high];
     $insert[open] = $quote[open];

     $query = "INSERT INTO
               dailyprice
               SET
               symbol = '$insert[symbol]',
               unixtime = $insert[unixtime],
               datetime = FROM_UNIXTIME($insert[unixtime]),
               close = '$insert[close]',
               high = '$insert[high]',
               low = '$insert[low]',
               open = '$insert[open]'    
                 ON DUPLICATE KEY
                 UPDATE
                 close = '$insert[close]',
                 high = '$insert[high]',
                 low = '$insert[low]',
                 open = '$insert[open]'                                                
               ";    
     $this->DB->query($query);         
     unset($insert);
    }      
   }
  }
  
  /******************************************************************************
  * PRICECACHE (Store last set of prices for overlapping of timerange)
  ******************************************************************************/
  if ($quotes)
  {
   foreach ($quotes AS $symbol => $intervals)
   {
    foreach ($intervals AS $interval => $quote)
    {
     $timerange = max(array_keys($quote));
     $finalquote = end($quote); // Get the last quote
     
     $query = "REPLACE INTO pricecache
               SET
               symbol = '$symbol',
               `interval` = '$interval',
               unixtime = '$timerange',
               close = '$finalquote[close]',
               high = '$finalquote[high]',
               low = '$finalquote[low]',
               open = '$finalquote[open]'
               ";
     $this->DB->query($query);   
    }
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
 private function adjustDailyPriceCache($row, $unixtime)
 {
  if (!$this->pricecache[$row[symbol]][day][adjusted])
  {   
   if ($this->pricecache[$row[symbol]][day][unixtime] == $unixtime)
   {
    $this->pricecache[$row[symbol]][day][high] = $row[high];
    $this->pricecache[$row[symbol]][day][low] = $row[low];
    $this->pricecache[$row[symbol]][day][open] = $row[open];
    $this->pricecache[$row[symbol]][day][adjusted] = true;
   }   
  }
 }
 
 //-----------------------------------------------------------------------------
 private function fetchPriceCache()
 {
  $query = "SELECT * FROM pricecache";

  $stmt = $this->DB->query($query);  
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
  {
   $this->pricecache[$row[symbol]][$row[interval]] = $row;
  }
 }
 
 //-----------------------------------------------------------------------------
 private function fetchSymbols()
 {
  $query = "SELECT * FROM counter WHERE active = '1'";
  //$query = "SELECT * FROM counter WHERE symbol = 'XAU A0-FX'";
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