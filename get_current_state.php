<?php

const URL = "http://192.168.1.221:5439/";

$result = json_decode(file_get_contents(URL . "/state"));

print "For patterns:\n";

foreach($result as $bulb) {
	
	$onoff = ($bulb->power) ? "on" : "off";
	print $bulb->label . " = " . $onoff . "\n";
	print $bulb->label . " = " . $bulb->rgb . " " . $bulb->extra->kelvin . "K\n"; 
	
}

//print_r($result);


?>