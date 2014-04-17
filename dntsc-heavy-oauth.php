<?php
/*

dntsc-heavy-oauth.php
    Handle services that use "heavyweight" (signed) OAuth.

Copyright 2013-2014 Mark Montague, mark@catseye.org

This file is part of DNT Social Commenting.

DNT Social Commenting is free software: you can redistribute it and/or
modify it under the terms of the GNU General Public License as published
by the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

DNT Social Commenting is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the 
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with DNT Social Commenting.  If not, see
<http://www.gnu.org/licenses/>.

*/


require_once dirname(__FILE__) . '/oauth/OAuth.php';


function dntsc_oauth_get_request_token( $service )
{

    global $dntsc_options;

    dntsc_debug( "obtaining request token for {$service}" );

    if ( empty( $dntsc_options['service'][$service]['enabled'] )
        || ! $dntsc_options['service'][$service]['enabled'] ) {
        dntsc_error( 'unable to get request token: unknown service' );
        $_SESSION['dntsc'] = array();
        wp_die( 'Could not determine which service to use to sign in.  Please try a different sign-in button or try again later.',
            '', array( 'response' => 200, 'back_link' => true ) );
    }


    $url = $redirect_url = '';
    switch ( $service ) {

        case 'twitter':
            $url = 'https://api.twitter.com/oauth/request_token';
            //$redirect_url = 'https://api.twitter.com/oauth/authorize';
            $redirect_url = 'https://api.twitter.com/oauth/authenticate';
            break;

        default:
            dntsc_error( 'unable to get request token: unknown service' );
            $_SESSION['dntsc'] = array();
            wp_die( 'Could not determine which service to use to sign in.  Please try a different sign-in button or try again later.',
                '', array( 'response' => 200, 'back_link' => true ) );
            break;

    }

    $consumer = new OAuthConsumer( $dntsc_options['service'][$service]['id'],
        $dntsc_options['service'][$service]['secret'] );
    $sign_method = new OAuthSignatureMethod_HMAC_SHA1();

    $params = array();
    $params['oauth_callback'] =  home_url(
        '/' . $dntsc_options['callback'] . '/?step=authenticatedheavy' );

    $request = OAuthRequest::from_consumer_and_token( $consumer,
        NULL, 'POST', $url, $params );
    $request->sign_request( $sign_method, $consumer, NULL );
    $body = $request->to_postdata();

    dntsc_debug( "posting to {$url} with body " . print_r( $body, TRUE ) );
    $response = wp_remote_post( $url,
        array( 'sslverify' => TRUE, 'method' => 'POST', 'body' => $body ) );

    if ( is_wp_error( $response ) ) {
        dntsc_error( 'unable to obtain request token: '
            . $response->get_error_message() );
        $_SESSION['dntsc'] = array();
        wp_die( 'Something went wrong talking to the service you are trying to sign in to.  Please try a different sign-in button or try again later.',
            '', array( 'response' => 200, 'back_link' => true ) );
    }

    if ( wp_remote_retrieve_response_code( $response ) != 200 ) {
        dntsc_error( 'unable to obtain request token: got HTTP response code '
            . wp_remote_retrieve_response_code( $response ) );
        $_SESSION['dntsc'] = array();
        wp_die( 'Something went wrong talking to the service you are trying to sign in to.  Please try a different sign-in button or try again later.',
            '', array( 'response' => 200, 'back_link' => true ) );
    }

    dntsc_debug( 'got request token response: ' .  print_r( $response, TRUE ) );

    $request_token = OAuthUtil::parse_parameters( wp_remote_retrieve_body(
        $response ) );
    dntsc_debug( 'got request token: ' .  print_r( $request_token, TRUE ) );

    $token = $request_token['oauth_token'];
    $_SESSION['dntsc']['oauth_token'] = $token;
    $_SESSION['dntsc']['oauth_token_secret'] =
        $request_token['oauth_token_secret'];

    $redirect_url .= '?oauth_token=' . $token;

    dntsc_debug( "redirect url is {$redirect_url}" );
    return $redirect_url;

}



add_action( 'dntsc_state_authenticatedheavy', 'dntsc_heavy_authenticated' );
function dntsc_heavy_authenticated() {

    global $dntsc_options;

    dntsc_debug( "got post-authentication callback from heavyweight oauth sign-in service\n"
        . "  $_REQUEST data: " . print_r( $_REQUEST, TRUE ) . "\n"
        . "  $_SESSION data: " . print_r( $_SESSION, TRUE ) );

    unset( $_SESSION['dntsc']['nonce'] );  // we don't use this

    $service = dntsc_get_service();
    if ( empty( $_REQUEST['oauth_token'] )
        || empty( $_REQUEST['oauth_verifier'] )
        || empty( $_SESSION['dntsc']['oauth_token'] )
        || ! $service
        || $_SESSION['dntsc']['oauth_token'] !== $_REQUEST['oauth_token'] ) {
        // TODO: make sure the token and verifier contain only the characters we are expecting
        // TODO: re-start the auth, preserving the URL the user was orignally trying to get to
        dntsc_error( 'bad session, dying' );
        $_SESSION['dntsc'] = array();
        wp_die( 'Bad session, please try again or try a differernt sign-in button.',
            '', array( 'response' => 200 ) );
    }

    $url = '';
    switch ( $service ) {

        case 'twitter':
            $url = 'https://api.twitter.com/oauth/access_token';
            break;

        default:
            dntsc_error( 'unable to get access token: unknown service' );
            $_SESSION['dntsc'] = array();
            wp_die( 'Could not determine which service was used to sign in.  Please try a different sign-in button or try again later.',
            '', array( 'response' => 200 ) );
            break;

    }

    // Use the temporary token to obtain an access token:

    $consumer = new OAuthConsumer( $dntsc_options['service'][$service]['id'],
        $dntsc_options['service'][$service]['secret'] );
    $sign_method = new OAuthSignatureMethod_HMAC_SHA1();
    $token = new OAuthConsumer( $_SESSION['dntsc']['oauth_token'],
        $_SESSION['dntsc']['$oauth_token_secret'] );

    $params = array();
    $params['oauth_verifier'] = $_REQUEST['oauth_verifier'];

    $request = OAuthRequest::from_consumer_and_token( $consumer,
        $token, 'POST', $url, $params );
    $request->sign_request( $sign_method, $consumer, $token );
    $body = $request->to_postdata();

    dntsc_debug( "posting to {$url} with body " . print_r( $body, TRUE ) );

    $response = wp_remote_post( $url,
        array( 'sslverify' => TRUE, 'method' => 'POST', 'body' => $body ) );

    if ( is_wp_error( $response ) ) {
        dntsc_error( 'unable to obtain access_token: '
            . $response->get_error_message() );
        $_SESSION['dntsc'] = array();
        wp_die( 'Something went wrong talking to the service that signed you in.  Please try a different sign-in button or try again later.',
            '', array( 'response' => 200 ) );
    }

    dntsc_debug( 'got access_token response: ' .  print_r( $response, TRUE ) );

    if ( wp_remote_retrieve_response_code( $response ) != 200 ) {
        dntsc_error( 'unable to obtain access_token: got HTTP response code '
            . wp_remote_retrieve_response_code( $response ) );
        $_SESSION['dntsc'] = array();
        wp_die( 'Something went wrong talking to the service that signed you in.  Please try a different sign-in button or try again later.',
            '', array( 'response' => 200 ) );
    }

    $access_token = OAuthUtil::parse_parameters( wp_remote_retrieve_body(
        $response ) );
    dntsc_debug( 'got access token: ' .  print_r( $access_token, TRUE ) );

    $_SESSION['dntsc']['access_token'] = $access_token;
    unset( $_SESSION['dntsc']['oauth_token'] );
    unset( $_SESSION['dntsc']['oauth_token_secret'] );

    if ( isset( $_SESSION['dntsc']['post_id'] ) ) {
        $url = get_permalink( $_SESSION['dntsc']['post_id'] );
        if ( $url ) {
            $url .= '#respond';
        } else {
            $url = home_url();
        }
        unset( $_SESSION['dntsc']['post_id'] );
    } else {
        $url = home_url();
    }

    // Before considering the user authenticated, make sure we can get
    // all of the user information we need -- if we can't get it, this is
    // our last chance to end cleanly with an error and not render a partial
    // page.
    
    $userinfo = dntsc_get_userinfo();
    if ( $userinfo === FALSE ) {
        dntsc_error( "Couldn't get all necessary user information, dying." );
        $_SESSION['dntsc'] = array();
        wp_die( "The service you successfully logged in with didn't provide all of the information needed to leave comments (full name, email address, URL).  Please use a different sign-in button or try again later.</p><p><a href=\"$url\">&laquo; Back</a>",
            '', array( 'response' => 200 ) );
    }

    dntsc_debug( "redirecting to {$url}" );
    session_write_close();
    wp_redirect( $url );
    exit( 0 );

}


function dntsc_heavy_get_userinfo()
{

    global $dntsc_options;

    $service = dntsc_get_service();

    $url = '';
    switch ( $service ) {

        case 'twitter':
            $url = 'https://api.twitter.com/1.1/account/verify_credentials.json';
            break;

        default:
            dntsc_error( 'unable to get user information: unknown service' );
            $_SESSION['dntsc'] = array();
            return FALSE;
            break;

    }

    $access_token = $_SESSION['dntsc']['access_token'];

    $consumer = new OAuthConsumer( $dntsc_options['service'][$service]['id'],
        $dntsc_options['service'][$service]['secret'] );
    $sign_method = new OAuthSignatureMethod_HMAC_SHA1();
    $token = new OAuthConsumer(
        $_SESSION['dntsc']['access_token']['oauth_token'],
        $_SESSION['dntsc']['access_token']['oauth_token_secret'] );
    $params = array();

    $request = OAuthRequest::from_consumer_and_token( $consumer,
        $token, 'GET', $url, $params );
    $request->sign_request( $sign_method, $consumer, $token );
    $url = $request->to_url();

    dntsc_debug( "retrieving {$url}" );

    $response = wp_remote_get( $url,
        array( 'sslverify' => TRUE, 'method' => 'GET' ) );

    if ( is_wp_error( $response ) ) {
        dntsc_error( 'unable to obtain user information: '
            . $response->get_error_message() );
        $_SESSION['dntsc'] = array();
        return FALSE;
    }

    if ( wp_remote_retrieve_response_code( $response ) != 200 ) {
        dntsc_error( 'unable to obtain user information: got HTTP response code '
            . wp_remote_retrieve_response_code( $response ) );
        $_SESSION['dntsc'] = array();
        return FALSE;
    }

    dntsc_debug( 'got user information response: ' .  print_r( $response, TRUE ) );

    $userinfo = json_decode( wp_remote_retrieve_body( $response ), TRUE );
    return $userinfo;

}


?>
