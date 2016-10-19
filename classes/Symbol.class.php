<?php

class Symbol
{
 public $symbol;
 public $session;
 public $timezone;
 public $pointvalue;
 public $pricescale;
 public $minmov;
 public $minmov2;  
 public $has_seconds;
 public $has_intraday;
 public $has_daily;
 public $has_no_volume;  
 
 private $DB;

 //-----------------------------------------------------------------------------
 public function __construct($DB)
 {
  $this->DB = $DB;
  
  $this->session = '24x7';
  $this->timezone = 'UTC';
  $this->pointvalue = 1;
  $this->pricescale = 100;
  $this->minmov = 1;
  $this->minmov2 = 0;  
  $this->has_seconds = 1;
  $this->has_intraday = 1;
  $this->has_daily = 1;
  $this->has_no_volume = 1;
 }
 
 //-----------------------------------------------------------------------------
 public function setSymbol($symbol)
 {
  $this->symbol = $symbol;
 }
 
 //-----------------------------------------------------------------------------
 public function setPrecision($formatmask, $pointvalue = 1)
 {
  if (preg_match("/[#\.](0+)$/",$formatmask,$matches))
  {
   $pricescale = 1 * pow(10, strlen($matches[1]));
  }
  
  $this->pricescale = $pricescale;
  $this->pointvalue = $pointvalue;  
 }
 
 //-----------------------------------------------------------------------------
 public function save()
 {
  $params = array();
  $params['symbol'] = $this->symbol;
  $params['session'] = $this->session;
  $params['timezone'] = $this->timezone;
  $params['pointvalue'] = $this->pointvalue;
  $params['pricescale'] = $this->pricescale;
  $params['minmov'] = $this->minmov;
  $params['minmov2'] = $this->minmov2;
  $params['has_seconds'] = $this->has_seconds;
  $params['has_intraday'] = $this->has_intraday;
  $params['has_daily'] = $this->has_daily;
  $params['has_no_volume'] = $this->has_no_volume;
  
  $query = "REPLACE INTO symbol
            SET
            symbol = :symbol,
            session = :session,
            timezone = :timezone,
            pointvalue = :pointvalue,
            pricescale = :pricescale,
            minmov = :minmov,
            minmov2 = :minmov2,
            has_seconds = :has_seconds,
            has_intraday = :has_intraday,
            has_daily = :has_daily,
            has_no_volume = :has_no_volume            
            ";
            
  $stmt = $this->DB->prepare($query);
  $stmt->execute($params) OR DIE(print_r($stmt->errorInfo()));
  
  $this->createCounter();
 }
 
 //-----------------------------------------------------------------------------
 private function createCounter()
 {
  $counterid = $this->symbol;
  $symbol = $this->symbol;
  $name = $this->symbol;
  
  $query = "SELECT * FROM counter WHERE symbol = '$symbol'";
  $stmt = $this->DB->query($query);
  if ($stmt->rowCount()==0)
  {
   $params['counterid'] = $counterid;
   $params['symbol'] = $symbol;
   $params['name'] = $name;
   $params['exchangeid'] = $this->matchCounterExchange($counterid);
   
   $query = "INSERT INTO counter
             SET
             counterid = :counterid,
             symbol = :symbol,
             name = :name,
             exchangeid = :exchangeid
             ";
   
   $stmt = $this->DB->prepare($query);
   $stmt->execute($params);   
  }
 }
 
 //-----------------------------------------------------------------------------
 private function matchCounterExchange($counterid)
 {
  $exchangeid = "stocks"; // Default
  
  $params['counterid'] = $counterid;  
  $query = "SELECT exchangeid FROM exchange WHERE :counterid REGEXP regex";  
  
  $stmt = $this->DB->prepare($query);
  $stmt->execute($params);
  if ($stmt->rowCount()>0)
  {
   $exchange = $stmt->fetch(PDO::FETCH_ASSOC);
   $exchangeid = $exchange[exchangeid];
  }  
  return $exchangeid;
 } 
}


?>