<?php
date_default_timezone_set("Asia/Jakarta");
 
@$msg = $_GET['msg'] ;
@$wa = $_GET['wa'] ;

//$wa = str_replace ('-', '', $wa) ;
$wa = str_replace ('+', '', $wa) ;
$wa = str_replace (' ', '', $wa) ;
if (substr($wa, 0, 1) == '0' ) { $wa = '62' . substr($wa,1,30) ; }


$msg = str_replace ('~r', "\r\n", $msg) ;
$msg = str_replace ('|', "\r\n", $msg) ;
$msg = str_replace ('<br>', "\r\n", $msg) ;
 
 
$token = 'YOUR_API_TOKEN_HERE' ;
$message = $msg ;
$phone = $wa ;

$curl = curl_init();
curl_setopt_array($curl, array(

  CURLOPT_URL => 'https://app.fastwa.com/api/v1/YOUR_URL_PARAM_HERE/send_text' ,
  //'https://app.ruangwa.id/api/send_message',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'POST',
  CURLOPT_POSTFIELDS => 'api_key='.$token.'&phone='.$phone.'&message='.$message,
));
$response = curl_exec($curl);
curl_close($curl);
echo $response;
 
 


//---- pake WASENDER.XYZ
/*
$api = '6285692961782111' ;
$curl =curl_init();
$data = ['number' => $api,// number sender
'message' => $msg,// message content
'to' => $wa, // number receiver
];

curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data) );
curl_setopt($curl, CURLOPT_URL, 'https://app.wasender.xyz/xend');
curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
$result = curl_exec($curl);
curl_close($curl);

echo "<pre>";
print_r($result);
*/

 

/*
include "key.php"; 

 
$curl = curl_init();

$dataarr = [
  "to" => $wa,
  "message" => $msg
];
$sendrest = json_encode($dataarr, true);

curl_setopt_array($curl, [
  CURLOPT_URL => "$host/messages/send-text",
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 300,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "POST",
  CURLOPT_POSTFIELDS => $sendrest,
  CURLOPT_HTTPHEADER => [
    "Authorization: Bearer $devicekey",
    "Content-Type: application/json",
    "device-key: $devicekey"
  ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);

curl_close($curl);

if ($err) {
  echo "cURL Error #:" . $err;
} else {
   echo $response;
}
 */