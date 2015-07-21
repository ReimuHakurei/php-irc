<?php
	require_once("irc_client.php");

	class ExampleBot extends IRCClient {
		// Change configuration options.
		public $ConnectionTimeout  = 60;
		public $CallbackTimeout    = 15;
		public $VerboseLog         = true;
		public $LogSockets         = false;
		public $CTCPVersionReply   = "PHP IRC Library (c) 2015 Alex Ingram :: https://github.com/ReimuHakurei/php-irc";
		
		protected function recv($Message) {
			if ($Message != NULL) {
				//$this->log("Received message.");
			} else {
				//$this->log("Received empty callback.");
			}
		}
	}

	$Client = new ExampleBot('irc://irc.myserver.net:+6697/test','TestBot','Bot','An IRC Bot');
?>
