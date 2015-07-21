<?php
/*
	PHP IRC Library
	irc_client.php

	Copyright (c) 2015 Alex Ingram

	Permission is hereby granted, free of charge, to any person obtaining a copy
	of this software and associated documentation files (the "Software"), to deal
	in the Software without restriction, including without limitation the rights
	to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
	copies of the Software, and to permit persons to whom the Software is
	furnished to do so, subject to the following conditions:

	The above copyright notice and this permission notice shall be included in
	all copies or substantial portions of the Software.

	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
	THE SOFTWARE.
*/

class IRCClient {
	// This will be set to "false" if a fatal error has been encountered inside
	//   of the class.
	protected $SanityCheckOK      = false;
	private   $ServerSocket;


	// Server connection information:
	protected $ServerHostname     = '';
	protected $ServerPort	      = 0;
	protected $ServerUseSSL       = false;

	protected $ClientNick         = '';
	protected $ClientIdent        = '';
	protected $ClientGecos        = '';


	// Configurable options:
	public    $ConnectionTimeout  = 30;
	public    $CallbackTimeout    = 60;
	public    $VerboseLog         = true;
	public    $LogSockets         = false;
	public    $CTCPVersionReply   = "PHP IRC Library (c) 2015 Alex Ingram :: https://github.com/ReimuHakurei/php-irc";
	
	// Internal database arrays:
	protected $CurrentChannels   = [];
	protected $QueuedChannels    = [];

	public function __construct($URI, $Nick, $Ident, $Gecos) {
		// In the construct, we will fill in all of the internal server
		//   connection information variables.
		//
		// The format of an IRC URI is simple. It can, in general usage,
		//   also include a list of channels. If those are found here,
		//   they will be added to an array of a to-join channel queue.
		//
		// An IRC URI is of the following format:
		//   irc://serverHostname:[+]port[/channel1,channel2]
		//
		// The + in the port section signifies an SSL connection.
		//
		// Although the IRC protocol does allow for channels starting
		//   with characters other than # (most commonly things such
		//   as +, &, and $), any channels in an IRC URI are assumed
		//   to use the standard # prefix, and as such, the prefix
		//   should be omitted. Channels should be comma-delimited.

		$this->log("PHP-IRCClient (c) 2015 Alex Ingram");
		$this->log("Starting up...");

		$ExplodedURI = explode("/",$URI);
		if (count($ExplodedURI) >= 3) {
			$HostnamePortBlock = $ExplodedURI[2];
			$ChannelList	= '';

			if (array_key_exists(3,$ExplodedURI)) {
				$this->QueuedChannels = explode(",",$ExplodedURI[3]);

				foreach ($this->QueuedChannels as &$channel) {
    					$channel = '#' . $channel;
				}
			}

			$HostnamePort = explode(":",$HostnamePortBlock);

			if (count($HostnamePort) == 2) {
				$this->ServerHostname = $HostnamePort[0];
				$this->ServerPort     = $HostnamePort[1];
			} else {
				$this->error("Malformed IRC URI in construct.");
			}

			if ($this->ServerPort[0] == "+") {
				$Port = str_split($this->ServerPort);
				array_shift($Port);

				$this->ServerPort = implode($Port);
				$this->ServerUseSSL = true;
			}
		} else {
			$this->error("Malformed IRC URI in construct.");
		}

		// Just for debugging.
		$this->log("  Server   : " . $this->ServerHostname . ":" . $this->ServerPort . ", using SSL: " . var_export($this->ServerUseSSL, true));
		$this->log("  Channels : " . implode(", ", $this->QueuedChannels));

		$this->ClientNick    = $Nick;
		$this->ClientIdent   = $Ident;
		$this->ClientGecos   = $Gecos;

		$this->SanityCheckOK = true;
		
		$this->connect();
	}



	// Logging functions

	protected function log($LogText) {
		if ($this->VerboseLog) {
			echo "[" . date("Y-m-d H:i:s") . "] " . implode(explode("\r\n",$LogText)) . "\n";
		}
	}

	protected function error($ErrorText) {
		$this->log("ERROR: " . $ErrorText);
		throw new Exception($ErrorText);
	}


	// Connection handling functions

	protected function connect() {
		// Note: This function will block until the bot disconnects.


		if (!$this->SanityCheckOK) {
			$this->log("WARNING: The constructor did not complete, expect major issues!");
		}


		$this->log("Connecting...");

		if ($this->ServerUseSSL) {
			$ServerURI = "tls://" . $this->ServerHostname . ":" . $this->ServerPort;
		} else {
			$ServerURI = "tcp://" . $this->ServerHostname . ":" . $this->ServerPort;
		}

		// Almost no IRC servers have valid SSL certificates, so we'll just ignore them.
		$Context = stream_context_create();
		stream_context_set_option($Context, 'ssl', 'verify_peer', false);
		stream_context_set_option($Context, 'ssl', 'verify_peer_name', false);


		@$this->ServerSocket = stream_socket_client($ServerURI, $errno, $errstr, $this->ConnectionTimeout, STREAM_CLIENT_CONNECT, $Context);
		
		stream_set_timeout($this->ServerSocket,$this->CallbackTimeout);
		
		if (!$this->ServerSocket) {
			$this->log("Server connection failed: $errstr ($errno)");
		} else {
			$this->log("Connection established. Registering with server...");

			$RegistrationComplete = false;

			$this->nick($this->ClientNick);
			$this->send("USER " . $this->ClientIdent . " 0 * :" . $this->ClientGecos);

			while (!feof($this->ServerSocket)) {
				if ($Message = fgets($this->ServerSocket, 512)) {
					if ($this->LogSockets) {
						$this->log(">> " . $Message);
					}
										
					$Message = $this->parse_message($Message);
					
					if (($Message->Command == "001" || $Message->Command == "002" || $Message->Command == "003" || $Message->Command == "004") && (!$RegistrationComplete )) {
						$RegistrationComplete = true;
						
						$this->log("Registration complete.");
						
						foreach($this->QueuedChannels as $Channel) {
							$this->join($Channel);
						}
					}
					
					if ($RegistrationComplete) {
						$this->recv($Message);
					}
				} else {
					$this->recv(NULL);
				}
			}
		}

		$this->log("Socket disconnected.");
	}

	private function parse_message($Message) {
		// This function handles the IRC protocol itself (ie: responding to PINGs, CTCP requests, etc), and
		//   will return a message object which will be passed along to the user's code.

		// We want to keep a copy of the raw message, in case the user wants it.
		$RawMessage = $Message;


		// RFC 2812 says that any messages lacking a prefix can be assumed to originate from the connection itself.
		// As such, we will set the prefix of any non-prefixed message to the server hostname, for parsing purposes.
		// This also allows us to process all messages in the same block of code.		
		if ($Message[0] != ':') {
			$Message = ":" . $this->ServerHostname . " " . $Message;
		}

		// We're now going to remove that ':' we just added above.
		$MessageArray = str_split($Message);
		array_shift($MessageArray);
		$Message = implode($MessageArray);
		unset($MessageArray);
		
		// Remove the CR-LF from the end...
		$Message = substr($Message,0,sizeof($Message) - 3);
		
		// RFC 2812 says that the trailing parameter (the one starting with a :), if present, should be treated
		// exactly the same as any other parameter.
		// Pull the trailing parameter, if present...
		$Trailing = NULL;
		$MessageArray = explode(":",$Message);
		if (sizeof($MessageArray) > 1) {
			$Trailing = $MessageArray[1];
			$Message = $MessageArray[0];
		}
		unset($MessageArray);
		
		// Pull the middle parameters...
		$MessageArray = explode(" ",$Message);
		// ...and slap the trailing parameter back on the end.
		$MessageArray[] = $Trailing;
		
		if (sizeof($MessageArray) >= 3) {		
			// Time to chop up that prefix into its' three segments!
			$MessagePrefix = split('[!@]',$MessageArray[0]);
			array_shift($MessageArray);
			if (sizeof($MessagePrefix) == 3) {
				$Nick = $MessagePrefix[0];
				$User = $MessagePrefix[1];
				$Host = $MessagePrefix[2];
			} else {
				$Nick = $MessagePrefix[0];
				$User = NULL;
				$Host = NULL;
			}
			
			$Command = $MessageArray[0];
			array_shift($MessageArray);
			
			$Parameters = array_values(array_filter($MessageArray));
			
			$Message = (object) array('Nick' => $Nick, 'User' => $User, 'Host' => $Host, 'Command' => $Command, 'Parameters' => $Parameters, 'RawMessage' => $RawMessage);
			
			// Respond to PINGs
			if ($Command == "PING") {
				$this->send("PONG :" . $Parameters[0]);
			}
						
			// Handle CTCP requests	
			if (($Command == "PRIVMSG" || $Command == "NOTICE") && array_key_exists(1,$Parameters)) {		
				if (($Parameters[1][0] == "\001") && ($Parameters[1][sizeof($Parameters[1])-1] == "\001")) {
					if ($Parameters[1] == "\001VERSION\001") {
						$this->send("NOTICE $Nick :" . $this->CTCPVersionReply);
						
						$this->log("Received CTCP VERSION request from $Nick.");
					}
					
					if ($Parameters[1][1] == 'P' && $Parameters[1][2] == 'I' && $Parameters[1][3] == 'N' && $Parameters[1][4] == 'G') {
						$this->send("NOTICE $Nick :" . $Parameters[1]);
						
						$this->log("Received CTCP PING request from $Nick.");
					}
				}
			}
			
			return $Message;
		} else {
			$this->log("Notice: Malformed message detected.");
			$this->log($RawMessage);
		}
	}



	// Socket functions

	protected function send($Message) {
		if (sizeof($Message) > 510) {
			$this->log("Notice: An attempt was made to send an excessively long string. The string will be trimmed to 510 characters.");
		}

		if ($this->LogSockets) {
			$this->log("<< " . substr($Message, 0, 510));
		}
		
		fwrite($this->ServerSocket, substr($Message, 0, 510) . "\r\n", 512);
	}


	protected function recv($Message) {
		return 0;
	}


	// Protocol abstraction functions

	protected function nick($Nick) {
		$this->send("NICK :" . $Nick);
	}

	protected function join($Channel) {
		$this->send("JOIN :" . $Channel);
	}
	
	protected function part($Channel, $Message) {
		$this->send("PART " . $Channel . " :" . $Message);
	}
	
	protected function quit($Message) {
		$this->send("QUIT :" . $Message);
	}
	
	protected function kick($Channel, $Person, $Message) {
		$this->send("KICK " . $Channel . " " . $Person . " :" . $Message);
	}
}
?>