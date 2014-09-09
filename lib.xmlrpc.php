<?php
/*
   Xenophobia XML-RPC library for PHP5
    by J. King (http://jkingweb.ca/)
   Licensed under Creative Commons Attribution (v2.5)

   See http://jkingweb.ca/code/php/lib.xmlrpc/
    for documentation
    
   Last revised 2009-04-01
*/


class XMLRPCLib {
 const agent = "Xenophobia XML-RPC/1.0.2";
 const notice = "This message is an XML-RPC server response, generated due to an error in the script automating this server.  If you were expecting something other than an XML-RPC response, the error is likely beyond your control.";
 public static $schema = '<grammar xmlns="http://relaxng.org/ns/structure/1.0"><start><choice><element name="methodCall"><interleave><ref name="Method"/><optional><ref name="CallParams"/></optional></interleave></element><element name="methodResponse"><choice><ref name="RespParams"/><ref name="Fault"/></choice></element></choice></start><define name="Method"><element name="methodName"><text/></element></define><define name="CallParams"><element name="params"><zeroOrMore><element name="param"><ref name="Value"/></element></zeroOrMore></element></define><define name="RespParams"><element name="params"><element name="param"><ref name="Value"/></element></element></define><define name="Value"><element name="value"><choice><ref name="ValueInt"/><ref name="ValueDouble"/><ref name="ValueBoolean"/><ref name="ValueString"/><ref name="ValueBase64"/><ref name="ValueDate"/><ref name="ValueArray"/><ref name="ValueStruct"/><ref name="ValueNullImplicit"/><ref name="ValueNullExplicit"/></choice></element></define><define name="ValueInt"><choice><element name="int"><text/></element><element name="i4"><text/></element></choice></define><define name="ValueDouble"><element name="double"><text/></element></define><define name="ValueBoolean"><element name="boolean"><choice><value>1</value><value>0</value></choice></element></define><define name="ValueString"><element name="string"><text/></element></define><define name="ValueBase64"><element name="base64"><text/></element></define><define name="ValueDate"><element name="dateTime.iso8601"><text/></element></define><define name="ValueArray"><element name="array"><optional><element name="data"><zeroOrMore><ref name="Value"/></zeroOrMore></element></optional></element></define><define name="ValueStruct"><element name="struct"><zeroOrMore><element name="member"><interleave><element name="name"><text/></element><ref name="Value"/></interleave></element></zeroOrMore></element></define><define name="ValueNullImplicit"><empty/></define><define name="ValueNullExplicit"><element name="nil"><empty/></element></define><define name="Fault"><element name="fault"><element name="value"><element name="struct"><element name="member"><interleave><element name="name"><value>faultCode</value></element><element name="value"><ref name="ValueInt"/></element></interleave></element><element name="member"><interleave><element name="name"><value>faultString</value></element><element name="value"><ref name="ValueString"/></element></interleave></element></element></element></element></define></grammar>';
 public static $useNil = FALSE;
 public static $faults = array(
   //interop
   -32700 => "parse error. not well formed",
   -32701 => "parse error. unsupported encoding",
   -32702 => "parse error. invalid character for encoding",
   -32600 => "server error. invalid xml-rpc. not conforming to spec.",
   -32601 => "server error. requested method not found",
   -32602 => "server error. invalid method parameters",
   -32603 => "server error. internal xml-rpc error",
   -32500 => "application error",
   -32400 => "system error",
   -32300 => "transport error",
   //implementation-specific
   -32099 => "(reserved)",
   -32098 => "(reserved)",
   -32097 => "server error. multicall nesting forbidden",
   -32096 => "server error. invalid or unknown timezone",
   -32095 => "client error. no procedure calls specified",
  );
 
 private function __construct() { 
  // throw an error if the user tries to make an instance
}
 
 public static function nativeToRPC($var) {
  // convert native PHP types to intermediary XML-RPC type objects
  if ($var instanceof XMLRPCValue)
   return $var;
  if (is_string($var))
   $var = new XMLRPCString($var);
  elseif (is_bool($var))
   $var = new XMLRPCBoolean($var);
  elseif (is_double($var))
   $var = new XMLRPCFloat($var);
  elseif (is_int($var))
   $var = new XMLRPCInt($var);
  elseif (is_null($var))
   $var = new XMLRPCNull();
  elseif (is_array($var)) {
   $isArray = TRUE;
   foreach(array_keys($var) as $key) {
    $checkKey = (string)(int) $key;
    if ($checkKey != $key) { // does a loose comparison to check that all array keys look like integers
     $isArray = FALSE;
     break;
    }
   }
   // if all keys are integers, it's an array; otherwise it's a struct
   $var = ($isArray) ? new XMLRPCArray($var) : new XMLRPCStruct($var);
  }
  elseif ($var instanceof DateTime)
   $var = new XMLRPCDate($var);
  elseif (is_object($var))
   $var = new XMLRPCStruct($var);
  else
   $var = new XMLRPCUnknownValue();
  return $var; 
 }
 
 public static function nodeToNative($node) {
  // convert DOM nodes to native PHP types
  if (!$node) // implicit null (no children of <value> node)
   return NULL;
  switch($node->tagName) {
   case "nil":
    return NULL;
   case "string":
    return $node->textContent;
   case "base64":
    return base64_decode($node->textContent);
   case "int":
   case "i4":
    return (int) $node->textContent;
   case "double": 
    return (float) $node->textContent;
   case "boolean":
    return (boolean) $node->textContent;
   case "dateTime.iso8601":
    return new DateTime($node->textContent);
   case "array":
    $array = array();
    $member = $node->getElementsByTagName("value")->item(0);
    while($member) {
     // if node isn't an element or isn't a <value> element, skip it
     if ($member->nodeType != XML_ELEMENT_NODE || $member->tagName != "value") {
      $member = $member->nextSibling;
      continue;
     }
     $array[] = self::nodeToNative($member->getElementsByTagName("*")->item(0));
     $member = $member->nextSibling;
    }
    return $array;
   case "struct":
    $struct = array();
    $member = $node->getElementsByTagName("member")->item(0);
    while($member) {
     // if node isn't an element or isn't a <member> element, skip it
     if ($member->nodeType != XML_ELEMENT_NODE || $member->tagName != "member") {
      $member = $member->nextSibling;
      continue;
     }
     $name = $member->getElementsByTagName("name")->item(0)->textContent;
     // only use the struct member if it has a key
     if ($name) {
      $value = $member->getElementsByTagName("value")->item(0)->getElementsByTagName("*")->item(0);
      $struct[$name] = XMLRPCLib::nodeToNative($value);
     }
     $member = $member->nextSibling;  
    }
    return $struct;
   case "fault":
    $members = $node->getElementsByTagName("member");
    for ($a = 0; $a < $members->length; $a++)
     {$name = trim($members->item($a)->getElementsByTagName("name")->item(0)->textContent);
      if ($name=="faultCode")
       {$code = $members->item($a)->getElementsByTagName("value")->item(0)->getElementsByTagName("*")->item(0);}
      elseif ($name=="faultString")
       {$string = $members->item($a)->getElementsByTagName("value")->item(0)->getElementsByTagName("*")->item(0);}
     }
    $code   = (int)    XMLRPCLib::nodeToNative($code);
    $string = (string) XMLRPCLib::nodeToNative($string);
    return new XMLRPCException($string, $code);
   default: // last-ditch fallback
    return new XMLRPCUnknownValue();
   }
  }
}


interface XMLRPCValue { // interface which all XML-RPC types must implement
 public function toNode();
 public function toNative();
}


class XMLRPCUnknownValue implements XMLRPCValue { // this only exists as a fail-safe
 
 public function toNode() {
  $document = new DOMDocument();
  return $document->createElement("string");
 }
 public function toNative() {
  return $this;
 } 
}


class XMLRPCNull implements XMLRPCValue {
 
 public function toNode() {
  $document = new DOMDocument();
  if (XMLRPCLib::$useNil) {
   $document->appendChild($document->createElement("nil"));
   return $document->documentElement;
  }
  else
   return $document->createTextNode("");
 }
 public function toNative() {
  return NULL;
 }
}


class XMLRPCString implements XMLRPCValue {
 protected $value;
 protected $encoding = "UTF-8";
 
 public function __construct($value, $encoding = "UTF-8") {
  $this->value = (string) $value;
  $this->encoding = $encoding;
 }
 public function toNode() {
  $document = new DOMDocument("1.0", $this->encoding);
  $document->appendChild($document->createElement("string"));
  $document->documentElement->appendChild($document->createTextNode($this->value));
  return $document->documentElement;
  }
  
 public function toNative() {
  return $this->value;
 } 
}


class XMLRPCInt implements XMLRPCValue {
 protected $value;
 
 public function __construct($value) {
  $this->value = (int) $value;
 }
 public function toNode() {
  $document = new DOMDocument();
  $document->appendChild($document->createElement("int"));
  $document->documentElement->appendChild($document->createTextNode($this->value));
  return $document->documentElement;
 }
 public function toNative() {
  return $this->value;
 }
}


class XMLRPCBoolean implements XMLRPCValue {
 protected $value;
 
 public function __construct($value) {
  $this->value = ($value) ? 1 : 0;
 }
 public function toNode() {
  $document = new DOMDocument();
  $document->appendChild($document->createElement("boolean"));
  $document->documentElement->appendChild($document->createTextNode($this->value));
  return $document->documentElement;
 }
 public function toNative() {
  return (boolean) $this->value;
 } 
}


class XMLRPCFloat implements XMLRPCValue {
 protected $value;
 
 public function __construct($value) {
  $this->value = (double) $value;
 }
 public function toNode() {
  $document = new DOMDocument();
  $document->appendChild($document->createElement("double"));
  $document->documentElement->appendChild($document->createTextNode($this->value));
  return $document->documentElement;
 }
 public function toNative() {
  return $this->value;
 } 
}


class XMLRPCBase64 implements XMLRPCValue {
 protected $value;
 
 public function __construct($value) {
  $this->value = $value;
 }
 public function toNode() {
  $document = new DOMDocument();
  $document->appendChild($document->createElement("base64"));
  $document->documentElement->appendChild($document->createTextNode(base64_encode($this->value)));
  return $document->documentElement;
 }
 public function toNative() {
  return $this->value;
 } 
}


class XMLRPCDate implements XMLRPCValue {
 protected $value;
 
 public function __construct($value = NULL) {
  // this classes uses Unix timestamps internally for simplicity's sake
  if (!$value)
   $this->value = time();
  elseif ($value instanceof DateTime)
   $this->value = $value->format("U");
  elseif (is_int($value))
   $this->value = $value;
  elseif (is_string($value))
   $this->value = strtotime($value);
  else
   $this->value = time();
 }
 public function toNode() {
  $document = new DOMDocument();
  $document->appendChild($document->createElement("dateTime.iso8601"));
  $document->documentElement->appendChild($document->createTextNode(date("Ymd\TH:i:s",$this->value)));
  return $document->documentElement;
 }
 public function toNative() {
  return new DateTime(date("Ymd\TH:i:s",$this->value));
 }
}


class XMLRPCArray implements XMLRPCValue {
 protected $value = array();
 
 public function __construct($value) {
  $this->value = $value;
  foreach($this->value as &$var) {
   $var = XMLRPCLib::nativeToRPC($var);
  }
 } 
 public function toNode() {
  $document = new DOMDocument();
  $array = $document->createElement("data");
  foreach($this->value as $member) {
   $array->appendChild($document->createElement("value"))->appendChild($document->importNode($member->toNode(),1));
  }
  $document->appendChild($document->createElement("array"))->appendChild($array);
  return $document->documentElement;
 }
 public function toNative() {
  $array = array();
  foreach($this->value as $member) {
   $array[] = $member->toNative();
  }
  return $array; 
 }  
}


class XMLRPCStruct implements XMLRPCValue {
 protected $value = array();
 
 public function __construct($value) {
  if (is_object($value))
   $this->value = (array) $value;
  elseif (is_array($value))
   $this->value = $value;
  else
   $this->value = array('value' => $value);
  foreach($this->value as &$var) {
   $var = XMLRPCLib::nativeToRPC($var);
  }
 }
 public function toNode() {
  $document = new DOMDocument();
  $document->appendChild($document->createElement("struct"));
  foreach($this->value as $key => $value) {
   $member = $document->createElement("member");
   $member->appendChild($document->createElement("name"))->appendChild($document->createTextNode($key));
   $member->appendChild($document->createElement("value"))->appendChild($document->importNode($value->toNode(),1));
   $document->documentElement->appendChild($member);
  }
  return $document->documentElement;
 }
 public function toNative() {
  $array = array();
  foreach($this->value as $key => $member) {
   $array[$key] = $member->toNative();
  }
  return $array;
 } 
}


class XMLRPCException extends Exception implements XMLRPCValue {
 
 public function __construct($message = NULL, $code = 0) {
  if ($code instanceof XMLRPCException) {
   $message = $code->getString();
   $code = $code->getCode();
  }
  parent::__construct($message, $code);
 }
 public function toNode() {
  $document = new DOMDocument();
  $struct = $document->appendChild($document->createElement("fault"))->appendChild($document->createElement("value"))->appendChild($document->createElement("struct"));
  $member = $struct->appendChild($document->createElement("member"));
  $member->appendChild($document->createElement("name"))->appendChild($document->createTextNode("faultCode"));
  $member->appendChild($document->createElement("value"))->appendChild($document->createElement("int"))->appendChild($document->createTextNode($this->getCode()));
  $member = $struct->appendChild($document->createElement("member"));
  $member->appendChild($document->createElement("name"))->appendChild($document->createTextNode("faultString"));
  $member->appendChild($document->createElement("value"))->appendChild($document->createElement("string"))->appendChild($document->createTextNode($this->getMessage()));
  return $document->documentElement;
 }
 public function toNative() {
  return $this;
 } 
 public function __toString() {
  return "Fault (".$this->getCode()."): ".$this->getMessage();
 } 
}


class XMLRPCExceptionMulti extends XMLRPCException { // for faults in system.multicall
 public function toNode() {
  return XMLRPCLib::nativeToRPC(array('faultCode' => $this->getCode(), 'faultString' => $this->getMessage()))->toNode();
 }
}


// basic emulation of DateTime class for versions of PHP prior to 5.2 (as implemented by PHP 5.2)
if (!class_exists("DateTime")) {
class DateTime {
 const ATOM    = "Y-m-d\TH:i:sP";
 const COOKIE  = "l, d-M-y H:i:s T";
 const ISO8601 = "Y-m-d\TH:i:sO";
 const RFC822  = "D, d M y H:i:s O";
 const RFC850  = "l, d-M-y H:i:s T";
 const RFC1036 = "D, d M y H:i:s O";
 const RFC1123 = "D, d M y H:i:s O";
 const RFC2822 = "D, d M y H:i:s O";
 const RFC3339 = "Y-m-d\TH:i:sP";
 const RSS     = "D, d M Y H:i:s O";
 const W3C     = "Y-m-d\TH:i:sP";
 protected $timestamp;

 public function __construct($time = "now", $timezone = NULL) {
  $this->timestamp = strtotime($time,time());
 }
 public function format($format) {
  return date($format,$this->timestamp);
 }
 public function getOffset() {
  return date("Z",$this->timestamp);
 }
 public function getTimezone() {
  return NULL;
 }
 public function modify($modification) {
  $this->timestamp = strtotime($modification,$this->timestamp);
 }
 public function setDate($year, $month, $day) {
  settype($year,"int");
  settype($month,"int");
  settype($day,"int");
  $this->timestamp = strtotime("$year-$month-{$day}T".date("H:i:s", $this->timestamp));
 }
 public function setISODate($year, $week, $day = NULL) {
  settype($year,"int");
  settype($week,"int");
  if ($day===NULL)
   $day = "";
  else {
   $day = (int) $day;
   $day = "-$day";
  }
  $this->timestamp = strtotime("{$year}W$week{$day}T".date("H:i:s",$this->timestamp));
 }
 public function setTime($hour, $min, $sec = 0) {
  settype($hour,"int");
  settype($min,"int");
  settype($sec,"int");
  $this->timestamp = strtotime(date("Y-m-d\T",$this->timestamp)."$hour:$min:$sec");
 }
 public function setTimezone($timezone) {
 }
}/*if*/}


class XMLRPCClient {
 public $useSchema = TRUE;
 public $useCurl = TRUE;
 public $debug = FALSE;
 public $throw = FALSE;
 protected $url;
 protected $calls = array();
 
 public function __construct($url) {
  $this->url = $url;
 }
 public function addCall($method) {
  // add RPCs to the multicall queue
  $args = func_get_args();
  $method = trim((string) array_shift($args));
  if (!$method)
   return FALSE;
  $this->calls[] = array('methodName' => $method, 'params' => $args);
  return TRUE;
 }
 public function clear() {
  // clears the multicall queue
  $this->calls = array();
 }
 public function call() {
  // perform an RPC
  $args = func_get_args();
  $method = trim((string) array_shift($args));
  // check to see if there is a multicall queue or whether system.multicall has been invoked directly
  $multicall = ($method=="system.multicall" || !$method && sizeof($this->calls)) ? TRUE : FALSE;
  // if there is no queue and no method was specified, throw an error
  if (!$multicall && !$method)
   throw new XMLRPCException(XMLRPCLib::$faults[-32095], -32095);
  $document = new DOMDocument;
  // handling a multicall is actually simpler than other RPCs---go figure
  if ($multicall) {
   $this->calls = XMLRPCLib::nativeToRPC($this->calls);
   $document->appendChild($document->createElement("methodCall"))->appendChild($document->createElement("methodName"))->appendChild($document->createTextNode("system.multicall"));
   $document->documentElement->appendChild($document->createElement("params"))->appendChild($document->createElement("param"))->appendChild($document->createElement("value"))->appendChild($document->importNode($this->calls->toNode(),1));
   $this->clear();   
  }
  else {
   $document->appendChild($document->createElement("methodCall"))->appendChild($document->createElement("methodName"))->appendChild($document->createTextNode($method));
   $params = $document->documentElement->appendChild($document->createElement("params"));
   for ($a = 0; $a < sizeof($args); $a++) {
    $args[$a] = XMLRPCLib::nativeToRPC($args[$a]);
    $params->appendChild($document->createElement("param"))->appendChild($document->createElement("value"))->appendChild($document->importNode($args[$a]->toNode(),1));
   }
  }   
  // send it off and get a response
  $document = $document->saveXML();
  if ($this->debug)
   $debug['request'] = $document;
  //choose between cURL and stream wrappers
  $document = (function_exists("curl_init") && $this->useCurl) ? $this->sendCurl($document) : $this->sendStream($document);
  if ($this->debug) {
   $debug['response'] = $document;
   return $debug;
  }
  // parse response
  $document = @DOMDocument::loadXML($document);
  if (!$document) 
   return $this->errorOut(new XMLRPCException(XMLRPCLib::$faults[-32700], -32700));
  //validate response, if applicable
  if ($this->useSchema && !@$document->relaxNGValidateSource(XMLRPCLib::$schema))
   return $this->errorOut(new XMLRPCException(XMLRPCLib::$faults[-32600], -32600));
  $response = $document->documentElement->getElementsByTagName("*")->item(0);
  switch($response->tagName) {
   case "fault":
    return $this->errorOut(XMLRPCLib::nodeToNative($response));
   case "params":
    // multicall requires special handling for faults
    if ($multicall) {
     $responses = XMLRPCLib::nodeToNative($response->getElementsByTagName("value")->item(0)->getElementsByTagName("*")->item(0));
     foreach($responses as &$response) {
      // checks to see if a struct is a multicall fault
      if (is_array($response) && sizeof($response)==2 && isset($response['faultCode']) && isset($response['faultString']))
       $response = new XMLRPCException($response['faultString'], $response['faultCode']);
     }  
     return $responses;
    }
    else
     return XMLRPCLib::nodeToNative($response->getElementsByTagName("value")->item(0)->getElementsByTagName("*")->item(0));
   default:
    return $this->errorOut(new XMLRPCException(XMLRPCLib::$faults[-32600], -32600));
  }
 }
 protected function errorOut($exception) {
  // a helper function which throws or returns exceptions based on preference
  switch($this->throw) {
   case TRUE:
    throw $exception;
   case FALSE:
    return $exception;
  }
 }
 protected function sendCurl(&$document) {
  // cURL dispatcher
  $agent = curl_init($this->url);
  curl_setopt($agent, CURLOPT_FOLLOWLOCATION, TRUE);
  curl_setopt($agent, CURLOPT_POST, TRUE);
  curl_setopt($agent, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($agent, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($agent, CURLOPT_INFILESIZE, strlen($document));
  curl_setopt($agent, CURLOPT_MAXREDIRS, 10);
  curl_setopt($agent, CURLOPT_POSTFIELDS, $document);
  curl_setopt($agent, CURLOPT_USERAGENT, XMLRPCLib::agent);
  curl_setopt($agent, CURLOPT_HTTPHEADER, array("Content-Type: text/xml; charset=UTF-8"));
  if ($this->debug) {
   curl_setopt($agent, CURLOPT_HEADER, TRUE);
   return @curl_exec($agent);
  }
  $response = @curl_exec($agent);
  if (curl_getinfo($agent, CURLINFO_HTTP_CODE) >= 300)
   throw new XMLRPCException(XMLRPCLib::$faults[-32300], -32300);
  if (!preg_match("!^(?:text|application)/xml!i", curl_getinfo($agent, CURLINFO_CONTENT_TYPE)))
   throw new XMLRPCException(XMLRPCLib::$faults[-32600], -32600);
  return $response;
 }
 protected function sendStream(&$document) {
  // PHP stream dispatcher
  $options = array(
    'method' => "POST",
    'user_agent' => XMLRPCLib::agent,
    'header'  => "Content-type: text/xml; charset=UTF-8\r\nContent-length: ".strlen($document),
    'content' => &$document,
    'max_redirects' => 10,
   );
  $context = stream_context_create(array('http' => &$options, 'https' => &$options));
  $stream = @fopen($this->url, "r", FALSE, $context);
  if (!$stream)
   throw new XMLRPCException(XMLRPCLib::$faults[-32300], -32300);
  return stream_get_contents($stream);
 } 
}

class XMLRPCServer extends XMLRPCLib {
 const defaultInput = "php://input";
 public $inputStream = "php://input";
 public $useSchema = TRUE;
 protected $methods = array();  
 protected $capabilities = array(
   //base capabilities of the server
   'xmlrpc' => array(
     'specUrl' => "http://www.xmlrpc.com/spec",
     'specVersion' => 20030630,
    ), 
   'capabilities' => array(
     'specUrl' => "http://tech.groups.yahoo.com/group/xml-rpc/message/2897",
     'specVersion' => 20010514,
    ),
   'faults_interop' => array(
     'specUrl' => "http://xmlrpc-epi.sourceforge.net/specs/rfc.fault_codes.php",
     'specVersion' => 20010516,
    ),
   'introspect' => array(
     'specUrl' => "http://xmlrpc.usefulinc.com/doc/reserved.html",  //gone; see http://web.archive.org/web/20010310102238/http://xmlrpc.usefulinc.com/doc/reserved.html
     'specVersion' => 20010310,
    ), 
   'nil' => array(
     'specUrl' => "http://ontosys.com/xml-rpc/extensions.php",
     'specVersion' => 20001211,
    ),
   'system_multicall' => array(
     'specUrl' => 'http://www.xmlrpc.com/discuss/msgReader$1208',  //gone; see http://web.archive.org/web/20060502175739/http://www.xmlrpc.com/discuss/msgReader$1208
     'specVersion' => 20010409,
    ), 
  );

 public function __construct() {
  // bootstrap the server by defining its base methods
  $this->addCall(
    "system.getCapabilities",
    array($this,"system_getCapabilities"),
    array("struct"),
    "Returns a (struct) list of implemented capabilities."
   ); 
  $this->addCall(
    "system.listMethods",
    array($this,"system_listMethods"),
    array("array"),
    "Returns a list of procedure calls implemented by this server."
   );
  $this->addCall(
    "system.methodSignature",
    array($this,"system_methodSignature"),
    array("array", "string"),
    "Returns a list of possible signatures for a procedure call."
   );
  $this->addCall(
    "system.methodHelp",
    array($this,"system_methodHelp"),
    array("string", "string"),
    "Returns information about the usage of a procedure call."
   );
  $this->addCall(
    "system.multicall",
    array($this,"system_multicall"),
    array("array", "array"),
    "Allows the performing of multiple procedure calls with a single request."
   );
  // if running on PHP 5.1 or later, support the getting and setting of the local timezone
  if (function_exists("date_default_timezone_set")) {
   $this->addCall(
     "system.setTimezone",
     array($this,"system_setTimezone"),
     array("string", "string"),
     "Sets the timezone (eg. 'America/Toronto') for this server to use during a multicall.  If called alone it is a no-op."
    );
   $this->addCall(
     "system.getTimezone",
     array($this,"system_getTimezone"),
     array("string"),
     "Returns the timezone (eg. 'America/Toronto') used by this server."
    );
  } 
 }
 public function addCall($name, $callback, $signature = NULL, $help = "") {
  // defines a method for the server and exposes it to the introspection facilities
  settype($name, "string");
  // if the method already exists, stop
  if (isset($this->methods[$name]))
   return FALSE;
  // if the specified callback is invalid, stop
  if (!is_callable($callback)) 
   return FALSE;
  // if a signature was provided, parse it
  if ($signature !== NULL) {
   $sig = array();
   settype($signature, "array");
   foreach($signature as &$type) {
    settype($type, "string");
   }
   $sig[] = $signature; 
  }
  settype($help, "string");
  // add the method
  $this->methods[$name] = array('callback' => $callback, 'signature' => $sig, 'help' => $help);
  return TRUE;
 }
 public function addSignature($method, $signature) {
  // adds a secondary signature to an existing method
  settype($method, "string");
  settype($signature, "array");
  if (!isset($this->methods[$method]))
   return FALSE;
  foreach($signature as &$type) {
   settype($type, "string");
  }
  $this->methods[$method]['signature'][] = $signature;
  return TRUE;
 }
 public function setHelp($method, $help) {settype($method, "string");
  // set or override a method's docstring
  settype($help, "string");
  if (!isset($this->methods[$method]))
   return FALSE;
  $this->methods[$method]['help'] = $help;
  return TRUE;
 }
 public function addCapability($name, $spec, $version)
  {settype($name, "string");
   settype($spec, "string");
   settype($version, "int");
   if (isset($this->capabilities[$name]))
    {return FALSE;}
   $this->capabilities[$name] = array('specUrl' => $spec, 'specVersion' => $version); 
   return TRUE;
  }
 public function serve() {
  // if data is coming from php://input, check for HTTP request methods other than POST
  if ($this->inputStream==self::defaultInput)
   $this->handleHTTPRequest();
  // if data isn't coming from php://input, an HTTP header cannot be counted on to exist
  else
   $_SERVER['CONTENT_TYPE'] = "text/xml";
  // if the input Content-Type isn't XML, error out
  if (isset($_SERVER['CONTENT_TYPE']) && !preg_match("!^(?:text|application)/xml!i", $_SERVER['CONTENT_TYPE']))
   $this->sendResponse(new XMLRPCException(XMLRPCLib::$faults[-32600],-32600));
  $payload = stream_get_contents(fopen($this->inputStream, "r"));
  // if there is no actual data, error out
  if (!trim($payload))
   $this->sendResponse(new XMLRPCException(XMLRPCLib::$faults[-32700],-32700));
  // parse the payload as XML
  $document = @DOMDocument::loadXML($payload);
  // if there were parse errors, error out
  if (!$document)
    $this->sendResponse(new XMLRPCException(XMLRPCLib::$faults[-32700],-32700));
  // if validation is used and the data doesn't validate, error out
  if ($this->useSchema && !@$document->relaxNGValidateSource(XMLRPCLib::$schema))
   $this->sendResponse(new XMLRPCException(XMLRPCLib::$faults[-32600],-32600));
  // if data is not a method call, error out
  if ($document->documentElement->tagName != "methodCall")
   $this->sendResponse(new XMLRPCException(XMLRPCLib::$faults[-32600],-32600));
  // if method isn't supported by server, error out
  $method = trim($document->documentElement->getElementsByTagName("methodName")->item(0)->textContent);
  if (!isset($this->methods[$method]))
   $this->sendResponse(new XMLRPCException(XMLRPCLib::$faults[-32601],-32601));
  // gather method parameters
  $params = array();
  $nodes = $document->documentElement->getElementsByTagName("params")->item(0)->getElementsByTagName("param");
  for ($a = 0; $a < $nodes->length; $a++) {
   $params[] = XMLRPCLib::nodeToNative($nodes->item($a)->getElementsByTagName("value")->item(0)->getElementsByTagName("*")->item(0));
  }
  try {
   // attempt to perform the method
   $response = call_user_func_array($this->methods[$method]['callback'], $params);
  } 
  catch (XMLRPCException $err) {
   // catch any thrown faults
   $response = $err;
  }
  // send the response
  self::sendResponse(XMLRPCLib::nativeToRPC($response));
 }
  
 public static function sendResponse($payload, $prependNotice = FALSE) {
  // if data to send isn't an XML-RPC type, make it so
  if (!$payload instanceof XMLRPCValue)
   $payload = XMLRPCLib::nativeToRPC($payload);
  $document = new DOMDocument();
  // $prependNotice is used by XMLRPCServer::handleErrors()
  if ($prependNotice)
   $document->appendChild($document->createComment("\n".wordwrap(XMLRPCLib::notice)."\n"));
  $document->appendChild($document->createElement("methodResponse"));
  // if responseis a fault no <params> or <param> elements are necessary
  if ($payload instanceof XMLRPCException)
   $document->documentElement->appendChild($document->importNode($payload->toNode(),1));
  else
   $document->documentElement->appendChild($document->createElement("params"))->appendChild($document->createElement("param"))->appendChild($document->createElement("value"))->appendChild($document->importNode($payload->toNode(),1));
  // serialize and send response
  $document = $document->saveXML();
  header("Server: ".XMLRPCLib::agent);
  header("Content-Type: text/xml; charset=UTF-8");
  header("Content-Length: ".strlen($document));
  die($document);
 }
  
 ///////////////////////////////////////////////////////////
 //////////////  Built-in RPC handlers below  //////////////
 ///////////////////////////////////////////////////////////
 
 protected function system_getCapabilities() {
  if (!isset($this->capabilities) || !is_array($this->capabilities) || !sizeof($this->capabilities))
   return new stdClass();
  else
   return $this->capabilities;
 }
 protected function system_listMethods() {
  return array_keys($this->methods);
 }
 protected function system_methodSignature($method) {
  if (!$this->methods[$method])
   return new XMLRPCException(XMLRPCLib::$faults[-32601],-32601);
  else
   return $this->methods[$method]['signature'];
  } 
 protected function system_methodHelp($method) {
  if (!isset($this->methods[$method]))
   return new XMLRPCException(XMLRPCLib::$faults[-32601],-32601);
  else
   return $this->methods[$method]['help'];
 }

 protected function system_multicall($calls) {
  $responses = array();
  foreach($calls as &$call) {
   $method = trim($call['methodName']);
   // if the requested method doesn't exist, queue an error and continue to the next call
   if (!isset($this->methods[$method])) {
    $responses[] = new XMLRPCExceptionMulti(XMLRPCLib::$faults[-32601],-32601);
    continue;
   }
   // nested multicalls are forbidden
   if ($method=="system.multicall") {
    $responses[] = new XMLRPCExceptionMulti(XMLRPCLib::$faults[-32097],-32097);
    continue;
   }
   $params = (array) $call['params'];
   try {
    // attempt to perform the requested RPC
    $response = call_user_func_array($this->methods[$method]['callback'], $params);
   }
   catch (XMLRPCException $err) {
    // if an exception was thrown, capture it and transform it into a multicall fault
    $response = new XMLRPCExceptionMulti($err);
   }
   // add the return alue of the call to the queue
   $responses[] = $response;
  } 
  return $responses;
 }
 protected function system_getTimezone() {
  return date_default_timezone_get();
 }
 protected function system_setTimezone($timezone) {
  if (date_default_timezone_set($timezone))
   return $timezone;
  else 
   return new XMLRPCException(XMLRPCLib::$faults[-32096],-32096);
 }
  
 ///////////////////////////////////////////////////////////
 ///////////  Error and exception handler below  ///////////
 ///////////////////////////////////////////////////////////
 
 public static function handleErrors() {
  ini_set("html_errors", FALSE);
  set_exception_handler(array("XMLRPCServer","handleException"));
  set_error_handler(array("XMLRPCServer","handleError"), error_reporting());
 } 
 public static function handleException($err) {
  // if error is an XML-RPC fault, send it directly
  if ($err instanceof XMLRPCException)
   self::sendResponse($err);
  // if it's a PHP exception, transform it into a fault before sending
  else 
   self::sendResponse(new XMLRPCException($err->getMessage()." (PHP code: ".$err->getCode().")", -32500), TRUE);
 }
 public static function handleError($code, $message) {
  // if error reporting is turns off, do nothing
  if (!error_reporting())
    return;
  else
   self::sendResponse(new XMLRPCException($message." (PHP code: $code)", -32500), TRUE);
 }
  
 ///////////////////////////////////////////////////////////
 //////////////  GET-request self-test below  //////////////
 ///////////////////////////////////////////////////////////
 
 protected function handleHTTPRequest() {
  switch($_SERVER['REQUEST_METHOD']) {
   case "POST":
    return;
   case "HEAD":
    header("HTTP/1.1 405 Method Not Allowed");
    header("Allow: POST");
    exit;
   default:
    header("HTTP/1.1 405 Method Not Allowed");
    header("Allow: POST");
    header("Content-Type: text/html; charset=UTF-8");
    $client = new XMLRPCClient("http://".$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI']);
    // get the list of capabilities
    $capabilities = $client->call("system.getCapabilities");
    // get the list of methods
    $methods = $client->call("system.listMethods");
    // for each method get an interleaved list of signatures and docstrings
    foreach($methods as $method) {
     $client->addCall("system.methodSignature", $method);
     $client->addCall("system.methodHelp", $method);
    }
    $data = $client->call();
    //gather the data together into an associative array, incrementally replacing $methods members
    while(sizeof($data)) {
     $method = array_shift($methods);
     $sigs = array_shift($data);
     $help = array_shift($data);
     foreach($sigs as &$sig) {
      $sig = array_shift($sig)." ".$method." ( ".implode(", ", $sig)." )";
     }
     // if no signatures are defined, leave placeholder text
     if (!sizeof($sigs))
      $sigs[0] = "(none provided)";
     $methods[$method] = array('sig' => $sigs, 'help' => $help);
    }
   
    /////////////////////////////////////////
    // This sucks.  Ideally the HTML       //
    // document here should be a separate  //
    // template file, but the convenience  //
    // of a single file wins out.          //
    /////////////////////////////////////////
    ?>
<!DOCTYPE html>
<title>Xenophobia XML-RPC server: 405 Method Not Allowed</title>
<style type="text/css">
/* 
   Stylesheet by Dustin Wilson (http://dustinwilson.com/)
   Licensed under Creative Commons Attribution (v2.5)
*/

body
 {background-color:#f4f4f4;
  font:normal 12px/17px Helvetica,Arial,sans-serif;
  color:#2f2f2f;
  padding:0 2em 2em 2em;
  margin:0;}
a,a:visited
 {font-weight:bold;
  text-decoration:none;
  color:#0072bc;}
a:hover
 {text-decoration:underline;}
a:visited
 {color:#662d91;}
h1,h2,h3,h4,h5,h6
 {margin:0 0 1em 0;}
h1
 {font-size:2em;
  font-weight:bold;
  padding:1ex;
  border:1px solid gray;
  border-top-width:0;
  border-bottom-width:4px;
  background-color:white;}
h2
 {font-size:1.5em;}
h3
 {margin:0;
  padding:0.5ex 1ex;
  border:1px solid gray;
  border-bottom-width:2px;
  background-color:white;
  line-height:16px;}
p
 {margin:0 0 1em 0;}
kbd,var,pre
 {font-family:Consolas,Courier,"Courier New",monospace;}
dl,dt,dd
 {margin:0;}
dl
 {margin-bottom:2em;
  border:1px solid gray;
  padding:1ex;
  background-color:white;}
dt
 {font-weight:bold;}
</style>
<body>
<h1>Xenophobia XML-RPC server</h1>
<p>This URI acts as an XML-RPC server, and therefore only accepts POST requests.  If you wish to interact with the server, send a proper POST request with an XML-RPC client to this URI.  Information on XML-RPC can be found at <a href="http://xml-rpc.com">the XML-RPC Web site</a>; documentation on the Xenophobia XML-RPC library in particular can be found at <a href="http://jkingweb.ca/code/php/lib.xmlrpc/">the author's manual page</a>.
<p>Below is a list of specifications and procedure calls implemented by this server specifically.  It serves both as self-documentation and as a demonstration of both the client and server functionality of the Xenophobia library.
<h2>Capabilities</h2>
<?php
foreach($capabilities as $capability => $data)
 {?>
<h3><?= $capability;?></h3>
<dl>
 <dt>Specification:
  <dd><a href="<?= $data['specUrl'];?>"><?= $data['specUrl'];?></a>
 <dt>Version:
  <dd><?= $data['specVersion'];?>
</dl>
<?php } ?>
<h2>Methods</h2>
<?php
foreach($methods as $method => $data)
 {?>
<h3><?= $method;?></h3>
<dl>
 <dt>Signatures:
  <dd>
<?php foreach($data['sig'] as $sig) {
    echo "   $sig<br>\n";
}?>
 <dt>Description:
  <dd><?= $data['help'];?>
</dl>
<?php } ?>
<small>
<?php 
 echo " ".XMLRPCLib::agent;
 if (date_default_timezone_get())
  echo " (". date_default_timezone_get().")";?>
</small>
<?php exit;
    }
  }
}