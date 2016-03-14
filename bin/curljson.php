#!/usr/bin/php
<?php

// apt-get install php5 php5-curl
// Stepan Kokhanovskiy, skokhanovskiy@gmail.com

function print_help()
{
	echo "
curljson.php makes http request and gets value of specified key from json response

Usage: curljson.php --url URL [--post DATA] [--username USER] [--password SECRET] [--key KEY] [--feature METHOD [--macro KEY]] [--verbose]
       curljson.php --url URL --output FILE [--post DATA] [--username USER] [--password SECRET] [--key KEY] [--verbose]
       curljson.php --input FILE [--age SEC] [--key KEY] [--feature METHOD [--macro KEY]] [--verbose]
       curljson.php --help

Parameters:
 --url,       -l   - the url for request
 --post,      -s   - make POST request with specified playload, GET used if not specified
 --username,  -u   - the username for basic access authentication
 --password,  -p   - the password for basic access authentication
 --input,     -i   - path to the file with source data obtained by using --output
 --age,       -a   - max age of --input file in seconds
 --key,       -k   - path to the key in json, fields separated by dot
 --output,    -o   - path to the file for save source data
 --feature,   -f   - use extended feature method for building output, see the list below
 --macro,     -m   - the path to key that value will be used in feature method, may be multiple
 --verbose,   -v   - enable verbose output
 --debug           - same as --verbose
 --help,      -h   - print this help

Supported feature methods:
 zabbix-lld        - build json for zabbix low-level discovery
 sum               - calculate sum of elements

Examples:

Get number of requests from ngx_http_status_module for 'mysite.com' zone
# curljson.php --url nginx:15478/status/server_zones --key '\"mysite.com\".requests'

Get number of published messages from rabbitmq for queue 'myqueue' in vhost '/'
# curljson.php --url rabbitmq:15672/api/queues///myqueue?columns=message_stats --username guest -password guest --key message_stats.publish

Get zabbix low-level discovery json from elasticsearch with kibana dashboard's titles
# curljson.php --url elasticsearch:9200/kibana-int/dashboard/_search? --post '{\"query\":{\"query_string\":{\"query\":\"title:*\"}},\"size\":20}' --key hits.hits --feature zabbix-lld --macro _source.title
\n";
}

function get_lastvalue($opt)
{
	if (is_array($opt))
	{
		$opt = end($opt);
	}
	return $opt;
}

function exit_error($error, $code = 1)
{
	fwrite(STDERR, $error);
	if ($code != 0)
		exit($code);
}

function print_debug($string)
{
	global $opts;
        if (isset($opts["debug"]) && $opts["debug"])
        {
                fwrite(STDERR, "\e[1;33m$string\e[0m");
        }
}

function array_value($array, $keys) {
	if (is_array($keys))
	{
		$result = array_reduce(
			$keys,
			function ($x, $key)
			{
				if (empty($key) && is_array($x))
				{
					reset($x);
					$key = key($x);
				}
				if (isset($x[$key]))
				{
					return $x[$key];
				}
			},
			$array
		);
	}
	else
	{
		$result = $array[$keys];
	}
	return $result;
}

function checksum($sum, $value)
{
	if ( ! is_numeric($value))
	{
		if ( ! is_null($value))
		{
			print_debug("Not a number value:\n" . var_export($value, true) . "\n");
			exit_error("Not a number value\n");
		}
	}
	else
	{
		print_debug("Add to sum: " . $value . "\n");
		$sum += $value;
	}
	return $sum;
}

$debug = false;

$shortopts  = "";
$shortopts .= "i:";	// input
$shortopts .= "a:";	// age
$shortopts .= "l:";	// url
$shortopts .= "s:";	// post
$shortopts .= "u::";	// username
$shortopts .= "p::";	// password
$shortopts .= "k:";	// key
$shortopts .= "o:";	// output
$shortopts .= "f:";	// feature
$shortopts .= "m:";	// macro
$shortopts .= "v";	// verbose
$shortopts .= "h";	// help

$longopts = array(
	"input:",
	"age:",
	"url:",
	"post:",
	"username:",
	"password:",
	"key:",
	"output:",
	"feature:",
	"macro:",
	"verbose",
	"debug",
	"help"
);

$multiopts = array(
	"macro"
);

$opts = getopt($shortopts, $longopts);

if( ! $opts)
{
	exit_error("Can not parse command line arguments\n", 0);
	print_help();
	exit(1);
}

foreach ($opts as $optkey => $optvalue)
{
	switch ($optkey)
	{
		case "i":
			$opts["input"] = $optvalue;
			break;
		case "a":
			$opts["age"] = $optvalue;
			break;
		case "l":
			$opts["url"] = $optvalue;
			break;
		case "s":
			$opts["post"] = $optvalue;
			break;
		case "u":
			$opts["username"] = $optvalue;
			break;
		case "p":
			$opts["password"] = $optvalue;
			break;
		case "k":
			$opts["key"] = $optvalue;
			break;
		case "o":
			$opts["output"] = $optvalue;
			break;
		case "f":
			$opts["feature"] = $optvalue;
			break;
		case "m":
			$opts["macro"] = $optvalue;
			break;
		case "v":
		case "verbose":
		case "debug":
			$opts["debug"] = true;
			break;
		case "h":
		case "help":
			print_help();
			exit(1);
	}
}

print_debug("Source options:\n" . var_export($opts, true) . "\n");

foreach ($opts as $optkey => $optvalue)
{
	if (( ! in_array($optkey, $multiopts)) && is_array($optvalue))
	{
		$opts[$optkey] = get_lastvalue($optvalue);
	}
}

if (isset($opts["url"]))
{
	$opts["url"] = str_replace("///", "/%2f/", $opts["url"], $replacecount);
}

if ((isset($opts["username"]) && empty($opts["username"])) || ( ! isset($opts["username"]) && isset($opts["password"])))
{
	echo "Username: ";
	$opts["username"] = preg_replace('/\r?\n$/', "", fgets(STDIN));
}

if ((isset($opts["password"]) && empty($opts["password"])) || ( ! isset($opts["password"]) && isset($opts["username"])))
{
	echo "Password: ";
	$opts["password"] = preg_replace('/\r?\n$/', "", `stty -echo; head -n1; stty echo`);
	echo "\n";
}

if (isset($opts["key"]))
{
	if (empty($opts["key"]))
	{
		unset($opts["key"]);
	}
	else
	{
		$opts["key"] = str_getcsv($opts["key"], '.');
	}
}

if (isset($opts["macro"]))
{
	if ( ! is_array($opts["macro"]))
	{
		$opts["macro"] = array($opts["macro"]);
	}
	foreach ($opts["macro"] as $macrokey => $macrovalue)
	{
		if ( ! empty($opts["macro"][$macrokey]))
		{
			$opts["macro"][$macrokey] = str_getcsv($macrovalue, '.');
		}
	}
}

if (isset($opts["age"]) && empty($opts["age"]))
{
	unset($opts["age"]);
}

print_debug("Ordered options:\n" . var_export($opts, true) . "\n");

if (isset($opts["input"]))
{
	print_debug("Input from file: '" . $opts["input"] . "'\n");
	if ( ! is_readable($opts["input"]))
	{
		exit_error("File is not available: " . $opts["input"] . "\n");
	}
	if (isset($opts["age"]))
	{
		date_default_timezone_set(@date_default_timezone_get());
		$filetime = filemtime($opts["input"]);
		print_debug("File time: " . $filetime . ", " . date("c", $filetime) . "\n");
		$curtime = time();
		print_debug("Current time: " . $curtime . ", " . date("c", $curtime) . "\n");
		if ($filetime && ($curtime - $filetime > $opts["age"]))
		{
			exit_error("File is too old: " . $opts["input"] . "\n");
		}
	}
	if (($cnts = file_get_contents($opts["input"], true)) === false)
	{
		exit_error("An error occured while load data from file: " . $opts["input"] . "\n");
	}
	print_debug("File contents:\n" . $cnts . "\n");
	if (($json = unserialize($cnts)) === FALSE)
	{
		print_debug("An error occured while unserialize file contents: " . $cnts . "\n");
		exit_error("An error occured while unserialize file contents: " . $opts["input"] . "\n");
	}
}
else if (isset($opts["url"]))
{
	print_debug("Input from curl\n");
	$curlopts = array(
		CURLOPT_URL => $opts["url"],
		CURLOPT_RETURNTRANSFER => true
	);
	if ( ! empty($opts["username"]) && ! empty($opts["password"]))
	{
		$curlopts += array(
			CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
			CURLOPT_USERPWD => $opts["username"] . ":" . $opts["password"]
		);
	}
	if (isset($opts["post"]))
	{
		$curlopts += array(
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $opts["post"]
		);
	}
	print_debug("Curl options:\n" . var_export($curlopts, true) . "\n");

	$curl = curl_init();

	curl_setopt_array($curl, $curlopts);
	print_debug("Curl started\n");
	$curlres = curl_exec($curl);
	if (curl_errno($curl))
	{
		exit_error("The error occured while curl executes: " . curl_error($curl) . "\n");
	}
	$curlinfo = curl_getinfo($curl);
	print_debug("Curl info:\n" . var_export($curlinfo, true) . "\n");
	print_debug("Curl result: " . $curlres . "\n");
	if ($curlinfo["http_code"] != 200)
	{
		exit_error("Response code is not 200: " . $curlinfo["http_code"] . "\n");
	}

	curl_close($curl);

	$json = json_decode($curlres, true);
	if (json_last_error() != JSON_ERROR_NONE)
	{
		exit_error("The error occured while JSON decoding: " . json_last_error() . "\n");
	}
} 
else
{
	exit_error("Input file or URL for curl is not defined\n");
}

print_debug("JSON:\n" . var_export($json, true) . "\n");
if (empty($json))
{
	exit_error("Empty json\n");
}

if (isset($opts["key"]))
{
	$json = array_value($json, $opts["key"]);
	if (is_null($json))
	{
		exit_error("Can not find key path in JSON: \"" . implode("\".\"", $opts["key"]) . "\"\n");
	}
	print_debug("Keyed JSON:\n" . var_export($json, true) . "\n");
}

if (isset($opts["output"]))
{
	print_debug("Output to file mode\n");
	if (file_put_contents($opts["output"], serialize($json), FILE_USE_INCLUDE_PATH) === false)
	{
		exit_error("An error occured while save data to file: " . $opts["output"] . "\n");
	}
	print_debug("Data saved to file: " . $opts["output"] . "\n");
}
else if (isset($opts["feature"]))
{
	if ( ! is_array($json))
	{
		$json = array(0 => $json);
	}
	switch($opts["feature"])
	{
		case "zabbix-lld":
			print_debug("Zabbix low-level discovery mode\n");
			$discovery = array();
			if (isset($opts["macro"]))
			{
				foreach ($json as $jsonkey => $jsonvalue)
				{
					$item = array();
					foreach (array_values($opts["macro"]) as $m)
					{
						if (empty($m))
						{
							$item["{#ID}"] = $jsonkey;
						}
						else
						{
							$name = sprintf("{#%s}", strtoupper(get_lastvalue($m)));
							$item[$name] = array_value($jsonvalue, $m);
							if (is_null($item[$name]))
							{
								exit_error("Can not find key path in JSON: \"" . implode("\".\"", $m) . "\"\n");
							}
						}
					}
					$discovery[] = $item;
				}
			}
			else
			{
				foreach (array_keys($json) as $item)
				{
					$discovery[] = ["{#NAME}" => $item];
				}
			}
			print_debug("Discovery array:\n" . var_export($discovery, true) . "\n");
			$discovery = sprintf('{"data":%s}', json_encode($discovery));
			if (json_last_error() != JSON_ERROR_NONE)
			{
				exit_error("The error occured while JSON encoding: " . json_last_error() . "\n");
			}
			echo $discovery . "\n";
			break;
		case "sum":
			print_debug("Sum of keys mode\n");
			$sum = 0;
			foreach (array_values($json) as $j)
			{
				if (isset($opts["macro"]))
				{
					foreach (array_values($opts["macro"]) as $m)
					{
						$sum = checksum($sum, array_value($j, $m));
					}
				}
				else
				{
					$sum = checksum($sum, $j);
				}
			}
			echo $sum . "\n";
			break;
		default:
			exit_error("Unknown feature: " . $opts["feature"] . "\n");
	}
}
else
{
	if (is_array($json))
	{
		exit_error("Result value is array\n");
	}
	if (is_bool($json))
	{
		print_debug("Convert the boolean to integer value\n");
		$json = (int) $json
	}
	echo $json . "\n";
}
exit(0);
?>
