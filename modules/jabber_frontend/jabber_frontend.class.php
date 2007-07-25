<?php
class JabberFrontend extends DragoonModule
{
    protected $module_description = 'Jabber Frontend';
    protected $module_version = '1.0.0';
    protected $module_author = 'OwlManAtt <owlmanatt@gmail.com>';
    protected $module_type = 'Frontend';

    // Jabber internals.
    protected $server_real;
	protected $server;
	protected $port;
	protected $username;
	protected $password;
	protected $resource;
	protected $jid;

	protected $connection;
	protected $delay_disconnect;
    protected $last_disconnect_retry;
    protected $reconnect_interval = 120;

	protected $stream_id;
	protected $roster;

	protected $iq_sleep_timer;
	protected $last_ping_time;

	protected $packet_queue;
	protected $subscription_queue;

	protected $iq_version_name;
	protected $iq_version_os;
	protected $iq_version_version;

	protected $error_codes;

	protected $connected;
	protected $keep_alive_id;
	protected $returned_keep_alive;
	protected $txnid;

	protected $CONNECTOR;

    public function __construct(&$dragoon,&$db)
	{
        parent::__construct(&$dragoon,&$db);

        $this->logMessage("Constructing Jabber frontend module."); 
        
		$this->packet_queue	= array();
		$this->subscription_queue = array();

		$this->iq_sleep_timer = 1;
		$this->delay_disconnect	= 1;

		$this->returned_keep_alive = TRUE;
		$this->txnid = 0;

		$this->iq_version_name = "Class.Jabber.PHP-YS ";
		$this->iq_version_version = "0.5-ys";
		$this->iq_version_os = $_SERVER['SERVER_SOFTWARE'];

		$this->connection_class = "CJP_StandardConnector";

        $this->error_codes = array(
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Not Allowed',
            406 => 'Not Acceptable',
            407 => 'Registration Required',
            408 => 'Request Timeout',
            409 => 'Conflict',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Remove Server Error',
            503 => 'Service Unavailable',
            504 => 'Remove Server Timeout',
            510 => 'Disconnected'
        );
	} // end __construct

    public function load()
    {
        $this->logMessage('load()ing jabber frontend module...');

        $file = 'modules/jabber_frontend/config.yaml';
        $CONFIG = Spyc::YAMLLoad($file);

        $this->server = $CONFIG['server'];
        $this->server_real = $CONFIG['server'];

        // Connect to jabber.yasashiisyndicate.org, but its
        // name is actually yasashiisyndicate.org.
        if($CONFIG['real_server'] != null)
        {
            $this->server_real = $CONFIG['real_server'];
        }
        
		$this->port = $CONFIG['port'];
		$this->username = $CONFIG['username'];
		$this->password	= $CONFIG['password'];
		$this->resource	= $CONFIG['resource'];

        if($this->connect())
        {
            if($this->sendAuth())
            {
                $this->SendPresence(null,null,'online');
                $this->dragoon->registerRunMethod('jabber_frontend','cruisecontrol',array(1));
            }
            else
            {
                $this->logMessage('Could not authenticate to Jabber server.','crit');
            }
        }
        else
        {
            return false;
        }

        return true;
    } // end load
    
    public function unload()
    {
        $this->logMessage('Disconnecting from Jabber....');
        $this->disconnect();

        return true;
    } // end unload

    public function connect()
	{
		$this->CONNECTOR = new $this->connection_class;

		if($this->CONNECTOR->OpenSocket($this->server_real, $this->port))
		{
			$this->SendPacket("<?xml version='1.0' encoding='UTF-8' ?" . ">\n");
			$this->SendPacket("<stream:stream to='{$this->server}' xmlns='jabber:client' xmlns:stream='http://etherx.jabber.org/streams'>\n");

			sleep(5);

			if($this->_check_connected())
			{
                $this->dragoon->unregisterRunMethod('jabber_frontent','reconnect');
				$this->connected = TRUE;	// Nathan Fritz
				return TRUE;
			}
			else
			{
				$this->logMessage("ERROR: Connect() #1",'notice');
				return FALSE;
			}
		}
		else
		{
			$this->logMessage("ERROR: Connect() #2",'notice');
			return FALSE;
		}
	} // end connect

    public function reconnect()
    {
        if(($this->last_disconnect_retry + $this->reconnect_interval) >= time())
        {
            $this->connect();
            $this->last_disconnect_retry = time();
        }

        return null;
    } // end reconnect

	public function disconnect()
	{
		if(is_int($this->delay_disconnect))
		{
			sleep($this->delay_disconnect);
		}

		$this->SendPacket("</stream:stream>");
		$this->CONNECTOR->CloseSocket();
	} // end disconnect



	public function sendAuth()
	{
		$this->auth_id	= "auth_" . md5(time() . $_SERVER['REMOTE_ADDR']);

		$this->resource	= ($this->resource != NULL) ? $this->resource : ("Class.Jabber.PHP " . md5($this->auth_id));
		$this->jid		= "{$this->username}@{$this->server}/{$this->resource}";

		// request available authentication methods
		$payload	= "<username>{$this->username}</username>";
		$packet		= $this->SendIq(NULL, 'get', $this->auth_id, "jabber:iq:auth", $payload);
        sleep(2);

		// was a result returned?
		if($this->GetInfoFromIqType($packet) == 'result' && $this->GetInfoFromIqId($packet) == $this->auth_id)
		{
			// yes, now check for auth method availability in descending order (best to worst)

			// if(!function_exists(mhash))
			if(false)
			{
				$this->logMessage("ATTENTION: SendAuth() - mhash() is not available; screw 0k and digest method, we need to go with plaintext auth",'notice');
			}

			// auth_0k
			if(function_exists(mhash) && isset($packet['iq']['#']['query'][0]['#']['sequence'][0]["#"]) && isset($packet['iq']['#']['query'][0]['#']['token'][0]["#"]))
			{
				return $this->_sendauth_0k($packet['iq']['#']['query'][0]['#']['token'][0]["#"], $packet['iq']['#']['query'][0]['#']['sequence'][0]["#"]);
			}
			// digest
			elseif(function_exists(mhash) && isset($packet['iq']['#']['query'][0]['#']['digest']))
			{
				return $this->_sendauth_digest();
			}
			// plain text
			elseif($packet['iq']['#']['query'][0]['#']['password'])
			{
				return $this->_sendauth_plaintext();
			}
			// dude, you're fucked
			{
				$this->logMessage("ERROR: SendAuth() #2 - No auth method available!",'notice');
				return FALSE;
			}
		}
		else
		{
			// no result returned
			$this->logMessage("ERROR: SendAuth() #1");
			return FALSE;
		}
	} // end sendAuth

	public function accountRegistration($reg_email = NULL, $reg_name = NULL)
	{
		$packet = $this->SendIq($this->server, 'get', 'reg_01', 'jabber:iq:register');

		if($packet)
		{
			$key = $this->GetInfoFromIqKey($packet);	// just in case a key was passed back from the server
			unset($packet);

			$payload = "<username>{$this->username}</username>
						<password>{$this->password}</password>
						<email>$reg_email</email>
						<name>$reg_name</name>\n";

			$payload .= ($key) ? "<key>$key</key>\n" : '';

			$packet = $this->SendIq($this->server, 'set', "reg_01", "jabber:iq:register", $payload);

			if($this->GetInfoFromIqType($packet) == 'result')
			{
				if(isset($packet['iq']['#']['query'][0]['#']['registered'][0]['#']))
				{
					$return_code = 1;
				}
				else
				{
					$return_code = 2;
				}

				if($this->resource)
				{
					$this->jid = "{$this->username}@{$this->server}/{$this->resource}";
				}
				else
				{
					$this->jid = "{$this->username}@{$this->server}";
				}

			}
			elseif($this->GetInfoFromIqType($packet) == 'error' && isset($packet['iq']['#']['error'][0]['#']))
			{
				// "conflict" error, i.e. already registered
				if($packet['iq']['#']['error'][0]['@']['code'] == '409')
				{
					$return_code = 1;
				}
				else
				{
					$return_code = "Error " . $packet['iq']['#']['error'][0]['@']['code'] . ": " . $packet['iq']['#']['error'][0]['#'];
				}
			}

			return $return_code;

		}
		else
		{
			return 3;
		}
	} // end accountRegistration

	protected function sendPacket($xml)
	{
		$xml = trim($xml);

		if($this->CONNECTOR->WriteToSocket($xml))
		{
			$this->logMessage("SEND: $xml",'debug');
			return TRUE;
		}
		else
		{
			$this->logMessage('ERROR: SendPacket() #1');
			return FALSE;
		}
	} // end sendPacket

	public function listen()
	{
		unset($incoming);

		while($line = $this->CONNECTOR->ReadFromSocket(4096))
		{
			$incoming .= $line;
		}

		$incoming = trim($incoming);

		if($incoming != "")
		{
			$this->logMessage("RECV: $incoming",'debug');
		}

		if($incoming != "")
		{
			$temp = $this->_split_incoming($incoming);

			for ($a = 0; $a < count($temp); $a++)
			{
				$this->packet_queue[] = $this->xmlize($temp[$a]);
			}
		}

		return TRUE;
	} // end listen

	protected function stripJID($jid = NULL)
	{
		preg_match("/(.*)\/(.*)/Ui", $jid, $temp);
		return ($temp[1] != "") ? $temp[1] : $jid;
	} // end stripJID


	public function sendMessage($to, $type = "normal", $id = NULL, $content = NULL, $payload = NULL)
	{
        // $this->logMessage("'$to'",'debug');
        // $this->logMessage(print_r($content,true),'debug');
        
		if($to && is_array($content))
		{
			if(!$id)
			{
				$id = $type . "_" . time();
			}

			$content = $this->_array_htmlspecialchars($content);

			$xml = "<message to='$to' type='$type' id='$id'>\n";

			if($content['subject'])
			{
				$xml .= "<subject>" . $content['subject'] . "</subject>\n";
			}

			if($content['thread'])
			{
				$xml .= "<thread>" . $content['thread'] . "</thread>\n";
			}

			$xml .= "<body>" . htmlentities($content['body']) . "</body>\n";
			$xml .= $payload;
			$xml .= "</message>\n";


			if($this->SendPacket($xml))
			{
				return TRUE;
			}
			else
			{
				$this->logMessage("ERROR: SendMessage() #1");
				return FALSE;
			}
		}
		else
		{
			$this->logMessage("ERROR: SendMessage() #2");
			return FALSE;
		}
	} // end sendMessage

	public function sendPresence($type = NULL, $to = NULL, $status = NULL, $show = NULL, $priority = NULL)
	{
		$xml = "<presence";
		$xml .= ($to) ? " to='$to'" : '';
		$xml .= ($type) ? " type='$type'" : '';
		$xml .= ($status || $show || $priority) ? ">\n" : " />\n";

		$xml .= ($status) ? "	<status>$status</status>\n" : '';
		$xml .= ($show) ? "	<show>$show</show>\n" : '';
		$xml .= ($priority) ? "	<priority>$priority</priority>\n" : '';

		$xml .= ($status || $show || $priority) ? "</presence>\n" : '';

		if($this->SendPacket($xml))
		{
			return TRUE;
		}
		else
		{
			$this->logMessage("ERROR: SendPresence() #1");
			return FALSE;
		}
	} // end sendPresence

	public function sendError($to, $id = NULL, $error_number, $error_message = NULL)
	{
		$xml = "<iq type='error' to='$to'";
		$xml .= ($id) ? " id='$id'" : '';
		$xml .= ">\n";
		$xml .= "	<error code='$error_number'>";
		$xml .= ($error_message) ? $error_message : $this->error_codes[$error_number];
		$xml .= "</error>\n";
		$xml .= "</iq>";

		$this->SendPacket($xml);
	} // end sendError

	public function RosterUpdate()
	{
		$roster_request_id = "roster_" . time();

		$incoming_array = $this->SendIq(NULL, 'get', $roster_request_id, "jabber:iq:roster");

		if(is_array($incoming_array))
		{
			if($incoming_array['iq']['@']['type'] == 'result'
				&& $incoming_array['iq']['@']['id'] == $roster_request_id
				&& $incoming_array['iq']['#']['query']['0']['@']['xmlns'] == "jabber:iq:roster")
			{
				$number_of_contacts = count($incoming_array['iq']['#']['query'][0]['#']['item']);
				$this->roster = array();

				for ($a = 0; $a < $number_of_contacts; $a++)
				{
					$this->roster[$a] = array(	"jid"			=> strtolower($incoming_array['iq']['#']['query'][0]['#']['item'][$a]['@']['jid']),
												"name"			=> $incoming_array['iq']['#']['query'][0]['#']['item'][$a]['@']['name'],
												"subscription"	=> $incoming_array['iq']['#']['query'][0]['#']['item'][$a]['@']['subscription'],
												"group"			=> $incoming_array['iq']['#']['query'][0]['#']['item'][$a]['#']['group'][0]['#']
											);
				}

				return TRUE;
			}
			else
			{
				$this->logMessage("ERROR: RosterUpdate() #1");
				return FALSE;
			}
		}
		else
		{
			$this->logMessage("ERROR: RosterUpdate() #2");
			return FALSE;
		}
	} // end rosterUpdate

	public function RosterAddUser($jid = NULL, $id = NULL, $name = NULL)
	{
		$id = ($id) ? $id : "adduser_" . time();

		if($jid)
		{
			$payload = "		<item jid='$jid'";
			$payload .= ($name) ? " name='" . htmlspecialchars($name) . "'" : '';
			$payload .= "/>\n";

			$packet = $this->SendIq(NULL, 'set', $id, "jabber:iq:roster", $payload);

			if($this->GetInfoFromIqType($packet) == 'result')
			{
				$this->RosterUpdate();
				return TRUE;
			}
			else
			{
				$this->logMessage("ERROR: RosterAddUser() #2");
				return FALSE;
			}
		}
		else
		{
			$this->logMessage("ERROR: RosterAddUser() #1");
			return FALSE;
		}
	} // end rosterAddUser

	public function RosterRemoveUser($jid = NULL, $id = NULL)
	{
		$id = ($id) ? $id : 'deluser_' . time();

		if($jid && $id)
		{
			$packet = $this->SendIq(NULL, 'set', $id, "jabber:iq:roster", "<item jid='$jid' subscription='remove'/>");

			if($this->GetInfoFromIqType($packet) == 'result')
			{
				$this->RosterUpdate();
				return TRUE;
			}
			else
			{
				$this->logMessage("ERROR: RosterRemoveUser() #2");
				return FALSE;
			}
		}
		else
		{
			$this->logMessage("ERROR: RosterRemoveUser() #1");
			return FALSE;
		}
	} // end removeRosterUser

	public function RosterExistsJID($jid = NULL)
	{
		if($jid)
		{
			if($this->roster)
			{
				for ($a = 0; $a < count($this->roster); $a++)
				{
					if($this->roster[$a]['jid'] == strtolower($jid))
					{
						return $a;
					}
				}
			}
			else
			{
				$this->logMessage("ERROR: RosterExistsJID() #2");
				return FALSE;
			}
		}
		else
		{
			$this->logMessage("ERROR: RosterExistsJID() #1");
			return FALSE;
		}
	} // end RosterExistsJID

	protected function getFirstFromQueue()
	{
		return array_shift($this->packet_queue);
	} // end getFirstFromQueue

	protected function getFromQueueById($packet_type, $id)
	{
		$found_message = FALSE;

		foreach ($this->packet_queue as $key => $value)
		{
			if($value[$packet_type]['@']['id'] == $id)
			{
				$found_message = $value;
				unset($this->packet_queue[$key]);

				break;
			}
		}

		return (is_array($found_message)) ? $found_message : FALSE;
	} // end getFromQueueById

	protected function callHandler($packet = NULL)
	{
		$packet_type	= $this->_get_packet_type($packet);

		if($packet_type == "message")
		{
			$type		= $packet['message']['@']['type'];
			$type		= ($type != "") ? $type : "normal";
			$funcmeth	= "Handler_message_$type";
		}
		elseif($packet_type == "iq")
		{
			$namespace	= $packet['iq']['#']['query'][0]['@']['xmlns'];
			$namespace	= str_replace(":", "_", $namespace);
			$funcmeth	= "Handler_iq_$namespace";
		}
		elseif($packet_type == "presence")
		{
			$type		= $packet['presence']['@']['type'];
			$type		= ($type != "") ? $type : "available";
			$funcmeth	= "Handler_presence_$type";
		}


		if($funcmeth != '')
		{
			if(method_exists($this, $funcmeth))
			{
				call_user_func(array(&$this, $funcmeth), $packet);
			}
			else
			{
				$this->Handler_NOT_IMPLEMENTED($packet);
				$this->logMessage("ERROR: CallHandler() #1 - neither method nor function $funcmeth() available");
			}
		}
	} // end callHandler


	public function CruiseControl($seconds = -1)
	{
		$count = 0;

		while ($count != $seconds)
		{
			$this->Listen();

			do {
				$packet = $this->GetFirstFromQueue();

				if($packet) {
					$this->CallHandler($packet);
				}

			} while (count($this->packet_queue) > 1);

			$count += 0.25;
			usleep(250000);
			
			if($this->last_ping_time + 180 < time())
			{
				// Modified by Nathan Fritz
				if($this->returned_keep_alive == FALSE)
				{
					$this->connected = FALSE;
					$this->logMessage('EVENT: Disconnected','debug');
                    $this->dragoon->registerRunMethod('jabber_frontent','reconnect');
				}
				if($this->returned_keep_alive == TRUE)
				{
					$this->connected = TRUE;
				}

				$this->returned_keep_alive = FALSE;
				$this->keep_alive_id = 'keep_alive_' . time();
				//$this->SendPacket("<iq id='{$this->keep_alive_id}'/>", 'CruiseControl');
				$this->SendPacket("<iq type='get' from='" . $this->username . "@" . $this->server . "/" . $this->resource . "' to='" . $this->server . "' id='" . $this->keep_alive_id . "'><query xmlns='jabber:iq:time' /></iq>");
				// **

				$this->last_ping_time = time();
			}
		}

		return TRUE;
	} // end cruiseControl



	public function SubscriptionAcceptRequest($to = NULL)
	{
		return ($to) ? $this->SendPresence("subscribed", $to) : FALSE;
	}

	public function SubscriptionDenyRequest($to = NULL)
	{
		return ($to) ? $this->SendPresence("unsubscribed", $to) : FALSE;
	}

	public function Subscribe($to = NULL)
	{
		return ($to) ? $this->SendPresence("subscribe", $to) : FALSE;
	}

	public function Unsubscribe($to = NULL)
	{
		return ($to) ? $this->SendPresence("unsubscribe", $to) : FALSE;
	}

	public function SendIq($to = NULL, $type = 'get', $id = NULL, $xmlns = NULL, $payload = NULL, $from = NULL)
	{
		if(!preg_match("/^(get|set|result|error)$/", $type))
		{
			unset($type);

			$this->logMessage("ERROR: SendIq() #2 - type must be 'get', 'set', 'result' or 'error'");
			return FALSE;
		}
		elseif($id && $xmlns)
		{
			$xml = "<iq type='$type' id='$id'";
			$xml .= ($to) ? " to='" . htmlspecialchars($to) . "'" : '';
			$xml .= ($from) ? " from='$from'" : '';
			$xml .= ">
						<query xmlns='$xmlns'>
							$payload
						</query>
					</iq>";

			$this->SendPacket($xml);
			sleep($this->iq_sleep_timer);
			$this->Listen();

			return (preg_match("/^(get|set)$/", $type)) ? $this->GetFromQueueById("iq", $id) : TRUE;
		}
		else
		{
			$this->logMessage("ERROR: SendIq() #1 - to, id and xmlns are mandatory");
			return FALSE;
		}
	} // end sendID

	// get the transport registration fields
	// method written by Steve Blinch, http://www.blitzaffe.com 
	public function TransportRegistrationDetails($transport)
	{
		$this->txnid++;
		$packet = $this->SendIq($transport, 'get', "reg_{$this->txnid}", "jabber:iq:register", NULL, $this->jid);

		if($packet)
		{
			$res = array();

			foreach ($packet['iq']['#']['query'][0]['#'] as $element => $data)
			{
				if($element != 'instructions' && $element != 'key')
				{
					$res[] = $element;
				}
			}

			return $res;
		}
		else
		{
			return 3;
		}
	} // end transportRegistrationDetails

	// register with the transport
	// method written by Steve Blinch, http://www.blitzaffe.com 
	function TransportRegistration($transport, $details)
	{
		$this->txnid++;
		$packet = $this->SendIq($transport, 'get', "reg_{$this->txnid}", "jabber:iq:register", NULL, $this->jid);

		if($packet)
		{
			$key = $this->GetInfoFromIqKey($packet);	// just in case a key was passed back from the server
			unset($packet);
		
			$payload = ($key) ? "<key>$key</key>\n" : '';
			foreach ($details as $element => $value)
			{
				$payload .= "<$element>$value</$element>\n";
			}
		
			$packet = $this->SendIq($transport, 'set', "reg_{$this->txnid}", "jabber:iq:register", $payload);
		
			if($this->GetInfoFromIqType($packet) == 'result')
			{
				if(isset($packet['iq']['#']['query'][0]['#']['registered'][0]['#']))
				{
					$return_code = 1;
				}
				else
				{
					$return_code = 2;
				}
			}
			elseif($this->GetInfoFromIqType($packet) == 'error')
			{
				if(isset($packet['iq']['#']['error'][0]['#']))
				{
					$return_code = "Error " . $packet['iq']['#']['error'][0]['@']['code'] . ": " . $packet['iq']['#']['error'][0]['#'];
					$this->logMessage('ERROR: TransportRegistration()');
				}
			}

			return $return_code;
		}
		else
		{
			return 3;
		}
	} // end transportregisstration

	public function GetvCard($jid = NULL, $id = NULL)
	{
		if(!$id)
		{
			$id = "vCard_" . md5(time() . $_SERVER['REMOTE_ADDR']);
		}

		if($jid)
		{
			$xml = "<iq type='get' to='$jid' id='$id'>
						<vCard xmlns='vcard-temp'/>
					</iq>";

			$this->SendPacket($xml);
			sleep($this->iq_sleep_timer);
			$this->Listen();

			return $this->GetFromQueueById("iq", $id);
		}
		else
		{
			$this->logMessage("ERROR: GetvCard() #1 - to and id are mandatory");
			return FALSE;
		}
	} // end getVcard

	// ======================================================================
	// private methods
	// ======================================================================

	private function _sendauth_0k($zerok_token, $zerok_sequence)
	{
		// initial hash of password
		$zerok_hash = mhash(MHASH_SHA1, $this->password);
		$zerok_hash = bin2hex($zerok_hash);

		// sequence 0: hash of hashed-password and token
		$zerok_hash = mhash(MHASH_SHA1, $zerok_hash . $zerok_token);
		$zerok_hash = bin2hex($zerok_hash);

		// repeat as often as needed
		for ($a = 0; $a < $zerok_sequence; $a++)
		{
			$zerok_hash = mhash(MHASH_SHA1, $zerok_hash);
			$zerok_hash = bin2hex($zerok_hash);
		}

		$payload = "<username>{$this->username}</username>
					<hash>$zerok_hash</hash>
					<resource>{$this->resource}</resource>";

		$packet = $this->SendIq(NULL, 'set', $this->auth_id, "jabber:iq:auth", $payload);

		// was a result returned?
		if($this->GetInfoFromIqType($packet) == 'result' && $this->GetInfoFromIqId($packet) == $this->auth_id)
		{
			return TRUE;
		}
		else
		{
			$this->logMessage("ERROR: _sendauth_0k() #1");
			return FALSE;
		}
	}



	private function _sendauth_digest()
	{
		$payload = "<username>{$this->username}</username>
					<resource>{$this->resource}</resource>
					<digest>" . bin2hex(mhash(MHASH_SHA1, $this->stream_id . $this->password)) . "</digest>";

		$packet = $this->SendIq(NULL, 'set', $this->auth_id, "jabber:iq:auth", $payload);

		// was a result returned?
		if($this->GetInfoFromIqType($packet) == 'result' && $this->GetInfoFromIqId($packet) == $this->auth_id)
		{
			return TRUE;
		}
		else
		{
			$this->logMessage("ERROR: _sendauth_digest() #1");
			return FALSE;
		}
	}



	private function _sendauth_plaintext()
	{
		$payload = "<username>{$this->username}</username>
					<password>{$this->password}</password>
					<resource>{$this->resource}</resource>";

		$packet = $this->SendIq(NULL, 'set', $this->auth_id, "jabber:iq:auth", $payload);

		// was a result returned?
		if($this->GetInfoFromIqType($packet) == 'result' && $this->GetInfoFromIqId($packet) == $this->auth_id)
		{
			return TRUE;
		}
		else
		{
			$this->logMessage("ERROR: _sendauth_plaintext() #1");
			return FALSE;
		}
	}



	private function _listen_incoming()
	{
		unset($incoming);

		while ($line = $this->CONNECTOR->ReadFromSocket(4096))
		{
			$incoming .= $line;
		}

		$incoming = trim($incoming);

		if($incoming != "")
		{
			$this->logMessage("RECV: $incoming",'debug');
		}

		return $this->xmlize($incoming);
	}



	private function _check_connected()
	{
		$incoming_array = $this->_listen_incoming();

		if(is_array($incoming_array))
		{
			if($incoming_array["stream:stream"]['@']['xmlns'] == "jabber:client"
				&& $incoming_array["stream:stream"]['@']["xmlns:stream"] == "http://etherx.jabber.org/streams")
			{
				$this->stream_id = $incoming_array["stream:stream"]['@']['id'];

				return TRUE;
			}
			else
			{
				$this->logMessage("ERROR: _check_connected() #1");
				return FALSE;
			}
		}
		else
		{
			$this->logMessage("ERROR: _check_connected() #2");
			return FALSE;
		}
	}



	private function _get_packet_type($packet = NULL)
	{
		if(is_array($packet))
		{
			reset($packet);
			$packet_type = key($packet);
		}

		return ($packet_type) ? $packet_type : FALSE;
	}



	private function _split_incoming($incoming)
	{
		$temp = preg_split("/<(message|iq|presence|stream)/", $incoming, -1, PREG_SPLIT_DELIM_CAPTURE);
		$array = array();

		for ($a = 1; $a < count($temp); $a = $a + 2)
		{
			$array[] = "<" . $temp[$a] . $temp[($a + 1)];
		}

		return $array;
	}

	// _array_htmlspecialchars()
	// applies htmlspecialchars() to all values in an array

	private function _array_htmlspecialchars($array)
	{
		if(is_array($array))
		{
			foreach ($array as $k => $v)
			{
				if(is_array($v))
				{
					$v = $this->_array_htmlspecialchars($v);
				}
				else
				{
					$v = htmlspecialchars($v);
				}
			}
		}

		return $array;
	}



	// ======================================================================
	// <message/> parsers
	// ======================================================================



	function GetInfoFromMessageFrom($packet = NULL)
	{
		return (is_array($packet)) ? $packet['message']['@']['from'] : FALSE;
	}



	function GetInfoFromMessageType($packet = NULL)
	{
		return (is_array($packet)) ? $packet['message']['@']['type'] : FALSE;
	}



	function GetInfoFromMessageId($packet = NULL)
	{
		return (is_array($packet)) ? $packet['message']['@']['id'] : FALSE;
	}



	function GetInfoFromMessageThread($packet = NULL)
	{
		return (is_array($packet)) ? $packet['message']['#']['thread'][0]['#'] : FALSE;
	}



	function GetInfoFromMessageSubject($packet = NULL)
	{
		return (is_array($packet)) ? $packet['message']['#']['subject'][0]['#'] : FALSE;
	}



	function GetInfoFromMessageBody($packet = NULL)
	{
		return (is_array($packet)) ? $packet['message']['#']['body'][0]['#'] : FALSE;
	}

	function GetInfoFromMessageXMLNS($packet = NULL)
	{
		return (is_array($packet)) ? $packet['message']['#']['x'] : FALSE;
	}



	function GetInfoFromMessageError($packet = NULL)
	{
		$error = preg_replace("/^\/$/", "", ($packet['message']['#']['error'][0]['@']['code'] . "/" . $packet['message']['#']['error'][0]['#']));
		return (is_array($packet)) ? $error : FALSE;
	}



	// ======================================================================
	// <iq/> parsers
	// ======================================================================



	function GetInfoFromIqFrom($packet = NULL)
	{
		return (is_array($packet)) ? $packet['iq']['@']['from'] : FALSE;
	}



	function GetInfoFromIqType($packet = NULL)
	{
		return (is_array($packet)) ? $packet['iq']['@']['type'] : FALSE;
	}



	function GetInfoFromIqId($packet = NULL)
	{
		return (is_array($packet)) ? $packet['iq']['@']['id'] : FALSE;
	}



	function GetInfoFromIqKey($packet = NULL)
	{
		return (is_array($packet)) ? $packet['iq']['#']['query'][0]['#']['key'][0]['#'] : FALSE;
	}



	function GetInfoFromIqError($packet = NULL)
	{
		$error = preg_replace("/^\/$/", "", ($packet['iq']['#']['error'][0]['@']['code'] . "/" . $packet['iq']['#']['error'][0]['#']));
		return (is_array($packet)) ? $error : FALSE;
	}



	// ======================================================================
	// <presence/> parsers
	// ======================================================================



	function GetInfoFromPresenceFrom($packet = NULL)
	{
		return (is_array($packet)) ? $packet['presence']['@']['from'] : FALSE;
	}



	function GetInfoFromPresenceType($packet = NULL)
	{
		return (is_array($packet)) ? $packet['presence']['@']['type'] : FALSE;
	}



	function GetInfoFromPresenceStatus($packet = NULL)
	{
		return (is_array($packet)) ? $packet['presence']['#']['status'][0]['#'] : FALSE;
	}



	function GetInfoFromPresenceShow($packet = NULL)
	{
		return (is_array($packet)) ? $packet['presence']['#']['show'][0]['#'] : FALSE;
	}



	function GetInfoFromPresencePriority($packet = NULL)
	{
		return (is_array($packet)) ? $packet['presence']['#']['priority'][0]['#'] : FALSE;
	}



	// ======================================================================
	// <message/> handlers
	// ======================================================================

	private function Handler_message_normal($packet)
	{
		$from_string = $packet['message']['@']['from'];
        $from_parts = explode('/',$from_string);
        $from = $from_parts[0]; // owlmanatt@yasashiisyndicate.org
        $from_resource = $from_parts[1]; // /Adium
        
        if(is_array($packet['message']['#']['body']) == true)
        {
            $message = $packet['message']['#']['body'][0]['#'];
            $this->logMessage("EVENT: Message (type normal) from $from: $message",'debug');

            if(preg_match("/^{$this->dragoon->getDragoonName()}, (.*)$/i",$message,$MATCHES) == true)
            {
                if(preg_match('/^(load|unload|reload) module ([a-z0-9_]+)/i',$MATCHES[1],$MODULE_NAME) == true)
                {
                    $this->logMessage("Received command to {$MODULE_NAME[1]} {$MODULE_NAME[2]}.");
                    switch($MODULE_NAME[1])
                    {
                        case 'load':
                        {
                            $this->dragoon->queueModuleLoad($MODULE_NAME[2]);
                            
                            break;
                        } // end load

                        case 'unload':
                        {
                            $this->dragoon->queueModuleUnload($MODULE_NAME[2]);

                            break;
                        } // end unload

                        case 'reload':
                        {
                            $this->dragoon->queueModuleReload($MODULE_NAME[2]);
                            
                            break;
                        } // end reload
                    } // end (un|re)?load switch
                } // end reload
                elseif(preg_match('/^refresh datasource ([0-9]+)/i',$MATCHES[1],$MODULE_NAME) == true)
                {
                    $source = new Datasource($this->db);
                    $source = $source->findByDatasourceId($MODULE_NAME[1]);
                    $source = $source[0];
                    
                    if(is_a($source,'Datasource'))
                    {
                        $source->setDatetimeLastFetch(0);
                        $source->save();

                        $this->sendMessage($from,'normal',null,array('body' => "{$source->getDescription()} flagged.",),null);
                    } // end isa
                    else
                    {
                        $this->sendMessage($from,'normal',null,array('body' => "Sorry, I can't find that datasource.",),null);

                    }
                } // end datasource refresher

                
                
            } // end command
            
        } // end is message
	} // end Handler_message_normal

	private function Handler_message_chat($packet)
	{
	    $this->Handler_message_normal($packet);
    } // end Handler_message_chat

    private	function Handler_message_groupchat($packet)
	{
	    $this->Handler_message_normal($packet);
    } // end Handler_message_groupchat

	private function Handler_message_headline($packet)
	{
		$from = $packet['message']['@']['from'];
		$this->logMessage("EVENT: Message (type headline) from $from",'debug');
	} // end Handler_message_headline

	private function Handler_message_error($packet)
	{
		$from = $packet['message']['@']['from'];
		$this->logMessage("EVENT: Message (type error) from $from",'notice');
	} // end Handler_message_error

	// ======================================================================
	// <iq/> handlers
	// ======================================================================

	// application version updates
	function Handler_iq_jabber_iq_autoupdate($packet)
	{
		$from	= $this->GetInfoFromIqFrom($packet);
		$id		= $this->GetInfoFromIqId($packet);

		$this->SendError($from, $id, 501);
		$this->logMessage("EVENT: jabber:iq:autoupdate from $from",'debug');
	}

	// interactive server component properties
	function Handler_iq_jabber_iq_agent($packet)
	{
		$from	= $this->GetInfoFromIqFrom($packet);
		$id		= $this->GetInfoFromIqId($packet);

		$this->SendError($from, $id, 501);
		$this->logMessage("EVENT: jabber:iq:agent from $from",'debug');
	}
	
    // lolz
    function Handler_iq_jabber_iq_agents($packet)
	{
		$from	= $this->GetInfoFromIqFrom($packet);
		$id		= $this->GetInfoFromIqId($packet);

		$this->SendError($from, $id, 501);
		$this->logMessage("EVENT: jabber:iq:agents from $from",'debug');
	}

	// simple client authentication
	function Handler_iq_jabber_iq_auth($packet)
	{
		$from	= $this->GetInfoFromIqFrom($packet);
		$id		= $this->GetInfoFromIqId($packet);

		$this->SendError($from, $id, 501);
		$this->logMessage("EVENT: jabber:iq:auth from $from",'debug');
	}

	// out of band data
	function Handler_iq_jabber_iq_oob($packet)
	{
		$from	= $this->GetInfoFromIqFrom($packet);
		$id		= $this->GetInfoFromIqId($packet);

		$this->SendError($from, $id, 501);
		$this->logMessage("EVENT: jabber:iq:oob from $from",'debug');
	}

	// method to store private data on the server
	function Handler_iq_jabber_iq_private($packet)
	{
		$from	= $this->GetInfoFromIqFrom($packet);
		$id		= $this->GetInfoFromIqId($packet);

		$this->SendError($from, $id, 501);
		$this->logMessage("EVENT: jabber:iq:private from $from",'debug');
	}

	// method for interactive registration
	function Handler_iq_jabber_iq_register($packet)
	{
		$from	= $this->GetInfoFromIqFrom($packet);
		$id		= $this->GetInfoFromIqId($packet);

		$this->SendError($from, $id, 501);
		$this->logMessage("EVENT: jabber:iq:register from $from",'debug');
	}

	// client roster management
	function Handler_iq_jabber_iq_roster($packet)
	{
		$from	= $this->GetInfoFromIqFrom($packet);
		$id		= $this->GetInfoFromIqId($packet);

		$this->SendError($from, $id, 501);
		$this->logMessage("EVENT: jabber:iq:roster from $from",'debug');
	}



	// method for searching a user database
	function Handler_iq_jabber_iq_search($packet)
	{
		$from	= $this->GetInfoFromIqFrom($packet);
		$id		= $this->GetInfoFromIqId($packet);

		$this->SendError($from, $id, 501);
		$this->logMessage("EVENT: jabber:iq:search from $from",'debug');
	}



	// method for requesting the current time
	function Handler_iq_jabber_iq_time($packet)
	{
		if($this->keep_alive_id == $this->GetInfoFromIqId($packet))
		{
			$this->returned_keep_alive = TRUE;
			$this->connected = TRUE;
			$this->logMessage('EVENT: Keep-Alive returned, connection alive.','debug');
		}
		$type	= $this->GetInfoFromIqType($packet);
		$from	= $this->GetInfoFromIqFrom($packet);
		$id		= $this->GetInfoFromIqId($packet);
		$id		= ($id != "") ? $id : "time_" . time();

		if($type == 'get')
		{
			$payload = "<utc>" . gmdate("Ydm\TH:i:s") . "</utc>
						<tz>" . date("T") . "</tz>
						<display>" . date("Y/d/m h:i:s A") . "</display>";

			$this->SendIq($from, 'result', $id, "jabber:iq:time", $payload);
		}

		$this->logMessage("EVENT: jabber:iq:time (type $type) from $from",'debug');
	}



	// method for requesting version
	function Handler_iq_jabber_iq_version($packet)
	{
		$type	= $this->GetInfoFromIqType($packet);
		$from	= $this->GetInfoFromIqFrom($packet);
		$id		= $this->GetInfoFromIqId($packet);
		$id		= ($id != "") ? $id : "version_" . time();

		if($type == 'get')
		{
			$payload = "<name>{$this->iq_version_name}</name>
						<os>{$this->iq_version_os}</os>
						<version>{$this->iq_version_version}</version>";

			#$this->SendIq($from, 'result', $id, "jabber:iq:version", $payload);
		}

		$this->logMessage("EVENT: jabber:iq:version (type $type) from $from -- DISABLED",'debug');
	}



	// keepalive method, added by Nathan Fritz
	/*
	function Handler_jabber_iq_time($packet)
	{
		if($this->keep_alive_id == $this->GetInfoFromIqId($packet))
		{
			$this->returned_keep_alive = TRUE;
			$this->connected = TRUE;
			$this->logMessage('EVENT: Keep-Alive returned, connection alive.');
		}
	}
	*/
	
	
	// ======================================================================
	// <presence/> handlers
	// ======================================================================



	function Handler_presence_available($packet)
	{
		$from = $this->GetInfoFromPresenceFrom($packet);

		$show_status = $this->GetInfoFromPresenceStatus($packet) . " / " . $this->GetInfoFromPresenceShow($packet);
		$show_status = ($show_status != " / ") ? " ($addendum)" : '';

		$this->logMessage("EVENT: Presence (type: available) - $from is available $show_status",'debug');
	}



	function Handler_presence_unavailable($packet)
	{
		$from = $this->GetInfoFromPresenceFrom($packet);

		$show_status = $this->GetInfoFromPresenceStatus($packet) . " / " . $this->GetInfoFromPresenceShow($packet);
		$show_status = ($show_status != " / ") ? " ($addendum)" : '';

		$this->logMessage("EVENT: Presence (type: unavailable) - $from is unavailable $show_status",'debug');
	}



	function Handler_presence_subscribe($packet)
	{
		$from = $this->GetInfoFromPresenceFrom($packet);
		$this->SubscriptionAcceptRequest($from);
		$this->RosterUpdate();

		$this->logMessage("Presence: (type: subscribe) - Subscription request from $from, was added to \$this->subscription_queue, roster updated",'debug');
	}

	function Handler_presence_subscribed($packet)
	{
		$from = $this->GetInfoFromPresenceFrom($packet);
		$this->RosterUpdate();

		$this->logMessage("EVENT: Presence (type: subscribed) - Subscription allowed by $from, roster updated",'debug');
	}



	function Handler_presence_unsubscribe($packet)
	{
		$from = $this->GetInfoFromPresenceFrom($packet);
		$this->SendPresence("unsubscribed", $from);
		$this->RosterUpdate();

		$this->logMessage("EVENT: Presence (type: unsubscribe) - Request to unsubscribe from $from, was automatically approved, roster updated",'debug');
	}



	function Handler_presence_unsubscribed($packet)
	{
		$from = $this->GetInfoFromPresenceFrom($packet);
		$this->RosterUpdate();

		$this->logMessage("EVENT: Presence (type: unsubscribed) - Unsubscribed from $from's presence",'debug');
	}



	// Added By Nathan Fritz
	function Handler_presence_error($packet)
	{
		$from = $this->GetInfoFromPresenceFrom($packet);
		$this->logMessage("EVENT: Presence (type: error) - Error in $from's presence",'debug');
	}
	
	
	
	// ======================================================================
	// Generic handlers
	// ======================================================================



	// Generic handler for unsupported requests
	function Handler_NOT_IMPLEMENTED($packet)
	{
		$packet_type	= $this->_get_packet_type($packet);
		$from			= call_user_func(array(&$this, "GetInfoFrom" . ucfirst($packet_type) . "From"), $packet);
		$id				= call_user_func(array(&$this, "GetInfoFrom" . ucfirst($packet_type) . "Id"), $packet);

		$this->SendError($from, $id, 501);
		$this->logMessage("EVENT: Unrecognized <$packet_type/> from $from",'debug');
	}


	// ======================================================================
	// Third party code
	// m@d pr0ps to the coders ;)
	// ======================================================================

	// xmlize()
	// (c) Hans Anderson / http://www.hansanderson.com/php/xml/
	function xmlize($data)
	{
		$vals = $index = $array = array();
		$parser = xml_parser_create();
		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
		xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
		xml_parse_into_struct($parser, $data, $vals, $index);
		xml_parser_free($parser);

		$i = 0;

		$tagname = $vals[$i]['tag'];
		$array[$tagname]['@'] = $vals[$i]['attributes'];
		$array[$tagname]['#'] = $this->_xml_depth($vals, $i);

		return $array;
	}



	// _xml_depth()
	// (c) Hans Anderson / http://www.hansanderson.com/php/xml/

	function _xml_depth($vals, &$i)
	{
		$children = array();

		if($vals[$i]['value'])
		{
			array_push($children, trim($vals[$i]['value']));
		}

		while (++$i < count($vals))
		{
			switch ($vals[$i]['type'])
			{
				case 'cdata':
					array_push($children, trim($vals[$i]['value']));
	 				break;

				case 'complete':
					$tagname = $vals[$i]['tag'];
					$size = sizeof($children[$tagname]);
					$children[$tagname][$size]['#'] = trim($vals[$i]['value']);
					if($vals[$i]['attributes'])
					{
						$children[$tagname][$size]['@'] = $vals[$i]['attributes'];
					}
					break;

				case 'open':
					$tagname = $vals[$i]['tag'];
					$size = sizeof($children[$tagname]);
					if($vals[$i]['attributes'])
					{
						$children[$tagname][$size]['@'] = $vals[$i]['attributes'];
						$children[$tagname][$size]['#'] = $this->_xml_depth($vals, $i);
					}
					else
					{
						$children[$tagname][$size]['#'] = $this->_xml_depth($vals, $i);
					}
					break;

				case 'close':
					return $children;
					break;
			}
		}

		return $children;
	}



	// TraverseXMLize()
	// (c) acebone@f2s.com, a HUGE help!

	function TraverseXMLize($array, $arrName = "array", $level = 0)
	{
		if($level == 0)
		{
			echo "<pre>";
		}

		while (list($key, $val) = @each($array))
		{
			if(is_array($val))
			{
				$this->TraverseXMLize($val, $arrName . "[" . $key . "]", $level + 1);
			}
			else
			{
				echo '$' . $arrName . '[' . $key . '] = "' . $val . "\"\n";
			}
		}

		if($level == 0)
		{
			echo "</pre>";
		}
	}
} // end JabberFrontend

?>
