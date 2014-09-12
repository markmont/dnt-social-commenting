<?php
/*
Plugin Name: DNT Social Commenting
Description: Allows users to authenticate via OAuth to social networking sites in order to leave WordPress comments.  Does not require the user to register or log in to WordPress.  Attempts to prevent WordPress readers from being tracked by the social networking providers.  Currently supported social networking providers are GitHub, Google, Twitter, and Facebook.
Version: 1.0.0
Author: Mark Montague
Author URI: http://mark.catseye.org/
License: GPL3
*/

/*

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

/*

Resources for how to authenticate via Google:

    https://developers.google.com/accounts/docs/OAuth2
    https://developers.google.com/accounts/docs/OAuth2Login
    https://developers.google.com/accounts/docs/OAuth2WebServer

Resources for how to authenticate via GitHub:

    http://developer.github.com/v3/oauth/

Resources for how to authenticate via Twitter:

    https://dev.twitter.com/docs/auth/implementing-sign-twitter
    http://oauth.googlecode.com/
    https://code.google.com/p/oauth/source/browse/#svn%2Fcode%2Fphp

Resources for how to authenticate via Facebook:

    https://developers.facebook.com/docs/facebook-login/login-flow-for-web-no-jssdk/
    https://developers.facebook.com/docs/reference/api/user/

*/


global $dntsc_db_version;
$dntsc_db_version = 1;

include_once( 'dntsc-require-signin.php' );
include_once( 'dntsc-light-oauth.php' );
include_once( 'dntsc-heavy-oauth.php' );
include_once( 'dntsc-avatar.php' );
include_once( 'dntsc-options.php' );


function base64url_encode( $data ) {
    return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}


/*  // not currently used
function base64url_decode( $data ) {
    return base64_decode( str_pad( strtr( $data, '-_', '+/' ),
        strlen( $data ) % 4, '=', STR_PAD_RIGHT ) );
} 
*/


function dntsc_message( $message ) {

    global $dntsc_options;
    global $dntsc_session;
    global $dntsc_service_name;

    // Sanitize secrets so they do not appear in the logs:
    foreach ( $dntsc_service_name as $service => $service_name ) {
        $secret = $dntsc_options['service'][$service]['secret'];
        if ( ! empty( $secret ) ) {
            $message = preg_replace( "/{$secret}/", "XXX{$service}-secretXXX",
                $message );
        }
    }

    $info = "DNTSC ";
    if ( ! empty( $dntsc_session['service'] ) ) {
        $info .= 'service=' . $dntsc_session['service'] . ' ';
    }
    if ( ! empty( $dntsc_session['userinfo']['dntsc_email'] ) ) {
        $info .= 'email=' . $dntsc_session['userinfo']['dntsc_email'] . ' ';
    }

    error_log( $info . $message );

}


function dntsc_debug( $message ) {
    global $dntsc_options;
    if ( $dntsc_options['debug'] ) { dntsc_message( $message ); }
}


function dntsc_error( $message ) {
    dntsc_message( "ERROR: " . $message );
}


function dntsc_destroy_session() {

    global $dntsc_session;

    if ( empty( $dntsc_session['id'] ) ) {
        dntsc_debug( "can't destroy session without session id" );
        $dntsc_session = array(); // nuke the in-memory data, at least
        return;
    }
    $t = 'dntsc' . $dntsc_session['id'];

    dntsc_debug( "destroying session: " . $dntsc_session['id'] );
    $ok = delete_transient( $t );
    if ( ! $ok ) {
        dntsc_error( "failed to delete transient " . $t );
    }

    $dntsc_session = array();

    // We can't unset the session cookie here if we've already started to
    // render the page.  The cookie will get unset by dntsc_init() the
    // next time a page is loaded.

}


function dntsc_save_session() {

    global $dntsc_session;

    if ( empty( $dntsc_session['id'] ) ) {
        dntsc_debug( "can't save session without session id" );
        return;
    }
    $t = 'dntsc' . $dntsc_session['id'];

    // If the session is already expired, delete it instead of saving it
    $now = time();
    if ( empty( $dntsc_session['expires'] )
        || $now >= $dntsc_session['expires'] ) {
        dntsc_destroy_session();
        return;
    }

    dntsc_debug( "saving session: " . print_r( $dntsc_session, TRUE ) );
    $ok = set_transient( $t, $dntsc_session, $dntsc_session['expires'] - $now );
    if ( ! $ok ) {
        dntsc_error( "failed to set transient " . $t );
    }

}


function dntsc_get_service() {

    global $dntsc_options;
    global $dntsc_session;

    if ( empty( $dntsc_session['service'] ) ) { return NULL; }
    $service = $dntsc_session['service'];

    // Make sure the service name is valid
    if ( ! empty( $dntsc_options['service'][$service]['enabled'] )
        && $dntsc_options['service'][$service]['enabled'] != 0 ) {
        return $service;
    }

    dntsc_error( "session contains bad value for service " );
    dntsc_destroy_session();
    return NULL;

}


function dntsc_activate() {

    global $wpdb;
    global $dntsc_db_version;

    $table_name = $wpdb->prefix . "dntsc_avatar"; 
    $sql = "CREATE TABLE $table_name (
        email varchar(255) NOT NULL,
        service_author_url varchar(255) NOT NULL,
        service_avatar_url varchar(255) NOT NULL,
        local_avatar_id varchar(100) NOT NULL,
        updated timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (service_author_url),
        KEY email (email),
        UNIQUE KEY local_avatar_id (local_avatar_id)
        );";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
    add_option( "dntsc_db_version", $dntsc_db_version );

    flush_rewrite_rules( false );

}
register_activation_hook( __FILE__, 'dntsc_activate' );


add_action( 'wp_enqueue_scripts', 'dntsc_jquery' );
function dntsc_jquery() {
    wp_enqueue_script( 'jquery' );
    wp_register_style( 'dntsc-style', plugins_url('css3-social-signin-buttons/auth-buttons.css', __FILE__) );
    wp_enqueue_style( 'dntsc-style' );
}


// Provide a place for multiple comment authentication modules (Simple Google
// Connect, Simple Facebook Connect) to add their login buttons.  Our own
// login button also goes here.

if ( ! function_exists( 'alt_login_method_div' ) ) {
    add_action( 'alt_comment_login','alt_login_method_div', 5, 0 );
    add_action( 'comment_form_before_fields', 'alt_login_method_div', 5, 0 ); // WP 3.0
    function alt_login_method_div() { echo '<div id="alt-login-methods">'; }
    add_action( 'alt_comment_login', 'alt_login_method_div_close', 20, 0 );
    add_action( 'comment_form_before_fields', 'alt_login_method_div_close', 20, 0); // WP 3.0
    function alt_login_method_div_close() { echo '</div><!-- alt-login-methods -->'; }
}

if ( ! function_exists( 'comment_user_details_begin' ) ) { // WP 3.0
    add_action( 'comment_form_before_fields', 'comment_user_details_begin', 1, 0);
    function comment_user_details_begin() { echo '<div id="comment-user-details">'; }
    add_action('comment_form_after_fields', 'comment_user_details_end', 20, 0 );
    function comment_user_details_end() { echo '</div><!-- comment-user-details -->'; }
}


// Buttons using http://nicolasgallagher.com/lab/css3-social-signin-buttons/
// actually pulled from fork that contains several enhancements and fixes
// (as of 2013-03-02): https://github.com/joshtronic/css3-social-signin-buttons

add_action( 'alt_comment_login', 'dntsc_add_button' );
add_action( 'comment_form_before_fields', 'dntsc_add_button', 10, 0 );
function dntsc_add_button() {
    global $wp_query;
    global $dntsc_options;
    global $dntsc_service_name;
    $post_id = $wp_query->post->ID;
    $i = 1;
    echo '<table><tr>';
    foreach ( $dntsc_service_name as $service => $service_name ) {
        if ( ! $dntsc_options['service'][$service]['enabled'] ) {
            continue;
        }
        $url = home_url( '/' . $dntsc_options['callback'] .
            "/?service={$service}&step=redirect&id={$post_id}" );
        echo '<td>';
        echo "<a class=\"btn-auth btn-{$service}\" href=\"{$url}\">";
        echo "Sign in with <b>${service_name}</b></a>";
        echo '</td>';
        if ( $i % 2 == 0 ) { echo '</tr><tr>'; }
        $i++;
    }
    echo '</tr></table>';
}


add_action( 'init', 'dntsc_init' );
function dntsc_init() {

    global $wp;
    global $dntsc_options;
    global $dntsc_session;

    $dntsc_session = array();

    $options = get_option( 'dntsc_options' );
    if ( $options !== FALSE ) {
       $dntsc_options = array_merge( $dntsc_options, $options );
       dntsc_debug( 'options: ' . print_r( $dntsc_options, TRUE ) );
    }

    if ( ! empty( $_COOKIE['dntsc'] ) ) {
        $id = $_COOKIE['dntsc'];
        $cookie_ok = 1;
        $now = time();
        if ( filter_var( $id, FILTER_VALIDATE_REGEXP,
            array( 'options' => array( 'regexp' => '/^[0-9a-f]{32}$/' ) ) ) ) {
            $data = get_transient( 'dntsc' . $id );
            if ( $data !== FALSE ) {
                $dntsc_session = $data;
                dntsc_debug( "session: " . print_r( $dntsc_session, TRUE ) );
                if ( empty( $dntsc_session['expires'] )
                    || $now >= $dntsc_session['expires'] ) {
                    dntsc_destroy_session();
                    $cookie_ok = 0;
                }
            } else {
                dntsc_error( 'could not retrieve transient dntsc' . $id );
                $cookie_ok = 0;
            }
        } else {
            dntsc_error( 'bad cookie value' );
            $cookie_ok = 0;
        }
        if ( ! $cookie_ok ) {
            // Unset the cookie, unless we're going to set it to a new value
            if ( empty( $_REQUEST['step'] ) || $_REQUEST['step'] != 'redirect' ) {
                // TODO: also check to see that we're at the callback URL path
                setcookie( 'dntsc', '', time() - 365*86400, COOKIEPATH,
                    COOKIE_DOMAIN, is_ssl(), TRUE );
            }
        }
    }

    $wp->add_query_var( $dntsc_options['callback'] );
    add_rewrite_rule( '^' . $dntsc_options['callback'] . '/?$',
        'index.php?' . $dntsc_options['callback'] . '=1', 'top' );
    flush_rewrite_rules( false );

}


add_filter( 'wp_headers', 'dntsc_set_http_headers', 111, 1 );
function dntsc_set_http_headers( $headers ) {

    global $dntsc_session;

    // Do we want to indicate that this page should not be cached?
    $add_nocache = 0;
    if ( ! empty( $_REQUEST['step'] ) && $_REQUEST['step'] == 'redirect' ) {
        // TODO: verify callback URL path too
        $add_nocache = 1;
    }
    if ( ! empty( $dntsc_session['id'] ) ) {
        $add_nocache = 2;
    }

    // Would we be overriding something more restrictive?  If so, don't.
    $do_remove = 0;
    foreach ( headers_list() as $h ) {
        if ( stripos( $h, 'Cache-Control' ) === 0 ) {
            if ( !empty( $headers['Cache-Control'] ) ) {
                // If set in both places, $headers will win, so skip
                $do_remove = 1;
                continue;
            }
            if ( stripos( $h, 'no-cache' ) !== FALSE 
                || stripos( $h, 'private' ) !== FALSE 
                || stripos( $h, 'no-store' ) !== FALSE ) {
                $add_nocache = -1;
            }
        }
    }
    if ( $do_remove ) {
        @header_remove( 'Cache-Control' );
    }
    if ( !empty( $headers['Cache-Control'] ) &&
        ( stripos( $headers['Cache-Control'], 'no-cache' ) !== FALSE 
                || stripos( $headers['Cache-Control'], 'private' ) !== FALSE 
                || stripos( $headers['Cache-Control'], 'no-store' ) !== FALSE ) ) {
        $add_nocache = -2;
    }

    if ( $add_nocache > 0 ) {
        $headers['Cache-Control'] = 'no-cache, must-revalidate, max-age=0';
        dntsc_debug( "indicating page must not be cached (reason {$add_nocache})" );
    } else {
        dntsc_debug( "passing on page caching decision (reason {$add_nocache})" );
    }
    
    return $headers;

}


add_action( 'template_redirect', 'dntsc_callback_catcher' );
function dntsc_callback_catcher() {

    global $dntsc_options;

    if ( get_query_var( $dntsc_options['callback'] ) == 1 ) {

        dntsc_debug( 'got callback request: ' . print_r( $_REQUEST, TRUE ) );

        if ( ! empty( $_REQUEST['step'] ) ) {
            if ( filter_var( $_REQUEST['step'], FILTER_VALIDATE_REGEXP,
                array( 'options' => array( 'regexp' => '/^[a-z]{1,32}$/' ) ) ) ) {
                do_action( 'dntsc_state_' . $_REQUEST['step'] );
            }
        }

        // Google only:
        if ( ! empty( $_REQUEST['state'] ) ) {
            if ( filter_var( $_REQUEST['state'], FILTER_VALIDATE_REGEXP,
                array( 'options' => array( 'regexp' => '/^[\w_=&-]+$/' ) ) ) ) {

                parse_str( $_REQUEST['state'], $state );
                dntsc_debug( 'google state=' . print_r( $state, TRUE ) );
                if ( ! empty( $state['step'] ) ) {
                    if ( filter_var( $state['step'], FILTER_VALIDATE_REGEXP,
                        array( 'options' => array( 'regexp' => '/^[a-z]{1,32}$/' ) ) ) ) {
                        do_action( 'dntsc_state_' . $state['step'] );
                    }
                }
            }
        }

        // if we made it here, then the action didn't do anything so
        // redirect to the home page
        wp_redirect( home_url() );
        exit( 0 );
    }

}


add_action( 'dntsc_state_profile', 'dntsc_profile_redirect' );
function dntsc_profile_redirect() {

    global $wpdb;

    // If we linked directly to a user's social media profile and
    // a reader clicked on it, the social media site would know from
    // the referrer which article the reader had read.  So we link
    // to our local avatar id instead, and then serve a page that does
    // a refresh to the social media site, eliminating the referrer.

    // TODO: consider replacing this with serving up javascript targets
    // for the URLs.  Upside is simpler code; downside is that our current
    // scheme is more reliable.

    // TODO: linking to our local avatar id and then redirecting is
    // a lot of work compared to using the HTML a element ref="noreferer"
    // attribute, although browser support still appears to be poor at
    // the end of 2013.

    if ( empty( $_REQUEST['id'] ) ) {
        dntsc_debug( 'profile id not set' );
        wp_redirect( home_url() );
        exit( 0 );
    }
    $local_avatar_id = $_REQUEST['id'];

    if ( ! filter_var( $local_avatar_id, FILTER_VALIDATE_REGEXP,
        array( 'options' => array( 'regexp' => '/^[0-9a-f]{1,64}$/' ) ) ) ) {
        dntsc_debug( 'bad profile id' );
        wp_redirect( home_url() );
        exit( 0 );
    }

    $avatar_table = $wpdb->prefix . 'dntsc_avatar';
    $url = $wpdb->get_var( $wpdb->prepare( "
        SELECT service_author_url FROM $avatar_table
        WHERE local_avatar_id = %s", $local_avatar_id ) );
    if ( $url == NULL ) {
        dntsc_debug( "no URL found for local avatar id {$local_avatar_id}" );
        wp_redirect( home_url() );
        exit( 0 );
    }

    // TODO: add a full page with:
    //   a message and clickable link for $url
    //   <script type="text/javascript" language="JavaScript">location.href = '$url';</script> 
    //   <meta http-equiv='refresh' content='0;$url' />

    dntsc_debug( "profile: redirecting local avatar id {$local_avatar_id} to ${url}" );
    dntsc_save_session();
    header( "Refresh: 0;url={$url}", true, 200 );
    exit( 0 );

}


add_action( 'dntsc_state_signout', 'dntsc_signout' );
function dntsc_signout() {

    $id = 0;
    if ( ! empty( $_REQUEST['id'] ) ) {
        $id = 0 + (int) $_REQUEST['id'];
    }

    $url = '';
    if ( $id > 0 ) {
        $url = get_permalink( $id );
    }
    if ( ! $url ) {
        $url = home_url();
    }

    dntsc_debug( "signing out, redirecting to {$url}" );
    setcookie( 'dntsc', '', time() - 365*86400, COOKIEPATH,
        COOKIE_DOMAIN, is_ssl(), TRUE );
    dntsc_destroy_session();
    wp_redirect( $url );
    exit( 0 );

}


add_action( 'dntsc_state_redirect', 'dntsc_redirect' );
function dntsc_redirect() {

    global $dntsc_options;
    global $dntsc_session;

    $dntsc_session = array(); // Forget all prior state
    $dntsc_session['id'] = bin2hex( openssl_random_pseudo_bytes( 16 ) );
    // TODO: make cookie name and expiration configurable options
    setcookie( 'dntsc', $dntsc_session['id'], 0, COOKIEPATH, COOKIE_DOMAIN,
        is_ssl(), TRUE );
    // We want the session to expire because having a session may prevent
    // cached copies of pages from being served to the user's browser.
    $dntsc_session['expires'] = time() + 24*60*60;

    if ( ! empty( $_REQUEST['id'] ) ) {
        $dntsc_session['post_id'] =  0 + (int) $_REQUEST['id'];
    }

    if ( empty( $_REQUEST['service'] ) ) {
        dntsc_error( 'sign-in-to-comment button did not specify service' );
        dntsc_destroy_session();
        wp_die( 'Could not determine which service to use to sign in.  Please try a different sign-in button or try again later.',
            '', array( 'response' => 200, 'back_link' => true ) );
    }

    $state = base64url_encode( openssl_random_pseudo_bytes( 32 ) );
    $dntsc_session['nonce'] = $state;

    $service = $_REQUEST['service'];
    switch ( $service ) {

        case 'github':
            $url = 'https://github.com/login/oauth/authorize';
            $url .= '?client_id=' . urlencode(
                $dntsc_options['service']['github']['id'] );
            $url .= '&redirect_uri=' . urlencode(
                home_url( '/' . $dntsc_options['callback'] .
                    '/?step=authenticatedlight' ) );
            $url .= '&scope=' . urlencode( 'user:email' );
            $url .= '&state=' . urlencode( $state );
            break;

        case 'google':
            $url = 'https://accounts.google.com/o/oauth2/auth';
            $url .= '?response_type=code';
            $url .= '&client_id=' . urlencode( $dntsc_options['service']['google']['id'] );
            $url .= '&redirect_uri=' . urlencode( home_url( '/' .
                $dntsc_options['callback'] . '/' ) );
            $url .= '&scope=' . urlencode( 'https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile' );
            $url .= '&state=' . urlencode( "state={$state}&step=authenticatedlight" );
            break;

        case 'twitter':
            $url = dntsc_oauth_get_request_token( $service );
            break;

        case 'facebook':
            $url = 'https://www.facebook.com/dialog/oauth';
            $url .= '?response_type=code';
            $url .= '&client_id=' . urlencode( $dntsc_options['service']['facebook']['id'] );
            $url .= '&redirect_uri=' . urlencode(
                home_url( '/' . $dntsc_options['callback'] .
                    '/?step=authenticatedlight' ) );
            $url .= '&scope=email';
            $url .= '&state=' . urlencode( $state );
            break;

        default:
            dntsc_error( 'unknown service set by sign-in-to-comment button' );
            dntsc_destroy_session();
            wp_die( 'Unknown sign-in service.  Please try a different sign-in button or try again later.',
                '', array( 'response' => 200, 'back_link' => true ) );
            break;

    }

    $dntsc_session['service'] = $service;

    dntsc_debug( "redirecting to {$url}" );
    dntsc_save_session();
    header( "Location: {$url}", true, 302 );
    exit( 0 );

}



// If the comment form is being displayed, remember this for later.
add_action( 'comment_form', 'dntsc_comments_enable' );
function dntsc_comments_enable() {
    global $dntsc_comments_form;
    $dntsc_comments_form = true;
}


function dntsc_get_facebook_avatar_url( $url ) {

    dntsc_debug( "retrieving Facebook picture information: {$url}" );
    $response = wp_remote_get( $url,
        array( 'sslverify' => TRUE, 'method' => 'GET' ) );

    if ( is_wp_error( $response ) ) {
        dntsc_error( 'unable to obtain Facebook picture information: '
            . $response->get_error_message() );
        return FALSE;
    }

    if ( wp_remote_retrieve_response_code( $response ) != 200 ) {
        dntsc_error( 'unable to obtain Facebook picture information: got HTTP response code '
            . wp_remote_retrieve_response_code( $response ) );
        return FALSE;
    }

    dntsc_debug( 'got Facebook picture information response: ' .
        print_r( $response, TRUE ) );
    $picture = json_decode( wp_remote_retrieve_body( $response ), TRUE );
    if ( empty( $picture['data']['url'] ) ) {
        return FALSE;
    }

    return $picture['data']['url'];

}


function dntsc_get_userinfo() {

    global $dntsc_session;

    if ( is_user_logged_in() ) { return FALSE; } // Do nothing for WP users

    if ( ! empty( $dntsc_session['userinfo']['dntsc_email'] ) ) {
        dntsc_debug( 'dntsc_get_userinfo: returning cached results: '
            . print_r( $dntsc_session['userinfo'], TRUE ) );
        return $dntsc_session['userinfo'];
    }

    if ( empty( $dntsc_session['access_token'] ) ) { return FALSE; }

    if ( ! empty( $dntsc_session['access_token_expires'] ) ) {
        if ( time() >= $dntsc_session['access_token_expires'] ) {
            dntsc_debug( 'access_token expired, clearing session' );
            dntsc_destroy_session();
            return FALSE;
        }
    }

    $service = dntsc_get_service();
    if ( ! $service ) { return FALSE; }

    if ( $service == 'twitter' ) {
        $userinfo = dntsc_heavy_get_userinfo();
    } else {
        $userinfo = dntsc_light_get_userinfo();
    }
    if ( $userinfo === FALSE ) { return FALSE; }

    switch ( $service ) {

        case 'github':
            if ( ! empty( $userinfo['email'] ) ) {
                $userinfo['dntsc_email'] = strtolower( trim ( $userinfo['email'] ) );
                // Generate our own avatar URL rather than trusting what GitHub
                // sends us. This way, we are guaranteed to get the size and
                // default we want.
                $userinfo['dntsc_avatar_url'] =
                    'https://secure.gravatar.com/avatar/' .
                    md5( $userinfo['dntsc_email'] ) . '?s=96&amp;d=mm';
                $rating = get_option( 'avatar_rating' );
                if ( ! empty( $rating ) ) {
                    $userinfo['dntsc_avatar_url'] .= "&amp;r={$rating}";
                }
            }
            if ( ! empty( $userinfo['name'] ) ) {
                $userinfo['dntsc_name'] = $userinfo['name'];
            }
            if ( ! empty( $userinfo['html_url'] ) ) {
                $userinfo['dntsc_url'] = $userinfo['html_url'];
            }        
            break;

        case 'google':
            if ( ! empty( $userinfo['email'] ) ) {
                $userinfo['dntsc_email'] = strtolower( trim ( $userinfo['email'] ) );
            }
            if ( ! empty( $userinfo['name'] ) ) {
                $userinfo['dntsc_name'] = $userinfo['name'];
            }
            if ( ! empty( $userinfo['link'] ) ) {
                $userinfo['dntsc_url'] = $userinfo['link'];
            }
            if ( ! empty( $userinfo['picture'] ) ) {
                $userinfo['dntsc_avatar_url'] = $userinfo['picture'];
            }
            break;

        case 'twitter':
            if ( ! empty( $userinfo['id'] ) ) {
                $userinfo['dntsc_email'] = strtolower( trim ( $userinfo['id'] ) ) . '@fake-email.twitter.com';
            }
            if ( ! empty( $userinfo['name'] ) ) {
                $userinfo['dntsc_name'] = $userinfo['name'];
            }
            if ( ! empty( $userinfo['screen_name'] ) ) {
                $userinfo['dntsc_url'] = 'http://twitter.com/' .
                    $userinfo['screen_name'];
            }
            if ( ! empty( $userinfo['profile_image_url_https'] ) ) {
                $userinfo['dntsc_avatar_url'] =
                    $userinfo['profile_image_url_https'];
            } else if ( ! empty( $userinfo['profile_image_url'] ) ) {
                $userinfo['dntsc_avatar_url'] = $userinfo['profile_image_url'];
            }
            if ( ! empty( $userinfo['dntsc_avatar_url'] ) ) {
                $userinfo['dntsc_avatar_url'] =
                    str_replace( '_normal.', '_bigger.',
                        $userinfo['dntsc_avatar_url'] );
                $userinfo['dntsc_avatar_url'] =
                    str_replace( '_mini.', '_bigger.',
                        $userinfo['dntsc_avatar_url'] );
            }
            break;

        case 'facebook':
            if ( ! empty( $userinfo['email'] ) ) {
                $userinfo['dntsc_email'] = strtolower( trim ( $userinfo['email'] ) );
            } else {
                if ( ! empty( $userinfo['id'] ) ) {
                    $userinfo['dntsc_email'] = strtolower( trim (
                        $userinfo['id'] ) ) . '@fake-email.facebook.com';
                }
            }
            if ( ! empty( $userinfo['name'] ) ) {
                $userinfo['dntsc_name'] = $userinfo['name'];
            }
            if ( ! empty( $userinfo['link'] ) ) {
                $userinfo['dntsc_url'] = $userinfo['link'];
            }
            if ( ! empty( $userinfo['picture']['data']['url'] ) ) {
                $userinfo['dntsc_avatar_url'] = $userinfo['picture']['data']['url'];
            }
            $avatar_url = FALSE;
            if ( ! empty( $userinfo['id'] ) ) {
                $id = strtolower( trim ( $userinfo['id'] ) );
                $url = "https://graph.facebook.com/{$id}/picture?width=256&height=256&redirect=false&return_ssl_resources=1&access_token=" . $dntsc_session['access_token'];
                $avatar_url = dntsc_get_facebook_avatar_url( $url );
            }
            if ( $avatar_url !== FALSE ) { 
                $userinfo['dntsc_avatar_url'] = $avatar_url;
            } else if ( ! empty( $userinfo['picture']['data']['url'] ) ) {
                dntsc_debug( "falling back to using Facebook user object picture url" );
                $userinfo['dntsc_avatar_url'] = $userinfo['picture']['data']['url'];
            }
            break;

    }
    dntsc_debug( 'fixed-up user information: ' .
        print_r( $userinfo, TRUE ) );

    if ( empty( $userinfo['dntsc_name'] ) ) {
        dntsc_error( 'service did not return a display name' );
        return FALSE;
    }

    if ( empty( $userinfo['dntsc_url'] ) ) {
        dntsc_error( 'Service did not return a URL for the user' );
        return FALSE;
    }

    if ( empty( $userinfo['dntsc_email'] ) ) {
        dntsc_error( 'Service did not return an email address for the user' );
        return FALSE;
    }

    $dntsc_session['userinfo'] = $userinfo;
    return $userinfo;

}


add_action( 'wp_footer', 'dntsc_footer_script', 30 ); // 30 to ensure we happen after sfc-base.php, if Simple Facebook Connect is also being used
function dntsc_footer_script() {

    global $dntsc_options;
    global $dntsc_comments_form;
    global $dntsc_service_name;

    if ( $dntsc_comments_form != true ) { return; } // nothing to do, comment form not displayed

    $userinfo = dntsc_get_userinfo();
    if ( $userinfo === FALSE ) {
        dntsc_destroy_session();
        return;
    }

    $name = esc_html( $userinfo['dntsc_name'] );

    $image_url = dntsc_download_avatar_image( $userinfo );
    if ( $image_url !== '' ) {
        $image = "<img style=\"float: left; margin-right: 1em;\" src=\"$image_url\" />";
    } else {
        $image = '';
    }

    $service = dntsc_get_service();
    $servicename = $dntsc_service_name[$service];

    global $wp_query;
    $post_id = $wp_query->post->ID;
    $signout = '/' . $dntsc_options['callback'] . "/?step=signout&id=$post_id";

    // NOTE: this will be echo'd into Javascript code as a single-quoted
    // string.  Make sure the string doesn't get terminated early with
    // an unescaped apostrophe, and that other variables have all been
    // escaped to protect against injection.
    $user_info = "<div class=\"comment-notes-logged-in\"><span class=\"avatar\">$image</span><p>Hi, $name!  You are signed in via your $servicename account.  Your $servicename user information will be used for comment attribution.<br /><a class=\"btn btn-default btn-sm\" href=\"$signout\">Sign&nbsp;out</a></p></div><br />";


?>
<script type="text/javascript">
    jQuery('#comment-notes').hide();
    jQuery('#comment-user-details').hide().after('<?php echo $user_info; ?>');
    jQuery('#dntsc-comment-fields').show();
</script>
<?php

}


add_filter( 'pre_comment_on_post', 'dntsc_comment_info' );
function dntsc_comment_info( $comment_post_ID ) {

    global $userinfo;
    global $wpdb;
    global $dntsc_options;

    $userinfo = dntsc_get_userinfo();
    if ( $userinfo === FALSE ) {
        dntsc_destroy_session();
        return;
    }

    if ( 1 ) {
        // This is an experiment to hide social media profile links behind
        // a layer of indirection.
        $avatar_table = $wpdb->prefix . 'dntsc_avatar';
        $local_avatar_id = $wpdb->get_var( $wpdb->prepare( "
            SELECT local_avatar_id FROM $avatar_table
            WHERE service_author_url = %s", $userinfo['dntsc_url'] ) );
        if ( $local_avatar_id == NULL ) {
            dntsc_debug( 'unable to find local_avatar_id, destroying session' );
            dntsc_destroy_session();
            return;
        }
        $url = '/' . $dntsc_options['callback'] . '/?step=profile&id=' .
            $local_avatar_id;
    } else {
        $url = $userinfo['dntsc_url'];
    }


    $_POST['author'] = $userinfo['dntsc_name'];
    $_POST['url'] = $url;
    $_POST['email'] = $userinfo['dntsc_email'];
    return;

}


?>
