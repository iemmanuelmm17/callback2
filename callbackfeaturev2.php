<?php
$digit="";
$digit=@$_REQUEST["Digits"];
$cdext=@$_REQUEST["cdext"];
$did=@$_REQUEST["did"];
$domain=@$_REQUEST["domain"];

if($digit == 1) {
print(" <Response>
 <Forward>sip:${did}@$domain</Forward>
 </Response>
");

} else if($digit == 2) {
print(" <Response>
 <Forward>sip:$cdext@$domain</Forward>
 </Response>
");

}
else if(strlen($digit) >= 3) {
    print("
        <Response>
            <Forward>sip:{$digit}@{$domain}</Forward>
        </Response>
    ");
} 
else{
if ($domain === '11123.WideTelco') {
    echo "<Gather numDigits='1' action='callbackfeaturev2.php'>
            <Play>jewishcallbackivr2.wav</Play>
          </Gather>";
} else {
    print(" <Response>
	 <Forward>sip:$cdext@$domain</Forward>
	 </Response>
	");
}
}
