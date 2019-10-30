<?php
include 'functions.php';
require_once 'vendor/autoload.php';
if (!isset($GLOBALS['redis_connection_string'])) {
    $GLOBALS['redis_connection_string'] = 'tcp://127.0.0.1:6379';
}
$client = new Predis\Client($GLOBALS['redis_connection_string']);
$expiry_minutes = 5;

$input = json_decode(file_get_contents('php://input'), true);
error_log(json_encode($input));

$messaging = $input['entry'][0]['messaging'][0];
if (isset($messaging['message']['attachments'])) {
    $messaging_attachment_payload = $messaging['message']['attachments'][0]['payload'];
}
$senderId  = $messaging['sender']['id'];
if (isset($messaging['message']['text']) && $messaging['message']['text'] !== null) {
    $messageText = $messaging['message']['text'];
    $coordinates = getCoordinatesForAddress($messageText);
} elseif (isset($messaging_attachment_payload) && $messaging_attachment_payload !== null) {
    $coordinates = new Coordinates();
    $coordinates->latitude = $messaging_attachment_payload['coordinates']['lat'];
    $coordinates->longitude = $messaging_attachment_payload['coordinates']['long'];
}

$payload = null;
$answer = "";


$settings = json_decode($GLOBALS['client']->get('messenger_user_day_' . $messaging['sender']['id']));

if (isset($messaging['postback']['payload'])
    && $messaging['postback']['payload'] == "get_started") {
    sendMessage($GLOBALS['title'] . ".  You can search for meetings by entering a City, County or Postal Code, or even a Full Address.  You can also send your location, using the button below.  (Note: Distances, unless a precise location, will be estimates.)");
    sendMessage("By default, results for today will show up.  You can adjust this setting using the menu below.");
} elseif ((isset($messageText) && strtoupper($messageText) == "JFT") || ((isset($messaging['postback']['payload'])
        && $messaging['postback']['payload'] == "JFT"))) {
    $result = get("https://www.jftna.org/jft/");
    $stripped_results = strip_tags($result);
    $without_tabs = str_replace("\t", "", $stripped_results);
    $without_htmlentities = html_entity_decode($without_tabs);
    $without_extranewlines = preg_replace("/[\r\n]+/", "\n\n", $without_htmlentities);
    sendMessage( $without_extranewlines );
} elseif (isset($messageText)
          && strtoupper($messageText) == "MORE RESULTS") {
    $payload = json_decode( $messaging['message']['quick_reply']['payload'] );
    sendMeetingResults($payload->coordinates, getMeetingResults($payload->coordinates, $settings, $payload->results_start));
} elseif (isset($messaging['postback']['payload'])) {
    $payload = json_decode($messaging['postback']['payload']);
    $client->setex('messenger_user_day_' . $senderId, ($expiry_minutes * 60), json_encode($payload));

    $coordinates = getSavedCoordinates($senderId);
    if ($coordinates != null) {
        sendMeetingResults($coordinates, getMeetingResults($coordinates, $settings));
    } else {
        sendMessage('The day has been set to ' . $payload->set_day . ".  This setting will reset to lookup Today's meetings in 5 minutes.  Enter a City, County or Zip Code.");
    }
} elseif (isset($messageText) && strtoupper($messageText) == "THANK YOU") {
    sendMessage( ":)" );
} elseif (isset($messageText) && strtoupper($messageText) == "HELP") {
    sendMessage( "To find more information on this messenger app visit https://github.com/radius314/yap.");
} elseif (isset($messageText) && strtoupper($messageText) == "📞 HELPLINE") {
    $coordinates = json_decode( $messaging['message']['quick_reply']['payload'] )->coordinates;
    if ($coordinates != null) {
        sendServiceBodyCoverage($coordinates);
    } else {
        sendMessage("Enter a location, and then resubmit your request.", $coordinates);
    }
} else {
    sendMeetingResults($coordinates, getMeetingResults($coordinates, $settings));
    $client->setex('messenger_user_location_' . $senderId, ($expiry_minutes * 60), json_encode($coordinates));
}

function sendServiceBodyCoverage($coordinates) {
    $service_body = getServiceBodyCoverage($coordinates->latitude, $coordinates->longitude);
    if ($service_body != null) {
        sendMessage("Covered by: " . $service_body->name . ", their phone number is: " . explode("|", $service_body->helpline)[0], $coordinates);
    } else {
        sendMessage("Cannot find Helpline coverage in the BMLT.  Join the BMLT Facebook Group and ask how to get this working.  https://www.facebook.com/BMLT-656690394722060/", $coordinates);
    }
}

function getSavedCoordinates($sender_id) {
    if ($GLOBALS['client']->get('messenger_user_location_' . $sender_id) != null) {
        return json_decode($GLOBALS['client']->get('messenger_user_location_' . $sender_id));
    } else {
        return null;
    }
}

function doIHaveTheBMLTChecker($results) {
    return round($results[0]['raw_data']->distance_in_miles) < 100;
}

function sendMeetingResults($coordinates, $results) {
    if ($coordinates->latitude !== null && $coordinates->longitude !== null) {
        $map_payload = [];
        for ($i = 0; $i < count($results); $i++) {
            sendMessage($results[$i]['message'],
                $coordinates,
                count($results));

            array_push($map_payload, [
                "latitude" => $results[$i]['latitude'],
                "longitude" => $results[$i]['longitude'],
                "distance" => $results[$i]['distance'],
                "raw_data" => $results[$i]['raw_data']
            ]);
        }

        $map_page_url = "https://"
            . $_SERVER['HTTP_HOST'] . "/"
            . str_replace("process", "map", $_SERVER['PHP_SELF'])
            . "?Data=" . base64_encode(json_encode($map_payload))
            . "&Latitude=" . $coordinates->latitude
            . "&Longitude=" . $coordinates->longitude;

        sendButton('Follow-up Actions', 'Results Map', $map_page_url, $coordinates, count($results));

        if (!doIHaveTheBMLTChecker($results)) {
            sendMessage("Your community may not be covered by the BMLT yet.  https://www.doihavethebmlt.org/?latitude=" . $coordinates->latitude . "&longitude=" . $coordinates->longitude);
        }
    } else {
        sendMessage("Location not recognized.  I only recognize City, County or Postal Code.");
    }
}

function sendMessage($message, $coordinates = null, $results_count = 0  ) {
    $quick_replies_payload = quickReplies( $coordinates, $results_count );

    sendBotResponse([
        'recipient' => ['id' => $GLOBALS['senderId']],
        'messaging_type' => 'RESPONSE',
        'message' => [
            'text' => $message,
            'quick_replies' => $quick_replies_payload
        ]
    ]);
}

function sendButton($title, $button_title, $link, $coordinates = null, $results_count = 0  ) {
    $quick_replies_payload = quickReplies( $coordinates, $results_count );

    sendBotResponse([
        'recipient' => ['id' => $GLOBALS['senderId']],
        'messaging_type' => 'RESPONSE',
        'message' => [
            'attachment' => [
                'type' => 'template',
                'payload' => [
                    'template_type' => 'button',
                    'text' => $title,
                    'buttons' => array([
                        'type' => 'web_url',
                        'url' => $link,
                        'title' => $button_title
                    ])
                ]
            ],
            'quick_replies' => $quick_replies_payload
        ]
    ]);
}

function quickReplies( $coordinates, $results_count ) {
    $quick_replies_payload = array();

    if ( isset( $coordinates ) ) {
        array_push( $quick_replies_payload,
            [
                'content_type' => 'text',
                'title'        => '📞 Helpline',
                'payload'      => json_encode( [
                    'coordinates' => $coordinates
                ] )
            ] );
    }

    if ( $results_count > 0 ) {
        array_push( $quick_replies_payload,
            [
                'content_type' => 'text',
                'title'        => 'More Results',
                'payload'      => json_encode( [
                    'results_start' => $results_count + 1,
                    'coordinates'   => $coordinates
                ] )
            ] );
    }

    return $quick_replies_payload;
}

function sendBotResponse($payload) {
    post('https://graph.facebook.com/v5.0/me/messages?access_token=' . $GLOBALS['fbmessenger_accesstoken'], $payload);
}
