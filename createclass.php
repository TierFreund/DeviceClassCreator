<?

/* TODO: Prüfen ob es bei doppelten claasnamen die gleiche  SCPDURL ist */
//include 'XUTILS.php'; only for dumpvar 

define('MSG_NEEDS',    'Benoetigt');
define('MSG_RETURNS',  'Liefert als Ergebnis');
define('MSG_FUNCTION', 'Funktion');
define('MSG_NOTHING',  'Nichts');
define('MSG_ALLOWED',  '=> Auswahl');
define('MSG_DEFAULT',  'Vorgabe');
define('MSG_NO_SERVICE','Unbekanner Service-Name');
define('MSG_NO_FUNCTION','Unbekannter Funktions-Name');
define('MSG_ARRAY_KEYS', 'Array mit folgenden Keys');
define('HEAD_LINE_FUNC','**************************************************************************');	
define('HEAD_LINE_CLASS','##########################################################################');	

class SchemeCreator {
	private $BaseUrl='';
	private $ClassSuffix='';
	private $FullServiceNames=false;
	private $Compact=false; // Wenn true werden keine Kommentare / Heads erstellt;
	private $VarDefaults=[]; // Vorgabe Werte für Functionen
	private $MaxArguments=5; // Zum erstellen der Function CallService benötigt
	private $VarDefs=null; // Array zum speichern aller Variablen der Service Funktionen;
	public $Content='';
	
	public function __construct($ClassSuffix='', $Defaults=null){
		if(!is_null($ClassSuffix))$this->ClassSuffix=$ClassSuffix;
		if(is_array($Defaults))$this->VarDefaults=$Defaults;
		else{
			$this->VarDefaults['InstanceID']=0;
			$this->VarDefaults['Channel']="'MASTER'";
			$this->VarDefaults['Speed']=1;
		}
	}
	
	public function Create($url, $FullServiceNames=false, $save=false, $ClassSuffix=null){
		$this->MaxArguments=5;
		$this->VarDefs=[];
		$this->FullServiceNames=$FullServiceNames;
		$this->Content='';
		if(!is_null($ClassSuffix))$this->ClassSuffix=$ClassSuffix;
		$xml = simplexml_load_file($url);
		if(!$xml)die ("Kann $url nicht erreichen");
		$p=parse_url($url);
		$this->BaseUrl=($p['scheme'])?$p['scheme'].'://':'';
		$this->BaseUrl.=$p['host'].':'.$p['port'];
		if(!$this->ClassSuffix){
			preg_match('/^([A-Za-z]+)/',(string)$xml->device->manufacturer,$matches); 
			$name=$matches[1];
			$this->ClassSuffix=$name;
		}
		$device_classes=[];
		$service_classes=[];
		$dupe_classes=[];
		$tmp=[];
		$bd=&$xml->device;
		
		// Erstelle ClassenIndex zwecks überprüfung und handling von gleichen Namen
		if($bd->serviceList){
			foreach($bd->serviceList[0]->service as $service){
				$name=$this->GetServiceName($service);
				if(isSet($tmp[$name]))$dupe_classes[$name]=1;else $tmp[$name]=1;
			}
		}
		if($bd->deviceList){
			foreach($bd->deviceList[0]->device as $device){
				foreach($device->serviceList->service as $service){
					$name=$this->GetServiceName($service);
					if(isSet($tmp[$name]))$dupe_classes[$name]=1;else $tmp[$name]=1;
				}
			}
		}	
		$tmp=null;			
		// Erstelle Service Classen;
		if($bd->serviceList){
			foreach($bd->serviceList[0]->service as $service){
				$name=$this->GetServiceName($service);
				if(isSet($dupe_classes[$name])){
					$clName=$this->GetServiceName($service,true);
					$service_classes[]=$clname.'=new '.$this->ClassSuffix.$name."($"."this,'{$service->serviceType}','{$service->controlURL}','{$service->eventSubURL}');";
				}else {
					$service_classes[]=$name.'=new '.$this->ClassSuffix.$name.'($this);';
				}
				if(!isSet($services[$name])){
					$services[$name]=$this->CreateUpnpServiceClass($service);
				}	
			}
		}
		// Erstelle Device Classen;
		if($bd->deviceList){
			foreach($bd->deviceList[0]->device as $device){
				foreach($device->serviceList->service as $service){
					$name=$this->GetServiceName($service);
					if(isSet($dupe_classes[$name])){
						$clName=$this->GetServiceName($service,true);
						$device_classes[]=$clName.'=new '.$this->ClassSuffix.$name."($"."this,'{$service->serviceType}','{$service->controlURL}','{$service->eventSubURL}');";
					}else {	
						$device_classes[]=$name.'=new '.$this->ClassSuffix.$name.'($this);';
					}
					if(!isSet($services[$name])){
						$services[$name]=$this->CreateUpnpServiceClass($service);
					}	
				}
			}
		}
		$data=implode("\n",$services);
		$head=$this->CreateDeviceHead($url,$bd);
		$base=$this->CreateBaseUpnpClass().PHP_EOL;
		$master=$this->CreateMasterControlClass($service_classes,$device_classes, $bd).PHP_EOL;
		$this->Content=$head.$master.$base.$data;
		if($save){
			file_put_contents($this->ClassSuffix.'.class.php', "<?\n$this->Content\n?>");
			file_put_contents($this->ClassSuffix.'.class.def',"<?\n".$this->CreateFunctionReferenz()."\n?>");
		}	
		return $this->Content;
		
	}
	public function CreateFunctionReferenz(){
		$flist='';$typelist=[];
		$fl=str_repeat('-',50);
		foreach($this->VarDefs as $ServiceName=>$service){
			$flist.="$fl\nService : $ServiceName\n$fl\n";
			if(!$service)continue;
			foreach($service as $FunctionName=>$function){
				$flist.=" $FunctionName( ";
				//			echo "   $FunctionName<br>";
				if(!$function){
					$flist.=")\n";
					continue;
				}
				$vi=$vo=[];
				foreach($function as $VarName=>$var){
					$typ=$var['typ'];
					$v="$VarName($typ)";
					$typelist[$typ]=(isSet($typelist[$typ]))?++$typelist[$typ]:1;
					if($var['allowed'])$v.="=[{$var['allowed']}]";
					else if($var['default'])$v.='='.$var['default'];
					if($var['mode']=='in')$vi[]=$v;else $vo[]=$v;
				}
				$flist.=implode(', ',$vi).")";
				if(count($vo)>0)$flist.=' RETURNS '.implode(', ',$vo);
				$flist.=PHP_EOL;
			}
		}
		
		foreach($typelist as $n=>$v)$fa[]=sprintf("   %-15s : %s",$n,$v);
		$typelist="$fl\nVerwendete Variablen Typen & Anzahl\n".implode("\n",$fa).PHP_EOL;
		return $typelist.$flist;
	}
	
	public function CreateJSFunctionReferenz(){
		$flist="{\n";$sp1=str_repeat(' ',2);$sp2=str_repeat(' ',4);$sp3=str_repeat(' ',6);
		$flist.=$sp1.'init:function(){for (var o in this){if(typeof this[o]._B!=\'undefined\')this[o]._B=this;}},'.PHP_EOL;
		$flist.=$sp1."CallService:function (service,function,args){ alert('callService'+service+'.'+function);},\n";
		foreach($this->VarDefs as $ServiceName=>$service){
			if($ServiceName==$this->ClassSuffix.'UpnpDevice'||$ServiceName==$this->ClassSuffix.'UpnpClass')continue;
			$flist.="$sp1$ServiceName: { _B:null";
			if(!$service){
				$flist.="},\n";
				continue;
			}
			$flist.=",\n";
			
			foreach($service as $FunctionName=>$function){
				$func="$FunctionName:function (";	
				if(!$function){
					$flist.="$sp2$func){},\n";
					continue;
				}

				$f="return this._B.CallService('$ServiceName','$FunctionName'";
				$finner='';
				$va=[];
				foreach($function as $VarName=>$var){
					if($var['mode']=='in'){
						$va[]=$VarName;
						if (!is_null($var['default'])){
//echo "VAR $VarName  DEFAULT: {$var['default']}<br>";
							$finner="if (typeof $VarName=='undefined')$VarName='{$var['default']}';";
						}
					}
		
				}
				if(count($va)>0)$f.=',['.implode(',',$va)."]";
				
				$f.=");";
				$f=$finner.$f;
					
				
				$func.=implode(',',$va)."){ $f },\n";
		
				$flist.=$sp2.$func;
			}
				
			$flist.="$sp1},\n";
		}
		$flist.='}';
		
		return $flist;
	}
	public function CreateJSFunctionReferenz1(){
		$flist="{\n";$sp1=str_repeat(' ',2);$sp2=str_repeat(' ',4);$sp3=str_repeat(' ',6);
		foreach($this->VarDefs as $ServiceName=>$service){
			$flist.="$sp1$ServiceName: {";
			if(!$service){
				$flist.="},\n";
				continue;
			}
			$flist.="\n";
			foreach($service as $FunctionName=>$function){
				$flist.="$sp2$FunctionName: {";
				if(!$function){
					$flist.="},\n";
					continue;
				}
				$flist.="\n";$va=[];
				foreach($function as $VarName=>$var){
					$v="$VarName : { typ: '{$var['typ']}'";
					$v.=", mode:'{$var['mode']}'";

					$v.=", default: ".((!is_null($var['default'])&&$var['default']!='')?"'{$var['default']}'":'null');
					$v.=', allowed: '.(($var['allowed'])?"''":'null');	
					$v.="}";
					$va[]=$v;
				}
				$flist.=$sp3.implode(",\n$sp3",$va).PHP_EOL."$sp2},\n";
			}
				
			$flist.="$sp1},\n";
		}
		$flist.='},';
		
		return $flist;
	}
	
	private function GetServiceName($service, $full=false){
		preg_match('/service:(.+)\:/',(string)$service->serviceType,$matches); 
		$name=$matches[1];
		if($this->FullServiceNames||$full){
			$p=(string)$service->controlURL;
			if(!$p)$p=(string)$service->eventSubURL;
			if($p){
				$p=explode('/',$p);
				if(isSet($p[1])&&$p[1]!=$name)$name=$p[1].$name;
			}
		}
		return $name;
	}
	
	private function CreateDeviceHead($url,$device){
		$dt=date(DATE_W3C);
		if($device->serialNumber)
			$serial=$device->serialNumber;
		else if($device->serialNum)
			$serial=$device->serialNum;
		else $serial='';
		return <<<DATA
/*---------------------------------------------------------------------------/
	
File:  
	Desc     : PHP Classes to Control {$device->modelDescription} 
	Date     : {$dt}
	Version  : 1.00.45
	Publisher: (c)2015 Xaver Bauer 
	Contact  : x.bauer@tier-freunde.net

Device:
	Device Type  : {$device->deviceType}
	URL 		 : {$url}	
	Friendly Name: {$device->friendlyName}
	Manufacturer : {$device->manufacturer}
	URL 		 : {$device->manufacturerURL}
	Model        : {$device->modelDescription}
	Name 		 : {$device->modelName}
	Number 		 : {$device->modelNumber}
	URL 		 : {$device->modelURL}
	Serialnumber : {$serial}
	UDN          : {$device->UDN}

/*--------------------------------------------------------------------------*/

DATA;
	}
	private function CreateServiceHead($className, $service){
		if($this->Compact)return '';
		return '/*'.HEAD_LINE_CLASS."*/
/*  Class  : {$className} 
/*  Service: {$service->serviceType}
/*	     Id: {$service->serviceId} 
/*".HEAD_LINE_CLASS."*/";
	}	

	private function CreateFunctionHead($name, $in, $out, $offsetX=0){
		if($this->Compact)return '';
		$space=str_repeat(' ',$offsetX);
		$head[]="$space/*".HEAD_LINE_FUNC."\n$space/* ".MSG_FUNCTION." : $name\n$space/* ";
		if(count($in)>0){
			$head[]='  '.MSG_NEEDS.':';
			$head[]='    '.implode("\n$space/*    ",$in);
		}else $head[]='  '.MSG_NEEDS.': '.MSG_NOTHING;
		$head[]='';
		if(count($out)>0){
			if(count($out)>1){
				$head[]='  '.MSG_RETURNS.': '.MSG_ARRAY_KEYS;
			}else $head[]='  '.MSG_RETURNS.':';
			$head[]='    '.implode("\n$space/*    ",$out);
		}else $head[]='  '.MSG_RETURNS.': '.MSG_NOTHING;
		$head[]='';
		$head[]=HEAD_LINE_FUNC."*/".PHP_EOL;
		return implode("\n$space/*",$head);
	}

	private function CreateBaseUpnpClass($offsetX=0){
		$space=str_repeat(' ',$offsetX);
		$sp3='   ';$sp7='       ';
		$head=$space.'/*'.HEAD_LINE_CLASS."/\n";
		$ClassName="{$this->ClassSuffix}UpnpClass";
		$head.=<<<DATA
$space/*  Class  : $ClassName 
$space/*  Desc   : Basis Class for Services
$space/*	Vars   :
$space/*  private SERVICE     : (string) Service URN
$space/*  private SERVICEURL  : (string) Path to Service Control
$space/*  private EVENTURL    : (string) Path to Event Control
$space/*  public  BASE        : (Object) Points to MasterClass
DATA;
		$head.="\n$space/*".HEAD_LINE_CLASS."*/\n";
		$data=<<<DATA
{$space}class {$this->ClassSuffix}UpnpClass {
$space$sp3 protected \$SERVICE="";
$space$sp3 protected \$SERVICEURL="";
$space$sp3 protected \$EVENTURL="";
$space$sp3 var \$BASE=null;

DATA;
		$this->VarDefs[$ClassName]['__construct']['BASE']=array('mode'=>'in','default'=>null,'allowed'=>null,'typ'=>'object');
		$this->VarDefs[$ClassName]['__construct']['SERVICE']=array('mode'=>'in','default'=>null,'allowed'=>null,'typ'=>'string');
		$this->VarDefs[$ClassName]['__construct']['SERVICEURL']=array('mode'=>'in','default'=>null,'allowed'=>null,'typ'=>'string');
		$this->VarDefs[$ClassName]['__construct']['EVENTURL']=array('mode'=>'in','default'=>null,'allowed'=>null,'typ'=>'string');
		$in=array('@BASE (object) Referenz of MasterClass','@SERVICE (string) [Optional]','@SERVICEURL (string) [Optional]','@EVENTURL (string) [Optional]');
		$out=array();
		$output[1]['head']=$this->CreateFunctionHead('__construct',$in,$out,$offsetX+4);
		$output[1]['data']=<<<DATA
$space$sp3 public function __construct(\$BASE, \$SERVICE="", \$SERVICEURL="", \$EVENTURL=""){
$space$sp7 \$this->BASE=\$BASE;
$space$sp7 if(\$SERVICE)\$this->SERVICE=\$SERVICE;
$space$sp7 if(\$SERVICEURL)\$this->SERVICEURL=\$SERVICEURL;
$space$sp7 if(\$EVENTURL)\$this->EVENTURL=\$EVENTURL;
$space$sp3 }
DATA;
		$this->VarDefs[$ClassName]['RegisterEventCallback']['callback_url']=array('mode'=>'in','default'=>'','allowed'=>null,'typ'=>'string');
		$this->VarDefs[$ClassName]['RegisterEventCallback']['timeout']=array('mode'=>'in','default'=>'3600','allowed'=>null,'typ'=>'int');
		$this->VarDefs[$ClassName]['RegisterEventCallback']['SID']=array('mode'=>'out','default'=>'','allowed'=>null,'typ'=>'string');
		$this->VarDefs[$ClassName]['RegisterEventCallback']['TIMEOUT']=array('mode'=>'out','default'=>'','allowed'=>null,'typ'=>'int');
		$this->VarDefs[$ClassName]['RegisterEventCallback']['Server']=array('mode'=>'out','default'=>'','allowed'=>null,'typ'=>'string');
		$in=array('@callback_url (string) Url die bei Ereignissen aufgerufen wird','@timeout (int) Gueltigkeitsdauer der CallbackUrl');
		$out=array('@SID (string)','@TIMEOUT (int)','@Server (string)');
		$output[2]['head']=$this->CreateFunctionHead('RegisterEventCallback',$in,$out,$offsetX+4);
		$output[2]['data']=<<<DATA
$space$sp3 public function RegisterEventCallback(\$callback_url,\$timeout=300){
$space$sp7 if(!\$this->EVENTURL)return false;	
$space$sp7 \$content="SUBSCRIBE {\$this->EVENTURL} HTTP/1.1\nHOST: ".\$this->BASE->GetBaseUrl()."\nCALLBACK: <\$callback_url>\nNT: upnp:event\nTIMEOUT: Second-\$timeout\nContent-Length: 0\n\n";
$space$sp7 \$a=\$this->BASE->sendPacket(\$content);\$res=false;
$space$sp7 if(\$a)foreach(\$a as \$r){\$m=explode(':',\$r);if(isSet(\$m[1])){\$b=array_shift(\$m);\$res[\$b]=implode(':',\$m);}}
$space$sp7 return \$res;
$space$sp3 }
DATA;
		$this->VarDefs[$ClassName]['UnRegisterEventCallback']['SID']=array('mode'=>'in','default'=>null,'allowed'=>null,'typ'=>'string');;
		$in=array('@SID (string)');
		$out=array();
		$output[3]['head']=$this->CreateFunctionHead('UnRegisterEventCallback',$in,$out,$offsetX+4);
		$output[3]['data']=<<<DATA
$space$sp3 public function UnRegisterEventCallback(\$SID){ 
$space$sp7 if(!\$this->EVENTURL)return false;	
$space$sp7 \$content="UNSUBSCRIBE {\$this->EVENTURL} HTTP/1.1\nHOST: ".\$this->BASE->GetBaseUrl()."\nSID: \$SID\nContent-Length: 0\n\n";
$space$sp7 return \$this->BASE->sendPacket(\$content);
$space$sp3 }
DATA;
		foreach($output as $o){
			$data.=$o['head'].$o['data'].PHP_EOL;
		}	
		$data.="$space}";
		return $head.$data;
	}

	private function CreateMasterControlClass($service_classes,$device_classes, $masterdevice, $offsetX=0) {
		$space=str_repeat(' ',$offsetX);
		$sp3='   ';$sp7='       ';$sp11='           ';
		$msgServiceErr=MSG_NO_SERVICE;
		$msgFuncErr=MSG_NO_FUNCTION;
		$head=$space.'/*'.HEAD_LINE_CLASS."/\n";
		$ClassName="{$this->ClassSuffix}UpnpDevice";
		$head.=<<<DATA
$space/*  Class  : $ClassName 
$space/*  Desc   : Master Class to Controll Device 
$space/*	Vars   :
$space/*  private _SERVICES  : (object) Holder for all Service Classes
$space/*  private _DEVICES   : (object) Holder for all Service Classes
$space/*  private _IP        : (string) IP Adress from Device
$space/*  private _PORT      : (int)    Port from Device
DATA;
		$head.="\n$space/*".HEAD_LINE_CLASS."*/\n";
		$data=<<<DATA
{$space}class {$this->ClassSuffix}UpnpDevice {
$space$sp3 private \$_SERVICES=null;
$space$sp3 private \$_DEVICES=null;
$space$sp3 private \$_IP='';
$space$sp3 private \$_PORT=1400;
DATA;
		$this->VarDefs[$ClassName]['__construct']['url']=array('mode'=>'in','default'=>'','allowed'=>null,'typ'=>'string');
		$in=array('@url (string)  Device Url eg. \'192.168.1.1:1400\'');
		$out=array();
		$data.="\n$space".$this->CreateFunctionHead('__construct',$in,$out,$offsetX+4);
		$data.=<<<DATA
$space$sp3 public function __construct(\$url){
$space$sp7 \$p=parse_url(\$url);
$space$sp7 \$this->_IP=(isSet(\$p['host']))?\$p['host']:\$url;
$space$sp7 \$this->_PORT=(isSet(\$p['port']))?\$p['port']:1400;
$space$sp7 \$this->_SERVICES=new stdClass();
$space$sp7 \$this->_DEVICES=new stdClass();

DATA;
		foreach($service_classes as $c)$data.=$space.$sp7.' $this->_SERVICES->'.$c.PHP_EOL;
		foreach($device_classes as $c)$data.=$space.$sp7.' $this->_DEVICES->'.$c.PHP_EOL;
		$data.="$space$sp3 }\n";
		$data.=$this->CreateClassIconFunctions($masterdevice,$offsetX+4);

		
		$this->VarDefs[$ClassName]['Upnp']['url']=array('mode'=>'in','default'=>'','allowed'=>null,'typ'=>'string');
		$this->VarDefs[$ClassName]['Upnp']['SOAP_service']=array('mode'=>'in','default'=>'','allowed'=>null,'typ'=>'string');
		$this->VarDefs[$ClassName]['Upnp']['SOAP_action']=array('mode'=>'in','default'=>'','allowed'=>null,'typ'=>'string');
		$this->VarDefs[$ClassName]['Upnp']['SOAP_arguments']=array('mode'=>'in','default'=>'','allowed'=>null,'typ'=>'string');
		$this->VarDefs[$ClassName]['Upnp']['XML_filter']=array('mode'=>'in','default'=>'','allowed'=>null,'typ'=>'string');
		$this->VarDefs[$ClassName]['Upnp']['result']=array('mode'=>'out','default'=>'','allowed'=>null,'typ'=>'string|array');
		$in=array('@url (string)','@SOAP_service (string)','@SOAP_action (string)','@SOAP_arguments (sting) [Optional]','@XML_filter (string|stringlist|array of strings) [Optional]');
		$out=array('@result (string|array) => The XML Soap Result');
		$output[0]['head']=$this->CreateFunctionHead('Upnp',$in,$out,$offsetX+4);
		$output[0]['data']=<<<DATA
$space$sp3 public function Upnp(\$url,\$SOAP_service,\$SOAP_action,\$SOAP_arguments = '',\$XML_filter = ''){
$space$sp7 \$POST_xml = '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">';
$space$sp7 \$POST_xml .= '<s:Body>';
$space$sp7 \$POST_xml .= '<u:'.\$SOAP_action.' xmlns:u="'.\$SOAP_service.'">';
$space$sp7 \$POST_xml .= \$SOAP_arguments;
$space$sp7 \$POST_xml .= '</u:'.\$SOAP_action.'>';
$space$sp7 \$POST_xml .= '</s:Body>';
$space$sp7 \$POST_xml .= '</s:Envelope>';
$space$sp7 \$POST_url = \$this->_IP.":".\$this->_PORT.\$url;
$space$sp7 \$ch = curl_init();
$space$sp7 curl_setopt(\$ch, CURLOPT_SSL_VERIFYPEER, 0);
$space$sp7 curl_setopt(\$ch, CURLOPT_SSL_VERIFYHOST, 0);
$space$sp7 curl_setopt(\$ch, CURLOPT_URL, \$POST_url);
$space$sp7 curl_setopt(\$ch, CURLOPT_HEADER, 0);
$space$sp7 curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, 1);
$space$sp7 curl_setopt(\$ch, CURLOPT_TIMEOUT, 30);
$space$sp7 curl_setopt(\$ch, CURLOPT_HTTPHEADER, array("Content-Type: text/xml", "SOAPAction: ".\$SOAP_service."#".\$SOAP_action));
$space$sp7 curl_setopt(\$ch, CURLOPT_POST, 1);
$space$sp7 curl_setopt(\$ch, CURLOPT_POSTFIELDS, \$POST_xml);
$space$sp7 \$r = curl_exec(\$ch);
$space$sp7 curl_close(\$ch);
$space$sp7 if (\$XML_filter != '')
$space$sp11 return \$this->Filter(\$r,\$XML_filter);
$space$sp7 else
$space$sp11 return \$r;
$space$sp3 }
DATA;
		$this->VarDefs[$ClassName]['Filter']['subject']=array('mode'=>'in','default'=>'','allowed'=>null,'typ'=>'string');
		$this->VarDefs[$ClassName]['Filter']['pattern']=array('mode'=>'in','default'=>'','allowed'=>null,'typ'=>'string|stringlist|array of strings');
		$this->VarDefs[$ClassName]['Filter']['result']=array('mode'=>'out','default'=>'','allowed'=>null,'typ'=>'variant');
		$in=array('@subject (string)','@pattern (string|stringlist|array of strings)');
		$out=array('@result (array|variant) => Array format FilterPattern=>Value');
		$output[1]['head']=$this->CreateFunctionHead('Filter',$in,$out,$offsetX+4);
		$output[1]['data']=<<<DATA
$space$sp3 public function Filter(\$subject,\$pattern){
$space$sp7 \$multi=is_array(\$pattern);
$space$sp7 if(!\$multi){
$space$sp11 \$pattern=explode(',',\$pattern);
$space$sp11 \$multi=(count(\$pattern)>1);
$space$sp7 }	
$space$sp7 foreach(\$pattern as \$pat){
$space$sp11 if(!\$pat)continue;
$space$sp11 preg_match('/\<'.\$pat.'\>(.+)\<\/'.\$pat.'\>/',\$subject,\$matches);
$space$sp11 if(\$multi)\$n[\$pat]=(isSet(\$matches[1]))?\$matches[1]:false;
$space$sp11 else return (isSet(\$matches[1]))?\$matches[1]:false;
$space$sp7 }	
$space$sp7 return \$n;
$space$sp3 }
DATA;
		$in=array();
		$out=array('@result (array) => Namen der vorhandenen Services');
		$output[2]['head']=$this->CreateFunctionHead('GetServiceNames',$in,$out,$offsetX+4);
		$output[2]['data']=<<<DATA
$space$sp3 public function GetServiceNames(){
$space$sp7 foreach(\$this->_SERVICES as \$fn=>\$tmp)if(substr(\$fn,0,1)!='_')\$n[]=\$fn;
$space$sp7 foreach(\$this->_DEVICES as \$fn=>\$tmp)if(substr(\$fn,0,1)!='_')\$n[]=\$fn;
$space$sp7 return \$n;
$space$sp3 }
DATA;
		$in=array('@ServiceName (string)');
		$out=array('@result (array) => Namen der vorhandenen Service Funktionen');
		$output[3]['head']=$this->CreateFunctionHead('GetServiceFunctionNames',$in,$out,$offsetX+4);
		$output[3]['data']=<<<DATA
$space$sp3 public function GetServiceFunctionNames(\$ServiceName){
$space$sp7 if(isSet(\$this->_SERVICES->\$ServiceName)){
$space$sp11 \$p=&\$this->_SERVICES->\$ServiceName;
$space$sp7 }else if(isSet(\$this->_DEVICES->\$ServiceName)){
$space$sp11 \$p=&\$this->_DEVICES->\$ServiceName;
$space$sp7 }else throw new Exception('Unbekanner Service-Name '.\$ServiceName.' !!!');
$space$sp7 foreach(get_class_methods(\$p) as \$fn)if(substr(\$fn,0,1)!='_')\$n[]=\$fn;
$space$sp7 return \$n;	
$space$sp3 }
DATA;
		$in=array('@ServiceName (string)','@FunctionName (string)','@Arguments (string,array) [Optional] Funktions Parameter');
		$out=array('@result (array|variant) => siehe Funktion');
		$output[4]['head']=$this->CreateFunctionHead('CallService',$in,$out,$offsetX+4);
		$tmp=<<<DATA
$space$sp3 public function CallService(\$ServiceName, \$FunctionName, \$Arguments=null){
$space$sp7 if(is_object(\$ServiceName))\$p=\$ServiceName;
$space$sp7 else if(isSet(\$this->_SERVICES->\$ServiceName)){
$space$sp11 \$p=&\$this->_SERVICES->\$ServiceName;
$space$sp7 }else if(isSet(\$this->_DEVICES->\$ServiceName)){
$space$sp11 \$p=&\$this->_DEVICES->\$ServiceName;
$space$sp7 }else throw new Exception('$msgServiceErr '.\$ServiceName.' !!!');
$space$sp7 if(!method_exists(\$p,\$FunctionName)) throw new Exception('$msgFuncErr '.\$FunctionName.' !!! Service:'.\$ServiceName);
$space$sp7 if(!is_null(\$Arguments)){
$space$sp11 \$a=&\$Arguments;
$space$sp11 if (!is_array(\$a))\$a=Array(\$a);
$space$sp11 switch(count(\$a)){\n
DATA;
		for($j=1;$j<$this->MaxArguments;$j++){
			$tmp.="$space$sp7$sp7  case $j: return \$p->\$FunctionName(";
			$at=[];
			for($x=0; $x<$j;$x++){
				$at[]="\$a[$x]";
			}	
			$tmp.=implode(',',$at).");break;\n";
		}
		$tmp.=<<<DATA
$space$sp7$sp7  default: return \$p->\$FunctionName();
$space$sp11 }
$space$sp7 }else return \$p->\$FunctionName();
$space$sp3 }
DATA;
		$output[4]['data']=$tmp;


		$in=array('@FunctionName (string)','@arguments (array)');
		$out=array('@result (variant) => siehe aufzurufende Funktion');
		$output[5]['head']=$this->CreateFunctionHead('__call',$in,$out,$offsetX+4);
		$output[5]['data']=<<<DATA
$space$sp3 public function __call(\$FunctionName, \$arguments){
$space$sp7 if(!\$p=\$this->_ServiceObjectByFunctionName(\$FunctionName))
$space$sp11 throw new Exception('Unbekannte Funktion '.\$FunctionName.' !!!');
$space$sp7 return \$this->CallService(\$p,\$FunctionName, \$arguments);
$space$sp3 }
DATA;
		$in=array('@FunctionName (string)');
		$out=array('@result (function||null) ServiceObject mit der gusuchten Function');
		$output[6]['head']=$this->CreateFunctionHead('_ServiceObjectByFunctionName',$in,$out,$offsetX+4);
		$output[6]['data']=<<<DATA
$space$sp3 private function _ServiceObjectByFunctionName(\$FunctionName){
$space$sp7 foreach(\$this->_SERVICES as \$fn=>\$tmp)if(method_exists(\$this->_SERVICES->\$fn,\$FunctionName)){return \$this->_SERVICES->\$fn;}
$space$sp7 foreach(\$this->_DEVICES as \$fn=>\$tmp)if(method_exists(\$this->_DEVICES->\$fn,\$FunctionName)){return \$this->_DEVICES->\$fn;}
$space$sp7 return false;
$space$sp3 }
DATA;
		$in=array('@content (string)');
		$out=array('@result (array)');
		$output[7]['head']=$this->CreateFunctionHead('sendPacket',$in,$out,$offsetX+4);
		$output[7]['data']=<<<DATA
$space$sp3 public function sendPacket( \$content ){
$space$sp7 \$fp = fsockopen(\$this->_IP, \$this->_PORT, \$errno, \$errstr, 10);
$space$sp7 if (!\$fp)throw new Exception("Error opening socket: ".\$errstr." (".\$errno.")");
$space$sp11 fputs (\$fp,\$content);\$ret = "";
$space$sp11 while (!feof(\$fp))\$ret.= fgetss(\$fp,128); // filters xml answer
$space$sp11 fclose(\$fp);
$space$sp7 if(strpos(\$ret, "200 OK") === false)throw new Exception("Error sending command: ".\$ret);
$space$sp7 foreach(preg_split("/\\n/", \$ret) as \$v)if(trim(\$v)&&(strpos(\$v,"200 OK")===false))\$array[]=trim(\$v);
$space$sp7 return \$array;
$space$sp3 }
DATA;
		$in=array();
		$out=array('@result (string)');
		$output[8]['head']=$this->CreateFunctionHead('GetBaseUrl',$in,$out,$offsetX+4);
		$output[8]['data']=<<<DATA
$space$sp3 public function GetBaseUrl(){ 
$space$sp7 return \$this->_IP.':'.\$this->_PORT;
$space$sp3 }
DATA;
		foreach($output as $o){
			$data.=$o['head'].$o['data'].PHP_EOL;
		}	
		$data.="$space}";	
		return $head.$data;
	}
	
	private function CreateUpnpServiceClass($service,$offsetX=0,$fullServiceName=false){
		$space=str_repeat(' ',$offsetX);
		
		$name=$this->GetServiceName($service,$fullServiceName);
		$head=$this->CreateServiceHead(	$name, $service);
		$data=<<<DATA
{$space}class {$this->ClassSuffix}{$name} extends {$this->ClassSuffix}UpnpClass {
{$space}    protected \$SERVICE='{$service->serviceType}';
{$space}    protected \$SERVICEURL='{$service->controlURL}';
{$space}    protected \$EVENTURL='{$service->eventSubURL}';
DATA;
		$data.=PHP_EOL.$space;
		if($service->SCPDURL){
			$xml = simplexml_load_file($this->BaseUrl.$service->SCPDURL);
			if($xml){
				// Varaiablen Info Tabelle
				$VarTable=[];
				foreach($xml->serviceStateTable->stateVariable as $var){
					$vname=(string)$var->name; $values=[];
					//echo "Name: '$name'<br>";	
					$VarTable[$vname]['typ']=(string)$var->dataType;
					if($var->allowedValueList){
						foreach($var->allowedValueList->allowedValue as $value){
							$values[]=(string)$value;
						}
						$VarTable[$vname]['allowed']=implode('|',$values);
					}	
				}	
				foreach($xml->actionList[0]->action as $action){
					$f[]=$this->CreateUpnpClassFunction($action,$VarTable, $offsetX+4, $name);
				}	
				$data.=implode("\n",$f);
			}
		}
		$data.="$space}\n";	
		return $head.PHP_EOL.$data;
	}

	private function CreateUpnpClassFunction($action,&$VarTable, $offsetX, $ClassName){
		$in=$out=$outFilter=$headIn=$headOut=[];
		$space=str_repeat(' ',$offsetX);
		$fname=(string)$action->name;
		$data="{$space}public function $fname";
		$args="";
		$info=null;
		if($action->argumentList->argument)
		foreach($action->argumentList->argument as $argument){
			$name=(string)$argument->name;
			$mode=(string)$argument->direction;
			$info[$name]['mode']=$mode;
			$info[$name]['default']=null;
			$info[$name]['allowed']=null;
			$info[$name]['typ']='unknown';
			$defaultValue='';
			if($mode=='in'){
				if(isSet($this->VarDefaults[$name])){
					$defaultValue=$this->VarDefaults[$name];
					$in[]='$'.$name.'='.$defaultValue;
					$info[$name]['default']=$defaultValue;
					$defaultValue=MSG_DEFAULT." = $defaultValue ";
				}else $in[]='$'.$name;
				$args.="<$name>$".$name."</$name>";
				$headPtr=&$headIn;
			}else if($mode=='out'){
				$out[]='$'.$name;
				$outFilter[]=$name;
				$headPtr=&$headOut;
			}
			if($argument->relatedStateVariable){
				$vname=(string)$argument->relatedStateVariable;
				if(isSet($VarTable[$vname])){
					$info[$name]['typ']=$VarTable[$vname]['typ'];
					
					$info[$name]['allowed']=isSet($VarTable[$vname]['allowed'])?$VarTable[$vname]['allowed']:null;
					$headPtr[]=$space.'  @'.$name.' ('.$VarTable[$vname]['typ'].') '.$defaultValue.((isSet($VarTable[$vname]['allowed']))?' '.MSG_ALLOWED.': '.$VarTable[$vname]['allowed']:'');
				}else $headPtr[]=$space.'  @'.$name;	
			}
		}
		$this->VarDefs[$ClassName][$fname]=$info;
		if(count($in)>$this->MaxArguments)$this->MaxArguments=count($in);
		$data.='('.implode(', ',$in).'){'.PHP_EOL;
		$data.=$space.'    $args="'.$args.'";'.PHP_EOL;
		$data.=$space.'    $filter="'.implode(',',$outFilter).'";'.PHP_EOL;
		$data.=$space.'    return $this->BASE->Upnp($this->SERVICEURL,$this->SERVICE,\''.$action->name.'\',$args,$filter);'.PHP_EOL;
		$data.="$space}";

		$head=$this->CreateFunctionHead((string)$action->name,$headIn,$headOut,$offsetX);
		return $head.$data;
		
		$data=implode("\n$space/*",$head).$data;
		
		return $data;
	}	
	
	private function CreateClassIconFunctions($device,$offsetX=0){
		$space=str_repeat(' ',$offsetX);
		$in=array('@IconNr (int)');
		$out=array('@width  (int)','@height (int)','@url (string)');
		$head=$this->CreateFunctionHead('GetIcon',$in,$out,$offsetX);
		
		$data=$space.'function GetIcon($id) {'.PHP_EOL;
		$data.=$space.'    switch($id){'.PHP_EOL;
		$iconcount=0;
		if($device->iconList){
			foreach($device->iconList[0]->icon as $icon){
				$idd=$iconcount++;
				$url=$this->BaseUrl.$icon->url;
				$data.="$space        case $idd : return array('width'=>{$icon->width},'height'=>{$icon->height},'url'=>'{$url}');break;\n";
			}
		}
		$data.="$space    }\n$space    return array('width'=>0,'height'=>0,'url'=>'');\n$space}\n";
		$in=[];
		$out=array('@count (int) => The Numbers of Icons Avail');
		
		$head1=$this->CreateFunctionHead('IconCount',$in,$out,$offsetX);
		$data1=$space.'function IconCount() { return '.$iconcount.";}\n";
		
		return $head.$data.$head1.$data1;
	}
}	

//$url='http://192.168.112.60:7676/smp_14_';
$url='http://192.168.112.56:1400/xml/device_description.xml';
//$url='http://192.168.112.18:32469/DeviceDescription.xml';

$o=new SchemeCreator();
$s=$o->Create($url);
//$s=$o->CreateJSFunctionReferenz();


echo "Ausgabe:<br><plain>".nl2br(str_replace(' ','&nbsp;',htmlspecialchars($s))).'</plain>';
exit;




	



/*
// Test 1
$xml = simplexml_load_file('http://192.168.112.54:1400/xml/AlarmClock1.xml');
$s=CreateUpnpClassFunction($xml->actionList[0]->action[0]);
echo "Ausgabe:<br><plain>".nl2br(htmlspecialchars($s)).'</plain>';
exit;
*/
/*
// Test 2
$xml = simplexml_load_file('http://192.168.112.56:1400/xml/device_description.xml');
$s=CreateUpnpServiceClass($xml->device->serviceList[0]->service[0],'Xavers');
echo "Ausgabe:<br><plain>".nl2br(str_replace(' ','&nbsp;',htmlspecialchars($s))).'</plain>';
exit;
*/





	
?>