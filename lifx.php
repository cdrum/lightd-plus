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

namespace Lightd\Drivers\Lifx;

require_once "nanoserv/nanoserv.php";
require_once "colors.php";

use Lightd\Light;
use Nanoserv\Connection_Handler;

class Packet {

	const HEADER_LENGTH = 36;
	
	public $protocol = 13312;
	public $target_mac;
	public $gateway_mac;
	public $timestamp;
	public $type;
	public $payload;

	static public function Decode($data) {
		$tmp = unpack("vsize/vprotocol/Nreserved1/a6target_mac/nreserved2/a6gateway_mac/nreserved3/Nts1/Nts2/vtype/nreserved4", $data);
		$ret = new self($tmp["type"], bin2hex($tmp["target_mac"]), substr($data, self::HEADER_LENGTH));
		$ret->protocol = $tmp["protocol"];
		$ret->gateway_mac = bin2hex($tmp["gateway_mac"]);
		$ret->timestamp = $tmp["ts1"] + $tmp["ts2"];
		return $ret;
	}

	public function __construct($type = null, $dest = null, $payload = null) {
		$this->type = $type;
		$this->target_mac = $dest;
		$this->payload = $payload;
	}
	
	public function Encode() {
		return pack("vvNa6na6nNNvn", strlen($this->payload) + self::HEADER_LENGTH, $this->protocol, 0, hex2bin($this->target_mac), 0, hex2bin($this->gateway_mac), 0, $this->timestamp, $this->timestamp, $this->type, 0) . $this->payload;
	}

}

abstract class Handler extends Connection_Handler {

	public $gateway_mac;
	public $must_reconnect = false;

	private $buffer = "";
	
	public function on_Connect() {
		// Send discovery packet
		$this->Write((new Packet(0x02))->Encode());
		$this->must_reconnect = false;
	}

	public function on_Connect_Fail($errcode) {
		$this->must_reconnect = true;
	}
	
	public function on_Disconnect() {
		$this->must_reconnect = true;
	}
	
	public function on_Read($data) {
		$this->buffer .= $data;
		while (strlen($this->buffer) >= Packet::HEADER_LENGTH) {
			list(,$len) = unpack("v", $this->buffer);
			$data = substr($this->buffer, 0, $len);
			$this->buffer = substr($this->buffer, $len);
			$pkt = Packet::Decode($data);
			if (!isset($this->gateway_mac)) {
				$this->on_Discover($pkt);
			} else if ($pkt->type === 0x6b) {
				$l = new Light();
				$tmp = unpack("vhue/vsaturation/vbrightness/vkelvin/vdim/vpower/a32label/Ntags1/Ntags2", $pkt->payload);
				$l->rgb = _color_pack(_color_hsl2rgb([$tmp["hue"] / 0xffff, $tmp["saturation"] / 0xffff, $tmp["brightness"] / 0xffff]), true);
				$l->extra = [
					"hue" => $tmp["hue"],
					"saturation" => $tmp["saturation"],
					"brightness" => $tmp["brightness"],
					"dim" => $tmp["dim"],
					"kelvin" => $tmp["kelvin"],
				];
				$l->id = $pkt->target_mac;
				$l->gw = $pkt->gateway_mac;
				$l->power = $tmp["power"] == 0xffff;
				$l->label = trim($tmp["label"]);
				$l->tags = $tmp["tags1"] + $tmp["tags2"];
				$this->on_Light_State($l);
			} else {
				$this->on_Packet($pkt);
			}
		}
	}

	public function on_Discover(Packet $pkt) {
//print "Discovering and setting gateway mac\n";
//print_r($pkt);
		$this->gateway_mac = $pkt->gateway_mac;
		$this->Refresh_States();
	}
	
	public function Send(Packet $pkt) {
//print "Sending packet";
//print_r($pkt);
		$pkt->gateway_mac = $this->gateway_mac;
		$this->Write($pkt->Encode());
	}

	public function Set_Power($power = true, $id = null) {
		$this->Send(new Packet(0x15, $id, pack("v", (int)$power)));
	}

	public function Set_Color($rgb, array $extra = [], $id = null) {
		list($hue, $saturation, $brightness) = _color_rgb2hsl(_color_unpack($rgb, true));
		$hue *= 0xffff;
		$saturation *= 0xffff;
		$brightness *= 0xffff;
		if (isset($extra["hue"])) {
			$hue = $extra["hue"];
		}
		if (isset($extra["saturation"])) {
			$saturation = $extra["saturation"];
		}
		if (isset($extra["brightness"])) {
			$brightness = $extra["brightness"];
		}
		$kelvin = isset($extra["kelvin"]) ? $extra["kelvin"] : 6500;
		$fade = isset($extra["fade"]) ? $extra["fade"] : 0;

		$this->Send(new Packet(0x66, $id, pack("cvvvvV", 0, $hue, $saturation, $brightness, $kelvin, $fade)));
	}

	public function Refresh_States() {
		$this->Send(new Packet(0x65));
	}

	abstract public function on_Packet(Packet $pkt);
	abstract public function on_Light_State(Light $l);

}

?>