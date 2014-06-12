#!/usr/local/bin/php
<?php

/*

lightd - a simple HTTP gateway for the lifx binary protocol

Original Copyright (C) 2014 Vincent Negrier aka. sIX <six at aegis-corp.org>
New code Copyright (C) 2014 Chris Drumgoole

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

Packet Descriptions 1: https://github.com/magicmonkey/lifxjs/blob/master/Protocol.md
Packet Descriptions 2: https://github.com/magicmonkey/lifxjs/blob/master/wireshark/lifx.lua

*/

namespace Lightd;

const VERSION = "0.9.3 (e)cdrum";

//const LIFX_HOST = "lifx";
const LIFX_PORT = 56700;

const BUILD_GW_PASSES = 5;

const API_LISTEN_ADDR = "0.0.0.0";
const API_LISTEN_PORT = 5439;

abstract class LogLevel
{
    const DEBUG = 0;
    const INFO = 1;
}

abstract class ApiResponseType
{
	const ERROR = 0;
	const OK = 1;
}

const LOG_LEVEL = LogLevel::DEBUG;

require_once "nanoserv/nanoserv.php";
require_once "nanoserv/handlers/HTTP/Server.php";
require_once "lifx.php";

use Nanoserv\HTTP\Server as HTTP_Server;
use Nanoserv\Core as Nanoserv;

use Lightd\Drivers\Lifx\Packet as Lifx_Packet;
use Lightd\Drivers\Lifx\Handler as Lifx_Handler;
use Exception;

/* Running vars */
$LIFX_Gateways = new LIFX_Gateways;
$LIFX_Bulbs = new Lights();
$LIFX_Patterns = new Patterns;

class LIFX_Gateway {
	
	private $mac_address;
	private $ip_address;
	private $listen_port;
	private $last_refresh_ts;
	private $socket_connection;
	
	public function __construct($mac_address = null, $ip_address = null, $listen_port = null, $last_refresh_ts = null) {
		log("construct LIFX_Gateway");
		$this->mac_address = $mac_address;
		$this->ip_address = $ip_address;
		$this->listen_port = $listen_port;
		$this->last_refresh_ts = $last_refresh_ts;
		
		// Made a concious decision not to do the socket connection on constructor
	}
	
	public function getMacAddress() {
		return $this->mac_address;
	}
	
	public function getLastRefreshTS() {
		return $this->last_refresh_ts;
	}

	public function setLastRefreshTS($t = null) {
		if (!$t) {
			$t = time();
		}
		
		$this->last_refresh_ts = $t;
		return true;
	}
	
	public function getSocketConnection() {
		return $this->socket_connection;
	}
	
	public function establish_connection() {
		$this->socket_connection = Nanoserv::New_Connection("tcp://" . $this->ip_address . ":" . $this->listen_port, __NAMESPACE__ . "\\Lifx_Client");
		$this->socket_connection->Connect();
		Nanoserv::Run(-1);
		sleep(1);
		
		// Check to see if the socket connection is valid
		if (!$this->socket_connection->socket->connected) {
			log("Shoot! Cannot connect to the Gateway. Not sure how to recover. :(");
			return false;
		} else {
			log("Connected to Gateway " . $this->mac_address);
			return true;
		}
	}
	
	public function maintainConnection($t) {
		if ($this->socket_connection->must_reconnect) {
			log("lost connection, trying to reconnect ...");
			sleep(3);
			$this->establish_connection();
		} else {
			if (($this->getLastRefreshTS() + 2) < $t) {
				log("Refreshing state of gw " . $this->mac_address);
				$this->socket_connection->Refresh_States();
				$this->setLastRefreshTS($t);
			}
		}
	}
	
}

class LIFX_Gateways {
	
	private $gateways = array();
	
	public function addGateway($gateway) {
		// First check if we are getting the correct object type
		if (!is_object($gateway)) {
			// Error!
			log("Error on adding LIFX Gateway - passed variable is not even an object!");
			return false;
		}
		
		if (!is_a($gateway, "Lightd\LIFX_Gateway")) {
			// Error!
			log("Error on adding LIFX Gateway - passed object not valid type. It is " . get_class($gateway));
			return false;
		} else {
			log("Adding new gateway to Gateways object - " . $gateway->getMacAddress());
			$this->gateways[$gateway->getMacAddress()] = $gateway;
			
			// Now, connect!
			if(!$this->gateways[$gateway->getMacAddress()]->establish_connection()) {
				// UNable to connect so let's remove it
				log("Couldn't connect, so removing gateway");
				unset($this->gateways[$gateway->getMacAddress()]);
			} 
			return true;
		}
	}
	
	public function getGateways() {
		return $this->gateways;
	}
	
	public function removeGatewayByMac($gateway_mac) {
		// First, let's see if we even have this gateway
		if (array_key_exists($gateway_mac, $this->gateways)) {
			unset($this->gateways[$gateway_mac]);
			log("Successfully removed gateway");
			return true;
		} else {
			log("Hmm.. tried to remove a gateway that didnt exist. This seems like a problem to me...");
			exit(1);
		}
	}
	
	public function removeGatewaysByMacInArray($gateway_macs = null) {
		if (!$gateway_macs) {
			return false;
		}
		
		$removed_count = 0;
		
		foreach ($gateway_macs as $gw_mac) {
			$result = $this->removeGatewayByMac($gw_mac);
			
			if ($result) {
				$removed_count++;
			}
		}
		
		return $removed_count;
		
	}
	
	// Returns gateway object if exists by mac address of gateway
	public function getGatewayByMac($gateway_mac) {
		if (array_key_exists($gateway_mac, $this->gateways)) {
			log("Checking, and found an existing gateway", LogLevel::DEBUG);
			return $this->gateways[$gateway_mac];
		} else {
			return null;
		}
	}	
}

/*
Class manages a single bulb
*/
class Light {
	
	//static public $all = []; // This is the array that stores all the bulbs

	public $id;
	public $label;
	public $gw;
	public $tags;
	public $rgb;
	public $power;
	public $extra;
	public $state_ts;

	public function __construct($id = null, $label = null, $gw = null, $tags = null, $rgb = null, $power = null, $extra = null) {
		$this->id = $id;
		$this->label = $label;
		$this->gw = $gw;
		$this->tags = $tags;
		$this->rgb = $rgb;
		$this->power = $power;
		$this->extra = $extra;
		
		$this->state_ts = time();
		
		$this->LIFX_Gateways = & $GLOBALS["LIFX_Gateways"];
	}
	
	public function refreshBulb($id, $label, $gw, $tags, $rgb, $power, $extra) {		
		log("Refreshing bulb information for bulb {$id}", LogLevel::DEBUG);
		
		$this->id = $id;
		$this->label = $label;
		$this->gw = $gw;
		$this->tags = $tags;
		$this->rgb = $rgb;
		$this->power = $power;
		$this->extra = $extra;
		
		// Refreshed now
		$this->state_ts = time();
	}

	public function Set_Power($power = true) {
		log("Requesting to set power of {$this->id} to {$power}", LogLevel::INFO);
		
		// Check if our socket connection exists, if not, fail gracefully
		if (!is_object($this->LIFX_Gateways->getGatewayByMac($this->gw))) {
			log("Unable to connect to socket (the gateway object doesn't exist!), hopefully this is temporary!");
			return;
		}
		
		$this->LIFX_Gateways->getGatewayByMac($this->gw)->getSocketConnection()->Set_Power($power, $this->id);
	}

	public function Set_Color($rgb, array $extra = []) {
		log("Requesting to set color of {$this->id} to {$rgb}", LogLevel::INFO);
		
		// Check if our socket connection exists, if not, fail gracefully
		if (!is_object($this->LIFX_Gateways->getGatewayByMac($this->gw))) {
			log("Unable to connect to socket (the gateway object doesn't exist!), hopefully this is temporary!");
			return;
		}
		
		$this->LIFX_Gateways->getGatewayByMac($this->gw)->getSocketConnection()->Set_Color($rgb, $extra, $this->id);
	}

}


/*
Class manages the lights in the network
*/
class Lights {
	private $bulbs = [];
	
	public function addBulb(Light $newBulb) {
		$this->bulbs[$newBulb->id] = $newBulb;
		log("New bulb registered: id '{$newBulb->id}', label '{$newBulb->label}' on gw '{$newBulb->gw}'", LogLevel::INFO);
	}
	
	public function removeBulb($bulb) {
		if (array_key_exists($bulb->id, $this->bulbs)) {
			unset($this->bulbs[$bulb->id]);
			log("Successfully removed bulb {$bulb->id}");
			return true;
		} else {
			log("Hmm.. tried to remove a bulb that didnt exist. This seems like a problem to me...");
			exit(1);
		}
	}
	
	public function getAllBulbs() {
		//log("Returning dump of all bulb information: " . print_r(array_values($this->bulbs), true));
		return array_values($this->bulbs);
	}
	
	public function getBulbByName($bulb_label) {
		foreach ($this->bulbs as $bulb) {
			if ($bulb->label === $bulb_label) {
				return $bulb;
			}
		}
		throw new Exception("Light not found: {$bulb_label}");
	}

	public function getBulbByMac($bulb_mac) {
		foreach ($this->bulbs as $bulb) {
			if ($bulb->id === $bulb_mac) {
				return $bulb;
			}
		}
		return false;
	}
	
	public function removeOldBulbs($last_heard_from = 100) { // default 100 seconds

		$removed_count = 0;
		
		foreach ($this->bulbs as $bulb) {
			if ((time() - $bulb->state_ts) > $last_heard_from) {
				$this->removeBulb($bulb);
				$removed_count++;
			}
		}
		
		if ($removed_count) {
			log("Removed {$removed_count} bulbs that we haven't heard from in {$last_heard_from} seconds.");
		}
		
		return $removed_count;
	}

	public function dumpAllBulbInfo() {
		foreach ($this->bulbs as $bulb) {
			log($bulb->label . " " . ($bulb->power ? "on" : "off") . " " . $bulb->rgb . " @ " . $bulb->extra["kelvin"] . "K (" . date("Ymd:His", $bulb->state_ts) . ")");
		}
		
	}
	
}

/*
Struct for pattern bulb config
*/

class BulbPatternConfig {
	public $power;
	public $rgb;
	public $kelvin;
}

/*
Class for defining a pattern
*/
class Pattern {
	
	private $pattern_name;
	private $pattern_config;
	
	public function __construct($pattern_name = null) {
		if (!$pattern_name) {
			return null;
		}
		
		$this->pattern_name = $pattern_name;
		$this->pattern_config = array();
	}
	
	public function addPatternElement($bulb_name = null, $bulb_config_string = null) {
		if (!$bulb_name) {
			return null;
		} else {
			if (!array_key_exists($bulb_name, $this->pattern_config)) {
				$this->pattern_config[$bulb_name] = new BulbPatternConfig;
			}
		}
		
		// Extract the power status
		$this->pattern_config[$bulb_name]->power = ($bulb_config_string !== "off") ? 1: 0;

		// Get RGB Config
		if (preg_match('/#([0-9a-fA-F]{6})/', $bulb_config_string, $res)) {
			$this->pattern_config[$bulb_name]->rgb = $res[1];
		}
		
		// Get Kelvin Config
		if (preg_match('/([0-9]+)K/', $bulb_config_string, $res)) {
			$this->pattern_config[$bulb_name]->kelvin = $res[1];
		}	
	}
	
	public function getPatternName() {
		return $this->pattern_name;
	}
	
	public function getPatternConfig() {
		return $this->pattern_config;
	}
}

/*
Class for managing patterns
*/
class Patterns {
	private $patterns = [];
	
	function addPattern($pattern) {
		if (!is_object($pattern)) {
			return 0;
		}

		$this->patterns[$pattern->getPatternName()] = $pattern;
		log("Added a new pattern named {$pattern->getPatternName()}.");
	}
	
	public function countPatterns() {
		return count($this->patterns);
	}
	
	public function getPatternByName($pattern_name) {
		foreach ($this->patterns as $pattern) {
			if ($pattern->getPatternName() === $pattern_name) {
				return $pattern;
			}
		}
		
		return false;
	}	
}

class Lifx_Client extends Lifx_Handler {
	
	function __construct() {
		$this->LIFX_Bulbs = & $GLOBALS["LIFX_Bulbs"];
	}
	
	public function on_Connect() {
		parent::on_Connect();
	}
	public function on_Discover(Lifx_Packet $pkt) {
		parent::on_Discover($pkt);
	}
	public function on_Packet(Lifx_Packet $pkt) {
		// var_dump($pkt);
	}
	public function on_Light_State(Light $l) {
		if ($this->LIFX_Bulbs->getBulbByMac($l->id)) {
			$this->LIFX_Bulbs->getBulbByMac($l->id)->refreshBulb($l->id, $l->label, $l->gw, $l->tags, $l->rgb, $l->power, $l->extra);
			//$rl = $this->LIFX_Bulbs->getBulbByMac($l->id);
		} else {
			$rl = new Light($l->id, $l->label, $l->gw, $l->tags, $l->rgb, $l->power, $l->extra);
			$this->LIFX_Bulbs->addBulb($rl);
		}
	}
}

function encapsulateApiResponse($object, $errorMessage = null, $response_type = ApiResponseType::OK) {
	
	$response_container = array();
	
	switch($response_type) {
		case ApiResponseType::ERROR:
			$response_container["status"] = "ERROR";
			$response_container["errorMessage"] = $errorMessage;
			$response_container["object"] = null;
			break;
		case ApiResponseType::OK:
			$response_container["status"] = "SUCCESS";
			$response_container["errorMessage"] = $errorMessage;
			$response_container["object"] = $object;
			break;
		default:
			break;
	}
	
	return json_encode($response_container, JSON_PRETTY_PRINT);
}

class API_Server extends HTTP_Server {
	
	function __construct() {
		$this->LIFX_Bulbs = & $GLOBALS["LIFX_Bulbs"];
		$this->LIFX_Patterns = & $GLOBALS["LIFX_Patterns"];
		parent::Add_Header("Access-Control-Allow-Origin: *");
		parent::Add_Header("Content-Type: application/json");
	}
	
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
						$this->LIFX_Bulbs->getBulbByName($args[1])->Set_Power($power);
					} else {
						foreach($this->LIFX_Bulbs->getAllBulbs() as $bulb) {
							if (is_object($bulb)) {
								$bulb->Set_Power($power);
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
						$this->LIFX_Bulbs->getBulbByName($args[1])->Set_Color($rgb, [ "hue" => $hue, "saturation" => $saturation, "brightness" => $brightness, "dim" => $dim, "kelvin" => $kelvin]);
					} else {
						foreach($this->LIFX_Bulbs->getAllBulbs() as $bulb) {
							if (is_object($bulb)) {
								$bulb->Set_Color($rgb, [ "hue" => $hue, "saturation" => $saturation, "brightness" => $brightness, "kelvin" => $kelvin, "dim" => $dim]);
							}
						}
					}
				break;

				case "state":
					if ($args[0]) {
						return encapsulateApiResponse($this->LIFX_Bulbs->getBulbByName($args[0]));
					} else {
						return encapsulateApiResponse($this->LIFX_Bulbs->getAllBulbs());
					}
				break;
				
				case "pattern":
					if (!isset($args[0])) {
						return encapsulateApiResponse([
						 	"pattern" => [
							"current" => $GLOBALS["current_pattern"],
							"ts" => $GLOBALS["current_pattern_ts"],
						]
						]);
					} else if (!$this->LIFX_Patterns->getPatternByName($args[0])) {
						throw new Exception("unknown pattern '{$args[0]}'");
					}
				
					// Fade?
					if ($args[1]) {
						$fade = $args[1];
					} else {
						$fade = null;
					}
				
					// Assumption here is there is only one pattern element for each bulb in pattern, so loop through each element
					foreach ($this->LIFX_Patterns->getPatternByName($args[0])->getPatternConfig() as $bulb_name => $bulb_config) {

						// Set power
						$this->LIFX_Bulbs->getBulbByName($bulb_name)->Set_Power($bulb_config->power);
					
						// Set RGB / Kelvin
						if ($bulb_config->rgb) {
							$rgb = "#" . $bulb_config->rgb;
							$this->LIFX_Bulbs->getBulbByName($bulb_name)->Set_Color($rgb, [ 
								"kelvin" => $bulb_config->kelvin,
								"fade" => $fade,
							]);
						}
					}
					$GLOBALS["current_pattern"] = $args[0];
					$GLOBALS["current_pattern_ts"] = time();
					return encapsulateApiResponse("pattern set");
					break;
				default:
					throw new Exception("invalid command '{$cmd}'");
					break;
			}
			
		} catch (Exception $e) {
			$this->Set_Response_Status(400);
			log("API Exception 400 thrown: {$e->getMessage()}", LogLevel::INFO);
			return encapsulateApiResponse(null, "{$e->getMessage()}", ApiResponseType::ERROR);
		}
	}
}

function log($msg, $level = LogLevel::DEBUG) {

	if ($level >= LOG_LEVEL) {
		switch ($level) {
			case LogLevel::INFO:
				print (date("Ymd:His") . " - INFO  - " . $msg . "\n");
				break;
			case LogLevel::DEBUG:
			default:
				print (date("Ymd:His") . " - DEBUG - " . $msg . "\n");
				break;
		}
	}
	
	return;
}

/* Function to get list of active gateways */
// TODO: move this to a class
function find_gateways() {
	log("Looking for LIFX Gateways");
	
	// Create packet for requesting gateways
	$packet = new Lifx_Packet(0x02);
	$broadcast_string = $packet->Encode();

	// Make socket for broadcasting request on network
	$broadcast_socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP); 
	if ($broadcast_socket == false) { die("SendSock: ".socket_strerror(socket_last_error())); } 

	$setopt = socket_set_option($broadcast_socket, SOL_SOCKET, SO_BROADCAST, 1); 
	if ($setopt == false) { die(socket_strerror(socket_last_error())); } 

	log("Sending broadcast 0x02");
	socket_sendto($broadcast_socket, $broadcast_string, strlen($broadcast_string), 0, '255.255.255.255', LIFX_PORT); 
	
	socket_close($broadcast_socket);

	// Make socket for listening for broadcast responses
	$listen_socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP); 
	if ($listen_socket == false) { die("SendSock: ".socket_strerror(socket_last_error())); } 
	if (socket_bind($listen_socket, "0.0.0.0", 56700) === false) {
	    echo "socket_bind() failed: reason: " . socket_strerror(socket_last_error($listen_socket)) . "\n";
	}

	socket_set_option($listen_socket,SOL_SOCKET,SO_RCVTIMEO,array("sec"=>1,"usec"=>0));

	// Create gw holder array
	$found_gateways = array();
	
	// Hiding listen timeout warnings
	$oldErrorReporting = error_reporting(); // save error reporting level
	error_reporting($oldErrorReporting ^ E_WARNING); // disable warnings
	
	// Loop through an arbitrary number of passes.
	$pass = 0;
	log("Listening for Gateways to respond to broadcast");
	while ($pass < BUILD_GW_PASSES) {

		// Create receive variables
		$from = null;
		$port = null;
		$buf = null;

		if (!socket_recvfrom($listen_socket, $buf, 41, 0, $from, $port)) {
			$pass++;
			continue;
		}

		$pkt = Lifx_Packet::Decode($buf);
		if ($pkt->type === 0x03) {
			log("Received a valid 0x03 response from " . $from . ", size of response is " . strlen($buf));

			// Extract the port (should be 56700, but just in case...)
			$port = unpack("V", substr($pkt->payload, 1, 4))[1];
			log("Gateway " . $pkt->gateway_mac . " port is " . $port);

			$gw = array(	
				"mac_address" => $pkt->gateway_mac,
				"ip_address" => $from,
				"listen_port" => $port,
				"last_refresh_ts" => ""		// Add refresh time as we'll use it later 
			);

			$found_gateways[$pkt->gateway_mac] = $gw;
		}

		$pass++;
	}
	error_reporting($oldErrorReporting); // restore error reporting level

	socket_close($listen_socket);

	return $found_gateways;
}

log("lightd-plus/" . VERSION . " Original (c) 2014 by sIX / aEGiS <six@aegis-corp.org> | New (c) 2014 Chris Drumgoole / cdrum.com", LogLevel::INFO);

$current_pattern = "off";
$current_pattern_ts = 0;

foreach (parse_ini_file(dirname(__FILE__) . DIRECTORY_SEPARATOR . "patterns.ini", true, INI_SCANNER_RAW) as $pname => $bulbs) {

	$newPattern = new Pattern($pname);
	foreach ($bulbs as $bname => $str) {
		$newPattern->addPatternElement($bname, $str);
	}
	
	// Add pattern to config
	$LIFX_Patterns->addPattern($newPattern);
}

log("Loaded " . $LIFX_Patterns->countPatterns() . " patterns.");

// Run the API listener
Nanoserv::New_Listener("tcp://" . API_LISTEN_ADDR . ":" . API_LISTEN_PORT, __NAMESPACE__ . "\\API_Server")->Activate();
log("API server listening on port " . API_LISTEN_PORT, LogLevel::INFO);

// Enter loop and manage
log("Starting up...", LogLevel::INFO);

$loop_time = time();	
while (true) {
	Nanoserv::Run(-1);
	$t = time();
	
	
	// Every 5 seconds, check for gateways
	if ((time() - $loop_time) >= 5) {
		// Get initial list of gateways
		$gws = find_gateways(); // TODO: put this in the class

		// Loop through found gateways and add to object
		foreach ($gws as $gw) {

			if ($LIFX_Gateways->getGatewayByMac($gw["mac_address"])) {
				// The gateway exists already

				// Update the connection, if needed
				$LIFX_Gateways->getGatewayByMac($gw["mac_address"])->maintainConnection($t);
				continue;
			} else {
				// The gateway doesn't exist, so let's add it
				$tmp_gw = new LIFX_Gateway($gw["mac_address"], $gw["ip_address"], $gw["listen_port"], time());
				if($LIFX_Gateways->addGateway($tmp_gw)) {
					log("Added new gateway " . $gw["mac_address"], LogLevel::INFO);
				} else {
					log("Error trying to ad new gateway " . $gw["mac_address"], LogLevel::INFO);
				}

				unset($tmp_gw); // clear our temporary object from memory as we added it to our object of gateways
			}
		}

		// Now let's see if we need to remove any gateways, such as if an existing gw was turned off, or absorved by another
		log("Looking for gateways that have gone away.");
	//	print_r($gws);
	//	print_r($lifx_gateways->getGateways());
		$missing_gws = array_keys(array_diff_key($LIFX_Gateways->getGateways(), $gws));
		log("Result of missing gateway check: " . print_r($missing_gws, true), LogLevel::DEBUG);
		if ($missing_gws) {
			log("Found " . count($missing_gws) . " gateways that have gone away.");

			// Now let's remove these from our object store
			$removed_gw_count = $LIFX_Gateways->removeGatewaysByMacInArray($missing_gws);

			log ("Removed " . $removed_gw_count . " missing gateways.");
		}
		$loop_time = time();
	}

	// Check for old bulbs - bulbs that haven't been updqted for a while...
	$LIFX_Bulbs->removeOldBulbs(100);

	// Wait a second until the next run
	sleep(1);
}
?>