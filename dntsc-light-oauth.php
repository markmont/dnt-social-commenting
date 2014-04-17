<?php
/*

dntsc-light-oauth.php
    Handle services that use "lightweight" OAuth.

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


add_action( 'dntsc_state_authenticatedlight', 'dntsc_light_authenticated' );
function dntsc_light_authenticated() {

    global $dntsc_options;

    dntsc_debug( "got post-authentication callback from sign-in service\n"
        . "  $_REQUEST data: " . print_r( $_REQUEST, TRUE ) . "\n"
        . "  $_SESSION data: " . print_r( $_SESSION, TRUE ) );

    if ( isset( $_REQUEST['error'] ) ) {  // Google and Facebook
        dntsc_error( 'sign-in service returned an authentication error: '
            . $_REQUEST['error'] );
        $_SESSION['dntsc'] = array();
        wp_die( 'The service you are attempting to sign in with returned an error, please try another service: ' . esc_html( $_REQUEST['error'] ), '',
            array( 'response' => 200 ) );
    }

    $service = dntsc_get_service();
    if ( empty( $_REQUEST['code'] )
        || empty( $_REQUEST['state'] )
        || empty( $_SESSION['dntsc']['nonce'] )
        || ! $service ) {
        // TODO: make sure code contains only the characters we are expecting, although if the nonce/state matches, we presume that the value for code came from github and hence can be trusted
        // TODO: re-start the auth, preserving the URL the user was orignally trying to get to
        dntsc_error( 'bad session, dying' );
        $_SESSION['dntsc'] = array();
        wp_die( 'Bad session, please try again or try a differernt sign-in button.',
            '', array( 'response' => 200 ) );
    }


    $nonce = '';
    if ( $service != 'google' ) {
        $nonce = $_REQUEST['state'];
    } else if ( filter_var( $_REQUEST['state'], FILTER_VALIDATE_REGEXP,
        array( 'options' => array( 'regexp' => '/^[\w_=&-]+$/' ) ) ) ) {
        parse_str( $_REQUEST['state'], $state );
        dntsc_debug( 'got google state: ' . print_r( $state, TRUE ) );
        if ( ! empty( $state['state'] ) ) {
            $nonce = $state['state'];
        }
    }
    if ( $_SESSION['dntsc']['nonce'] !== $nonce ) {
        dntsc_error( 'incorrect nonce, dying' );
        $_SESSION['dntsc'] = array();
        wp_die( 'Bad session, please try again or try a different sign-in button.',
            '', array( 'response' => 200 ) );
    }
    unset( $_SESSION['dntsc']['nonce'] );


    // Use the temporary code to obtain an access token:

    switch ( $service ) {

        case 'github':
            $url = 'https://github.com/login/oauth/access_token';
	    $body = array(
                'client_id' => $dntsc_options['service']['github']['id'],
                'client_secret' => $dntsc_options['service']['github']['secret'],
                'code' => $_REQUEST['code']
                );
            break;

        case 'google':
            $url = 'https://accounts.google.com/o/oauth2/token';
	    $body = array(
                'code' => $_REQUEST['code'],
                'client_id' => $dntsc_options['service']['google']['id'],
                'client_secret' => $dntsc_options['service']['google']['secret'],
                'redirect_uri' => home_url( '/' .
                    $dntsc_options['callback'] . '/' ),
                'grant_type' => 'authorization_code'
                );
            break;

        case 'facebook':
            $url = 'https://graph.facebook.com/oauth/access_token';
            $url .= '?client_id=' .
                urlencode( $dntsc_options['service']['facebook']['id'] );
            $url .= '&client_secret=' .
                urlencode( $dntsc_options['service']['facebook']['secret'] );
            $url .= '&code=' .  urlencode( $_REQUEST['code'] );
            $url .= '&redirect_uri=' .
                urlencode( home_url( '/' . $dntsc_options['callback'] .
                    '/?step=authenticatedlight' ) );
            break;

    }

    if ( $service == 'facebook' ) {
        dntsc_debug( "retrieving {$url}" );
        $response = wp_remote_get( $url,
            array( 'sslverify' => TRUE, 'method' => 'GET' ) );
    } else {
        dntsc_debug( "posting to {$url} with body " . print_r( $body, TRUE ) );
        $response = wp_remote_post( $url,
            array( 'sslverify' => TRUE, 'method' => 'POST', 'body' => $body ) );
    }

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

    $expires = 0;
    switch ( $service ) {
        case 'github':
            parse_str( wp_remote_retrieve_body( $response ), $data );
            break;
        case 'google':
            $data = json_decode( wp_remote_retrieve_body( $response ), TRUE );
            if ( ! empty( $data['expires_in'] ) ) {
                $expires = 0 + (int) $data['expires_in'];
            }
            break;
        case 'facebook':
            parse_str( wp_remote_retrieve_body( $response ), $data );
            if ( ! empty( $data['expires'] ) ) {
                $expires = 0 + (int) $data['expires'];
            }
            break;
    }

    if ( empty( $data['access_token'] ) ) {
        dntsc_error( 'empty access_token' );
        $_SESSION['dntsc'] = array();
        wp_die( 'Something went wrong talking to the service that signed you in.  Please try a different sign-in button or try again later.',
            '', array( 'response' => 200 ) );
    }
    $_SESSION['dntsc']['access_token'] = $data['access_token'];
    dntsc_debug( 'got access_token: ' .  $_SESSION['dntsc']['access_token'] );

    if ( $expires > 0 ) {
        $_SESSION['dntsc']['expiry'] = time() + $expires;
    }

    // For Facebook only, as of 2013-08-13:
    // While https://developers.facebook.com/docs/facebook-login/login-flow-for-web-no-jssdk/
    // says to check the access token, https://developers.facebook.com/docs/facebook-login/access-tokens/
    // does not say it is required.  Since the access token is in response
    // to our GET, the only ways it could be for a different app is if the
    // HTTPS channel is compromised or Facebook returned the wrong app.
    // And we can't validate the user's Facebook, since we did not ask the
    // user who they are.  So there is little point in makeing a call to
    // https://graph.facebook.com/debug_token to inspect the access token.

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


function dntsc_light_get_userinfo()
{

    $service = dntsc_get_service();
    $access_token = $_SESSION['dntsc']['access_token'];

    switch ( $service ) {

        case 'github':
            $url = 'https://api.github.com/user?access_token='
                . $access_token;
            break;

        case 'google':
            $url = 'https://www.googleapis.com/oauth2/v1/userinfo?access_token='
                . $access_token;
            break;

        case 'facebook':
            $url = 'https://graph.facebook.com/me?fields=id,name,email,link,picture&access_token='
                . $access_token;
            break;

        default:
            return FALSE;
            break;

    }

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

    dntsc_debug( 'got user information response: ' . print_r( $response, TRUE ) );

    $userinfo = json_decode( wp_remote_retrieve_body( $response ), TRUE );
    return $userinfo;

}


?>
