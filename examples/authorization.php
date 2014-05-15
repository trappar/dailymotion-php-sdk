<?php

// Require the composer autoloader
require_once(__DIR__ . '/../../../autoload.php');

session_start();

use Dailymotion\Dailymotion;

// Quick way of getting the current url
$redirect = filter_var('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'], FILTER_SANITIZE_URL);

// Create and set up a new Dailymotion object
$dailymotion = new Dailymotion();
$dailymotion->setClientId('my_client_id');
$dailymotion->setClientSecret('my_client_secret');
$dailymotion->setRedirectUri($redirect);
$dailymotion->setScopes('manage_videos');

/*
 * We are going to use a session variable to store the access token when we get it, but when we first
 * hit this section of code it won't be set.
*/
if (!isset($_SESSION['dailymotion_access_token'])) {
    /*
     * If $_GET['error'] == 'access_denied' then the user clicked cancel on the authorization form
     */
    if (isset($_GET['error']) && $_GET['error'] == 'access_denied') {
        die ('The user clicked cancel on the authorization form!');
    }

    /*
     * At this point we still don't have an access token but we don't know if the user has been sent
     * to Dailymotion yet. We can check for $_GET['code'] to determine that.
     */
    if (!isset($_GET['code'])) {
        /*
         * Make some random information to be sent as a "state", we can check this when the request is
         * returned to us to make sure nothing happened to the request in between now and then.
         */
        $state             = uniqid();
        $_SESSION['state'] = $state;

        // Transfer user to dailymotion
        header('Location: ' . $dailymotion->buildAuthorizationEndpoint($state));
        exit;
    } else {
        if ($_GET['state'] != $_SESSION['state']) {
            die ("We weren't given back the proper state, this is a potential security breach.");
        }

        try {
            // Trade the authorization code for an access token
            $response = $dailymotion->authorize($_GET['code']);

            // We assume since no AuthException was thrown that we now have an access token
            $_SESSION['dailymotion_access_token'] = $dailymotion->getAccessToken();
        } catch (Exception $e) {
            die ('There was a problem authorizing: ' . $e->getMessage());
        }

        // This is just here to clear out the get parameters
        header('Location: ' . $redirect);
        exit;
    }
}

echo 'We now have an access token, here it is: ' . $_SESSION['dailymotion_access_token'];