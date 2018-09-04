<?php

class ChartLogger
{
 public $symbols;
 public $output;

 public $stats;
 
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
  $starttime = microtime(true);
  
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
    $this->stats[total_rows]++;
    
    //### Only capture non-duplicate unique prices (unixtime/close)
    if ($duplicatecheck[$row['symbol']]['unixtime'] != $row['unixtime'] ||
        $duplicatecheck[$row['symbol']]['close'] != $row['close']) 
    {
     //### Group prices by interval (seconds, 10s, minutes)         
     $interval['second'] = $row['unixtime'];
     $interval['minute'] = floor($row['unixtime']/60)*60;          
     $interval['day'] = floor($row['unixtime']/86400)*86400;          
     
     $prices[$row['symbol']]['second'][$interval['second']][] = $row['close']; // Seconds
     $prices[$row['symbol']]['minute'][$interval['minute']][] = $row['close']; // Minutes
     $prices[$row['symbol']]['day'][$interval['day']][] = $row['close']; // Day
     
     //### Daily price will use datafeed provided open/high/low
     $this->adjustDailyPriceCache($row, $interval['day']);
     
     $duplicatecheck[$row['symbol']] = $row;
    }
    else
    {
     $this->stats[total_duplicates]++;
    }
    $lastunixtime = $row['unixtime']; // Offset unixtime       
    $lastpriceid = $row['priceid']; // Offset priceid
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
      $quote = array();
      
      // If symbol/unixtime exists in cache, use open/high/low from cache
      if ($unixtime == $this->pricecache[$symbol][$interval]['unixtime'])
      {
       $quote['open'] = $this->pricecache[$symbol][$interval]['open'];
       $quote['low'] = $this->pricecache[$symbol][$interval]['low'];
       $quote['high'] = $this->pricecache[$symbol][$interval]['high'];
      }
      
      // 1. Open - Use cache value if exists always
      // 2. High/Low - Use lower/higher value if exists
      // 3. Close - Always use last element in list
      $quote['open'] = $quote['open'] ? $quote['open'] : current($values); // First element
      $quote['low'] = ($quote['low'] && $quote['low'] < min($values)) ? $quote['low'] : min($values);
      $quote['high'] = ($quote['high'] && $quote['high'] > max($values)) ? $quote['high'] : max($values);
      $quote['close'] = end($values); // Last element
        
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
   //### INTRADAY (SECOND) 
   foreach ($quotes AS $symbol => $intervals)
   {
    foreach ($intervals['second'] AS $unixtime => $quote)
    {
     $insert = array();
     $insert['symbol'] = $symbol;
     $insert['unixtime'] = $unixtime;
     $insert['utcdatetime'] = gmdate("Y-m-d H:i:s",$unixtime);
     $insert['close'] = $quote['close'];
     $insert['low'] = $quote['low'];
     $insert['high'] = $quote['high'];
     $insert['open'] = $quote['open'];
     $inserts['second'][] = "('" . implode("','",$insert) . "')";
    }
    
    //-----------------------------------------------------------------------------
    foreach ($intervals['minute'] AS $unixtime => $quote)
    {
     $insert = array();
     $insert['symbol'] = $symbol;
     $insert['unixtime'] = $unixtime;
     $insert['utcdatetime'] = gmdate("Y-m-d H:i:s",$unixtime);     
     $insert['close'] = $quote['close'];
     $insert['low'] = $quote['low'];
     $insert['high'] = $quote['high'];
     $insert['open'] = $quote['open'];
     $inserts['minute'][] = "('" . implode("','",$insert) . "')";
    }      
    
    //-----------------------------------------------------------------------------
    foreach ($intervals['day'] AS $unixtime => $quote)
    {
     $insert = array();
     $insert['symbol'] = $symbol;
     $insert['unixtime'] = $unixtime;
     $insert['utcdatetime'] = gmdate("Y-m-d H:i:s",$unixtime);     
     $insert['close'] = $quote['close'];
     $insert['low'] = $quote['low'];
     $insert['high'] = $quote['high'];
     $insert['open'] = $quote['open'];
     
     $inserts['day'][] = "('" . implode("','",$insert) . "')";
    }      
   }

   /*******************************************************************************
   * MASS INSERTS
   *******************************************************************************/   
   if ($inserts['second'])
   {
    $query = "INSERT INTO seconds
              (symbol, unixtime, utcdatetime, close, low, high, open)
              VALUES " . implode(",",$inserts['second']) . "
              ON DUPLICATE KEY UPDATE
                close = VALUES(close),
                low = VALUES(low),
                high = VALUES(high),
                open = VALUES(open)              
              ";
    $this->DB->query($query);       
   }
   
   if ($inserts['minute']) 
   {
    $query = "INSERT INTO
               intraday
               (symbol, unixtime, utcdatetime, close, high, low, open)
               VALUES " . implode(",",$inserts['minute']) . "
               ON DUPLICATE KEY UPDATE
                close = VALUES(close),
                low = VALUES(low),
                high = VALUES(high),
                open = VALUES(open)  
               ";    
     $this->DB->query($query);      
   }
   
   //------------------------------------------------------------------------------
   if ($inserts['day'])
   {
    $query = "INSERT INTO
              dailyprice
              (symbol, unixtime, utcdatetime, close, high, low, open)
              VALUES " . implode(",",$inserts['day']) . "
              ON DUPLICATE KEY UPDATE
               close = VALUES(close),
               low = VALUES(low),
               high = VALUES(high),
               open = VALUES(open)   
              ";    
              
    $this->DB->query($query);          
   }
  }
  
  /******************************************************************************
  * PRICECACHE (Store last set of prices for overlapping of timerange)
  ******************************************************************************/
  if ($quotes)
  {
   $this->updatePriceCache($quotes);
   $this->logOutput($quotes);
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
   $lastpriceid = $row['lastpriceid'];
   $lastunixtime = $row['lastunixtime'];   
  }
  $this->updateLastPriceOffset($lastpriceid, $lastunixtime);
  
  /******************************************************************************
  * EXECUTION TIME
  ******************************************************************************/
  $this->stats['execution_time'] = round((microtime(true)-$starttime)/60,4) . " seconds";  
 }
 
 //-----------------------------------------------------------------------------
 public function getStats()
 {
  $stats = array();
  $stats[execution_time] = array("label"=>"Execution Time","value"=>$this->stats['execution_time']);
  $stats[total_rows] = array("label"=>"Total Rows","value"=>$this->stats['total_rows']);
  $stats[total_duplicates] = array("label"=>"Duplicate Prices","value"=>$this->stats['total_duplicates']);
  return $stats;
 }
 
 //-----------------------------------------------------------------------------
 public function getLag()
 {
  return ($this->datafeed_unixtime - $this->lastunixtime);
 }
 
 //-----------------------------------------------------------------------------
 public function getOutput()
 {
  if ($this->output)
  {
   ksort($this->output);
  }
  return $this->output;
 } 
 
 //-----------------------------------------------------------------------------
 private function fetchPriceCache()
 {
  $query = "SELECT * FROM pricecache";

  $stmt = $this->DB->query($query);  
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
  {
   $this->pricecache[$row['symbol']][$row['interval']] = $row;
  }
 }
  
  
 //-----------------------------------------------------------------------------
 private function adjustDailyPriceCache($row, $unixtime)
 {
  if (!$this->pricecache[$row['symbol']]['day']['adjusted'])
  {   
   if ($this->pricecache[$row['symbol']]['day']['unixtime'] == $unixtime)
   {
    $this->pricecache[$row['symbol']]['day']['high'] = $row['high'];
    $this->pricecache[$row['symbol']]['day']['low'] = $row['low'];
    $this->pricecache[$row['symbol']]['day']['open'] = $row['open'];
    $this->pricecache[$row['symbol']]['day']['adjusted'] = true;
   }   
  }
 }  
 
 //-----------------------------------------------------------------------------
 private function updatePriceCache($quotes)
 {  
  foreach ($quotes AS $symbol => $intervals)
  {
   foreach ($intervals AS $interval => $prices)
   {
    $price = end($prices); // Get the last quote   
    $price['unixtime'] = max(array_keys($prices));
    
    $values[] = "('$symbol', '$interval', $price[unixtime], '$price[close]', '$price[high]', '$price[low]', '$price[open]')";    
   }
  }

  $query = "INSERT INTO pricecache
            (symbol, `interval`, unixtime, close, high, low, open)
            VALUES
            (".implode(",",$values).")
                ON DUPLICATE KEY UPDATE
                unixtime = VALUES(unixtime),
                close = VALUES(close),
                high = VALUES(high),
                low = VALUES(low),
                open = VALUES(open)                
            ";  
            
  if (!$this->DB->query($query))
  {
   return false;
  }  
 }


 //-----------------------------------------------------------------------------
 private function fetchSymbols()
 {
  //### Chart symbols
  $chartsymbols = array();
  
  $query = "SELECT * FROM symbol";
  $stmt = $this->DB->query($query);
  while ($row = $stmt->fetchObject('Symbol', array($this->DB)))
  {
   $chartsymbols[$row->symbol] = $row;
  }

  //### Datafeed counters
  $query = "SELECT symbol, formatmask FROM counter WHERE active = '1'";
  $stmt = $this->DB_DATAFEED->query($query);
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
  {
   $this->symbols[$row['symbol']] = $row;
   
   /******************************************************************************
   * CREATE SYMBOL/COUNTER IF NOT EXIST
   ******************************************************************************/   
   if (!in_array($row['symbol'],array_keys($chartsymbols)))
   {    
    $chartsymbol = new Symbol($this->DB);
    $chartsymbol->setSymbol($row['symbol']);
    $chartsymbol->setPrecision($row['formatmask']);
    
    $chartsymbol->save();
   }
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
  $this->lastunixtime = $row['lastunixtime'];  
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

 //-----------------------------------------------------------------------------
 private function logOutput($quotes)
 {
  foreach ($quotes AS $symbol => $intervals)
  {  
   $prices = $intervals['second'];   
   $price = end($prices); // Get the last quote   
   $price['unixtime'] = max(array_keys($prices));    
   $this->output[$symbol] = $price;
  }     
 }  

}

?>