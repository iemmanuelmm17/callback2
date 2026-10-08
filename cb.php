<?php

/* 	---------- How to use ------------
	DID must be pointed to an Auto-attendant (Callback AA)
	Call Back AA should be set to go to option 0 every time
	Option 0 is must be routed to an extension that will forward calls to run_callbackv3
		- this extension's caller id number must be set to the Callback AA ($_REQUEST['CallBackAA'])
	Option 1 is must be routed to their Calldecider extension
	Option 2 is must be routed to the Callback AA
	Option 3 is optional and should be a negative value number. The format must be User e.g., User -4.
*/

date_default_timezone_set('UTC');

require_once '/var/www/callback/config.php';require_once('/var/www/html/ns-api/Vendor/vendor/autoload.php');
//require 'config.php'; require_once('\programmer\php\vendor\autoload.php');

use GuzzleHttp\Client;
$server = 'nms2.nyc.birns.net';

// === Helper: Get OAuth Token ===
function getAccessToken(): ?string {
	global $server;
 $url = "https://$server/ns-api/oauth2/token/";
	
	$cacheToken = '/var/www/callback/nsapi_token_cache.json';
	//$cacheToken = 'nsapi_token_cache.json';

    if (file_exists($cacheToken)) {
        $cacheData = json_decode(file_get_contents($cacheToken), true);
        if ($cacheData && isset($cacheData['token'], $cacheData['expiry'])) {
            if ($cacheData['expiry'] > time()) {
                return $cacheData['token'];
            }
        }
    }
	
    $postData = [
        'client_id'     => getenv('CDR_CLIENT_ID') ?: CLIENT_ID,
        'client_secret' => getenv('CDR_CLIENT_SECRET') ?: CLIENT_SECRET,
        'username'      => getenv('CDR_USERNAME') ?: SUPER_USER,
        'password'      => getenv('CDR_PASSWORD') ?: SUPER_PASS,
        'grant_type'    => 'password',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => http_build_query($postData),
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_SSL_VERIFYPEER  => true,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $responseData = json_decode($response, true);
    $accessToken = $responseData['access_token'] ?? null;
    $expiresIn = $responseData['expires_in'] ?? 3600;

    if ($accessToken) {
        file_put_contents($cacheToken, json_encode([
            'token'  => $accessToken,
            'expiry' => time() + $expiresIn - 60,
        ]));
    }

    return $accessToken;
}

function isExtensionExcluded(?string $excludedExt, string $ext): bool {
	if (empty($excludedExt)) return false;
	$arr = array_map('trim', explode(',', $excludedExt));
	return in_array($ext, $arr, true);
}

// === Helper: Generate Prompt Identifier ===
function generatePromptIdentifier($accountUser) {
    $length = strlen($accountUser);
    if ($length == 2) return $accountUser . '0001';
    if ($length == 3) return $accountUser . '001';
    if ($length == 4) return $accountUser . '01';
    return $accountUser . '1';
}

// === Helper: Print Forward Response ===
function printForwardResponse($extension, $domain) {
    print("<Response><Forward>sip:$extension@$domain</Forward></Response>");
		exit();
}

// === Helper: Print Gather Response ===
function printGatherResponse($origUser, $callDeciderExt, $domain, $message, $audio = 'default') {
	if ($audio == 'default') {
		print("<Response><Gather timeout='5' numDigits='6' action='callbackfeaturev2.php?did=${origUser}&amp;cdext=${callDeciderExt}&amp;domain=${domain}'>
			<Say language='en-US' voice='amy'>$message</Say>
		</Gather><Hangup/></Response>");
		exit();
	}
	else {
		print("<Response>
			//<Play>" . $audio . "</Play>
			<Gather numDigits='6' timeout='5' action='callbackfeaturev2.php?did=${origUser}&amp;cdext=${callDeciderExt}&amp;domain=${domain}'>
			<Play>" . $audio ."</Play>
			</Gather>
			<Hangup/></Response>");
			exit();
	}
}


// === Extract Request Parameters ===
$accountUser = $_GET['AccountUser'] ?? '';
$customerDomain = $_GET['AccountDomain'] ?? '';
$callerNumber = $_REQUEST['NmsAni'] ?? '';
if ($callerNumber === '' && isset($_POST['NmsAni'])) {
    $callerNumber = $_POST['NmsAni'];
}

$calledNumber = $_REQUEST['NmsDnis'] ?? '';
if ($calledNumber === '' && isset($_POST['NmsDnis'])) {
    $calledNumber = $_POST['NmsDnis'];
}

$searchTimeOffsetHours = -4;

if ($callerNumber && strlen($callerNumber) === 11) {
    $callerNumber = substr($callerNumber, 1);
}

if ($calledNumber && strlen($calledNumber) === 11) {
    $calledNumber = substr($calledNumber, 1);
}

if ($callerNumber && strlen($callerNumber) <= 5) {
	$callerNumber = 'sip:' . $callerNumber . '@' . $customerDomain;
}

$accessToken = getAccessToken();
if (!$accessToken) {
    printForwardResponse($callDeciderExtension, $customerDomain);
}

if ($accountUser) {
    $promptIdentifier = generatePromptIdentifier($accountUser);
    $promptName = 'Prompt_' . $promptIdentifier;
    $promptApiUrl = "https://$server/ns-api/v2/domains/$customerDomain/users/$accountUser/autoattendants/$promptName";

    $httpClient = new Client();
    $promptResponse = $httpClient->get($promptApiUrl, [
        'headers' => [
            'Accept' => 'application/json',
            'Authorization' => "Bearer $accessToken"
        ]
    ]);

    $promptData = json_decode($promptResponse->getBody(), true);
	
	$AAcallDeciderExtension = $promptData['auto-attendant']['option-0']['destination-user'];
    $callDeciderExtension = $promptData['auto-attendant']['option-1']['destination-user'];
    $autoAttendantExtension = $promptData['auto-attendant']['option-2']['destination-user'];
    $searchTimeOffsetHours = $promptData['auto-attendant']['option-3']['destination-user'] ?? $searchTimeOffsetHours;
	$messageUrl = $promptData['auto-attendant']['option-4']['audio']['file-access-url'] ?? 'default';
	$excludedExt = $promptData['auto-attendant']['option-5']['audio']['file-script-text'] ?? null;
	
	$ignoredList = array_filter(array_map('trim', explode(',', $excludedExt)));
	
	$escapedUrl = htmlspecialchars($messageUrl, ENT_QUOTES);
	
	if ($searchTimeOffsetHours > 0) {
		$searchTimeOffsetHours = -abs($searchTimeOffsetHours);
	}
}

$isExtensionExcluded = false;
$currentDateTime = new DateTime('now', new DateTimeZone('UTC'));
$pastDateTime = clone $currentDateTime;
$pastDateTime->modify("$searchTimeOffsetHours hours");

$searchStartDateTime = $pastDateTime->format('Y-m-d H:i:s');
$searchEndDateTime = $currentDateTime->format('Y-m-d H:i:s');

// === Helper: Check for Recent Conversation ===
function checkRecentConversation($customerDomain, $callerNumber, $accessToken, $autoAttendantExt, $callDeciderExt, $callbackAAExt, $excludedExt): ?string {
	global $ignoredList;
    $currentUtcTime = new DateTime('now', new DateTimeZone('UTC'));
    $pastUtcTime = clone $currentUtcTime;
    $pastUtcTime->modify('-1 hours');

    $startTime = $pastUtcTime->format('Y-m-d H:i:s');
    $endTime = $currentUtcTime->format('Y-m-d H:i:s');
    global $server;	
    $cdrApiUrl = "https://$server/ns-api/v2/domains/$customerDomain/cdrs?datetime-start=$startTime&datetime-end=$endTime&caller=$callerNumber";
    $httpClient = new Client();
	
    $response = $httpClient->get($cdrApiUrl, [
        'headers' => [
            'Accept' => 'application/json',
            'Authorization' => "Bearer $accessToken"
        ]
    ]);

    $cdrRecords = json_decode($response->getBody(), true);
	//echo '<pre>';echo json_encode($cdrRecords, JSON_PRETTY_PRINT);echo '</pre>';//header('Content-Type: text/xml');
	
    foreach (array_reverse($cdrRecords) as $callRecord) {
	$isNotAnAACall = $callRecord['call-leg-ordinal-index'] ?? null;
        $terminatingUser = $callRecord['call-term-user'] ?? null;		
		
        $talkDurationSeconds = $callRecord['call-talking-duration-seconds'] ?? 0;
        if ($terminatingUser != $callbackAAExt && $terminatingUser != $callDeciderExt && $terminatingUser != $autoAttendantExt && $talkDurationSeconds >= 3 && $isNotAnAACall) {
			$ignoredList = array_map('intval', $ignoredList);
			$terminatingUser = (int) $terminatingUser;
			if (in_array($terminatingUser, $ignoredList, true)) {
				return 0;
			}
            return $terminatingUser;
        }
    }
    return 0;
}


$recentContactExtension = checkRecentConversation($customerDomain, $callerNumber, $accessToken, $autoAttendantExtension, $callDeciderExtension, $AAcallDeciderExtension, $excludedExt);
if ($recentContactExtension) {
	$message = "Would you like to reconnect to extension <say-as interpret-as='digits'>$recentContactExtension</say-as>? Press 1 for yes, or 2 for no.";

    printGatherResponse($recentContactExtension, $callDeciderExtension, $customerDomain, $message, $escapedUrl);
	exit();
}

$httpClient = new Client();
$cdrSearchUrl = "https://$server/ns-api/v2/domains/$customerDomain/cdrs?datetime-start=$searchStartDateTime&datetime-end=$searchEndDateTime&dialled=$callerNumber";

try {
    $cdrResponse = $httpClient->get($cdrSearchUrl, [
        'headers' => [
            'Accept' => 'application/json',
            'Authorization' => "Bearer $accessToken",
        ]
    ]);

    $cdrData = json_decode($cdrResponse->getBody(), true);
    $matchFound = false;

    if (is_array($cdrData) && !empty($cdrData)) {
        foreach ($cdrData as $cdr) {
            $recentOriginationUser = strval($cdr['call-orig-user'] ?? '');
            $recentTalkDuration = intval($cdr['call-talking-duration-seconds'] ?? 0);
            $recentOrigCallerID = strval($cdr['call-orig-caller-id'] ?? '');
			$extensionName = strval($cdr['call-orig-from-name'] ?? '');

            if ($recentOrigCallerID === $calledNumber) {
                $matchFound = true;

                if ($recentTalkDuration >= 3) {
                    $message = "Would you like to reconnect to $extensionName? Press 1 for yes, or 2 for no.";
                } else {
                    $message = "Are you returning $extensionName call? Press 1 for yes, or 2 for no.";
                }
				$ignoredList = array_map('intval', $ignoredList);
				$recentOriginationUser = (int) $recentOriginationUser;
				if (in_array($recentOriginationUser, $ignoredList, true)) {
					printForwardResponse($callDeciderExtension, $customerDomain);
				}
				else {
					printGatherResponse($recentOriginationUser, $callDeciderExtension, $customerDomain, $message, $escapedUrl);
				}
                break; // Stop loop once match is handled
            }
        }

        if (!$matchFound) {
            printForwardResponse($callDeciderExtension, $customerDomain);
        }
    } else {
        printForwardResponse($callDeciderExtension, $customerDomain);
    }

} catch (Exception $e) {
    printForwardResponse($callDeciderExtension, $customerDomain);
}
