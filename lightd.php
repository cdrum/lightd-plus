#!/usr/local/bin/php
<?php

/*

lightd - a simple HTTP gateway for the lifx binary protocol

Copyright (C) 2014 Vincent Negrier aka. sIX <six at aegis-corp.org>

This library is free software; you can redistribute it and/or
modify it under the terms of the GNU Lesser General Public
License as published by the Free Software Foundation; either
version 2.1 of the License, or (at your option) any later version.

This library is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
Lesser General Public License for more details.

You should have received a copy of the GNU Lesser General Public
License along with this library; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA 

Enhancements to allow multiple bulb gateways by Chris Drumgoole / cdrum.com

*/

namespace Lightd;

const VERSION = "0.9.0 (e)cdrum 1";

//const LIFX_HOST = "lifx";
const LIFX_PORT = 56700;

const BUILD_GW_PASSES = 5;

const API_LISTEN_ADDR = "0.0.0.0";
const API_LISTEN_PORT = 5439;

require_once "nanoserv/nanoserv.php";
require_once "nanoserv/handlers/HTTP/Server.php";
require_once "lifx.php";

use Nanoserv\HTTP\Server as HTTP_Server;
use Nanoserv\Core as Nanoserv;

use Lightd\Drivers\Lifx\Packet as Lifx_Packet;
use Lightd\Drivers\Lifx\Handler as Lifx_Handler;
use Exception;

class Light {
	
	static public $all = [];

	public $id;
	public $label;
	public $gw;
	public $tags;
	public $state_ts;

	public $rgb;
	public $power;
	public $extra;

	public function __construct($id = null, $label = null, $gw = null) {
		$this->id = $id;
		$this->label = $label;
		$this->gw = $gw;
	}
	
	static public function Get_All() {
		return array_values(self::$all);
	}
	
	static public function Get_By_Name($label) {
		foreach (self::$all as $l) {
			if ($l->label === $label) {
				return $l;
			}
		}
		throw new Exception("light not found: {$label}");
	}
	
	static public function Register(self $l) {
		self::$all[$l->id] = $l;
		log("new bulb registered: {$l->label} on gw {$l->gw}");
	}

	static public function Dump() {
		foreach (self::$all as $l) {
			log($l->label . " " . ($l->power ? "on" : "off") . " " . $l->rgb . " @ " . $l->extra["kelvin"] . "K (" . date("Ymd:His", $l->state_ts) . ")");
		}
	}

	public function Set_Power($power = true) {
		$GLOBALS["lifx"][$this->gw]->Set_Power($power, $this->id);
	}

	public function Set_Color($rgb, array $extra = []) {
		$GLOBALS["lifx"][$this->gw]->Set_Color($rgb, $extra, $this->id);
	}

}

class Lifx_Client extends Lifx_Handler {
	public function on_Connect() {
		log("connected");
		parent::on_Connect();
	}
	public function on_Discover(Lifx_Packet $pkt) {
		log("found gateway bulb at {$pkt->gateway_mac}");
		parent::on_Discover($pkt);
	}
	public function on_Packet(Lifx_Packet $pkt) {
		// var_dump($pkt);
	}
	public function on_Light_State(Light $l) {
		if (isset(Light::$all[$l->id])) {
			$rl = Light::$all[$l->id];
		} else {
			$rl = new Light($l->id, $l->label, $l->gw);
			Light::Register($rl);
		}
		$rl->state_ts = time();
		$rl->id = $l->id;
		$rl->label = $l->label;
		$rl->gw = $l->gw;
		$rl->tags = $l->tags;
		$rl->rgb = $l->rgb;
		$rl->power = $l->power;
		$rl->extra = $l->extra;
	}
}

class API_Server extends HTTP_Server {
	public function on_Request($url) {
		try {
			log("[{$this->socket->Get_Peer_Name()}] API {$url}");
			$args = explode("/", ltrim(urldecode($url), "/"));
			$cmd = array_shift($args);
			switch ($cmd) {
				
				case "power":
				switch ($args[0]) {
					case "on":
					$power = true;
					break;
					case "off":
					$power = false;
					break;
				}
				if (!isset($power)) {
					throw new Exception("invalid argument '{$args[0]}'");
				}
				if ($args[1]) {
					Light::Get_By_Name($args[1])->Set_Power($power);
				} else {
					foreach($GLOBALS["lifx"] as $lifx) {
						if (is_object($lifx)) {
							$lifx->Set_Power($power);
						}
					}
				}
				break;

				case "color":
				$extraargs = explode("-",$args[0]);
				$rgb = "#" . $extraargs[0];
				$hue= $extraargs[1];
				$saturation = $extraargs[2];
				$brightness = $extraargs[3];
				$dim = $extraargs[4];
				$kelvin = $extraargs[5];
				if ($args[1]) {
					Light::Get_By_Name($args[1])->Set_Color($rgb, [ "hue" => $hue, "saturation" => $saturation, "brightness" => $brightness, "dim" => $dim, "kelvin" => $kelvin]);
				} else {
					foreach($GLOBALS["lifx"] as $lifx) {
						if (is_object($lifx)) {
							$lifx->Set_Color($rgb, [ "hue" => $hue, "saturation" => $saturation, "brightness" => $brightness, "kelvin" => $kelvin, "dim" => $dim]);
						}
					}
				}
				break;

				case "state":
				if ($args[0]) {
					return json_encode(Light::Get_By_Name($args[0]), JSON_PRETTY_PRINT);
				} else {
					return json_encode(Light::Get_All(), JSON_PRETTY_PRINT);
				}
				break;
				
				case "pattern":
				if (!isset($args[0])) {
					return json_encode([ 
						"current" => $GLOBALS["current_pattern"],
						"ts" => $GLOBALS["current_pattern_ts"],
					]);
				} else if (!isset($GLOBALS["patterns"][$args[0]])) {
					throw new Exception("unknown pattern '{$args[0]}'");
				}
				if ($args[1]) {
					$fade = $args[1];
				}
				foreach ($GLOBALS["patterns"][$args[0]] as $bname => $bdata) {
					$l = Light::Get_By_Name($bname);
					$l->Set_Power($bdata["power"]);
					if ($bdata["rgb"]) {
						$rgb = "#" . $bdata["rgb"];
						$l->Set_Color($rgb, [ 
							"kelvin" => $bdata["kelvin"],
							"fade" => $fade,
						]);
					}
				}
				$GLOBALS["current_pattern"] = $args[0];
				$GLOBALS["current_pattern_ts"] = time();
				break;

			}
			return "ok";
		} catch (Exception $e) {
			$this->Set_Response_Status(400);
			return "error: {$e->getMessage()}";
		}
	}
}

function log($msg) {
	echo date("Ymd:His") . " " . $msg . "\n";
}

function build_gateways() {
	
	log("Getting Gateways");
	
	// Create packet for requesting gateways
	$packet = new Lifx_Packet(0x02);
	$broadcast_string = $packet->Encode();

	// Make socket for broadcasting request on network
	log("Creating broadcast socket");
	$broadcast_socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP); 
	if ($broadcast_socket == false) { die("SendSock: ".socket_strerror(socket_last_error())); } 

	$setopt = socket_set_option($broadcast_socket, SOL_SOCKET, SO_BROADCAST, 1); 
	if ($setopt == false) { die(socket_strerror(socket_last_error())); } 

	log("Sending broadcast");
	socket_sendto($broadcast_socket, $broadcast_string, strlen($broadcast_string), 0, '255.255.255.255', LIFX_PORT); 
	
	log("Closing broadcast socket");
	socket_close($broadcast_socket);

	// Make socket for listening for broadcast responses
	log("Creating listening socket");
	$listen_socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP); 
	if ($listen_socket == false) { die("SendSock: ".socket_strerror(socket_last_error())); } 
	if (socket_bind($listen_socket, "0.0.0.0", 56700) === false) {
	    echo "socket_bind() failed: reason: " . socket_strerror(socket_last_error($listen_socket)) . "\n";
	}

	socket_set_option($listen_socket,SOL_SOCKET,SO_RCVTIMEO,array("sec"=>1,"usec"=>0));

	// Create gw holder array
	$gateways = array();
	
	// Hiding listen timeout warnings
	$oldErrorReporting = error_reporting(); // save error reporting level
	error_reporting($oldErrorReporting ^ E_WARNING); // disable warnings
	
	// Loop through an arbitrary number of passes.
	$pass = 0;
	while ($pass < BUILD_GW_PASSES) {

		// Create receive variables
		$from = null;
		$port = null;
		$buf = null;


		log("Listen pass " . $pass);
		if (!socket_recvfrom($listen_socket, $buf, 41, 0, $from, $port)) {
			$pass++;
			continue;
		}



		$pkt = Lifx_Packet::Decode($buf);
		if ($pkt->type === 0x03) {
			log("Received a valid 0x03 response from " . $from . ", size of response is " . strlen($buf));
			log("Payload length: " . strlen($pkt->payload));

			// Extract the port (should be 56700, but just in case...)
			$port = unpack("V", substr($pkt->payload, 1, 4))[1];
			log("Gateway " . $pkt->gateway_mac . " port is " . $port);

			$gw = array(	
				"mac" => $pkt->gateway_mac,
				"ip" => $from,
				"port" => $port
			);

			$gateways[$pkt->gateway_mac] = $gw;
		}

		$pass++;
	}
	error_reporting($oldErrorReporting); // restore error reporting level

	log("Closing listening socket");
	socket_close($listen_socket);

	return $gateways;

}

log("lightd/" . VERSION . " Original (c) 2014 by sIX / aEGiS <six@aegis-corp.org> | New (c) 2014 Chris Drumgoole / cdrum.com");

$patterns = [];
$current_pattern = "off";
$current_pattern_ts = 0;

foreach (parse_ini_file(dirname(__FILE__) . DIRECTORY_SEPARATOR . "patterns.ini", true, INI_SCANNER_RAW) as $pname => $bulbs) {
	$bdata = [];
	foreach ($bulbs as $bname => $str) {
		$power = ($str !== "off");
		$bcmd = [ "power" => $power ];
		if ($power) {
			if (preg_match('/#([0-9a-fA-F]{6})/', $str, $res)) {
				$bcmd["rgb"] = $res[1];
			}
			if (preg_match('/([0-9]+)K/', $str, $res)) {
				$bcmd["kelvin"] = $res[1];
			}
		}
		$bdata[$bname] = $bcmd;
	}
	$patterns[$pname] = $bdata;
}

log("loaded " . count($patterns) . " patterns");




// Get Gateways
print "Getting Gateways...\n";


$gws = build_gateways();
//print_r($gws);

$num_gws = count($gws);
log("Found " . $num_gws . " gateways to connect to");

// Make array to hold the lifx gateway connections
$lifx = array($num_gws);
$gw_count = 0;

foreach($gws as $gw) {
	$lifx[$gw["mac"]] = Nanoserv::New_Connection("tcp://" . $gw["ip"] . ":" . $gw["port"], __NAMESPACE__ . "\\Lifx_Client");
	$lifx[$gw["mac"]]->Connect();
	
	Nanoserv::Run(1);

	if (!$lifx[$gw["mac"]]->socket->connected) {
		log("cannot connect");
		exit(1);
	}
	
	$gw_count++;
}


Nanoserv::New_Listener("tcp://" . API_LISTEN_ADDR . ":" . API_LISTEN_PORT, __NAMESPACE__ . "\\API_Server")->Activate();
log("API server listening on port " . API_LISTEN_PORT);

$last_refresh_ts = time();

while (true) {
	Nanoserv::Run(-1);
	$t = time();

	$gw_count = 0;
	foreach($gws as $gw) {

		if ($lifx[$gw["mac"]]->must_reconnect) {
			log("lost connection, trying to reconnect ...");
			sleep(3);
			$lifx[$gw["mac"]] = Nanoserv::New_Connection("tcp://" . $gw["ip"] . ":" . $gw["port"], __NAMESPACE__ . "\\Lifx_Client");
			$lifx[$gw["mac"]]->Connect();
			Nanoserv::Run(-1);
		} else {
			if (($last_refresh_ts + 2) < $t) {
				$lifx[$gw["mac"]]->Refresh_States();
				$last_refresh_ts = $t;
			}
		}
	}
}

?>