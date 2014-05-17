lightd
======

lightd is a simple HTTP gateway for the lifx binary protocol
This update eliminates the need for a fixed IP/known list of gateways. This script will now 
monitor the entire address space for gateways and control bulbs from all gateways.

#### Requirements

- command line PHP binary (>= 5.4)
- configured lifx bulbs in the same subnet as the computer running this script

lightd will send a broadcast request to the subnet asking for gateways to identify themselves.
Assuming they do, this script will provides its REST API on port 5439, you may change these values at
the top of lightd.php

if everything is set up correctly, you should see something like this when you
run lightd from the command line :

```
20140123:204248 lightd/0.9.0 (c) 2014 by sIX / aEGiS
20140123:204248 loaded 5 patterns
20140123:204248 connected to lifx
20140123:204248 API server listening on port 5439
20140123:204248 found gateway bulb at d073d5014736
20140123:204248 new bulb registered: Kitchen
20140123:204249 new bulb registered: Living
```

you may want to create a startup script and redirect the standard output to a
log file if you wish to run it long term.

#### API methods

##### Power on/off

```
/power/(on|off)[/<bulb_label>]
```

if bulb_label is not given, the command applies to all bulbs in the lifx mesh

examples:
* /power/on/Kitchen
* /power/off

##### Set color

```
/color/<rgb>-<hue>-<saturation>-<brightness>-<dim>-<kelvin>[/<bulb_label>]
```

if bulb_label is not given, the color is applied to all bulbs in the lifx mesh

examples:
* /color/002040-21845-35000-65535-0-3500
* /color/ffffff-21845-35000-65535-0-3500/Kitchen
* /color/002040-0-0-22955-0-3500/Kitchen

##### Set pattern

```
/pattern/<name>[/<transition_time_ms>]
```

patterns are read from the patterns.ini file
if transition_time_ms is not given, the pattern is applied immediately

examples:
* /pattern/off
* /pattern/movies/10000
* /pattern/night/3600000

##### Dump state

```
/state
```

dumps a JSON encoded array of bulb objects with their current state

sample output:
```
[
    {
        "id": "d073d5014736",
        "label": "Kitchen",
        "tags": 0,
        "state_ts": 1390508260,
        "rgb": "#000000",
        "power": true,
        "extra": {
            "hue": 0,
            "saturation": 0,
            "brightness": 0,
            "dim": 0,
            "kelvin": 3000
        }
    },
    {
        "id": "d073d500bf47",
        "label": "Living",
        "tags": 0,
        "state_ts": 1390508261,
        "rgb": "#191919",
        "power": true,
        "extra": {
            "hue": 0,
            "saturation": 0,
            "brightness": 6425,
            "dim": 0,
            "kelvin": 2800
        }
    }
]
```
