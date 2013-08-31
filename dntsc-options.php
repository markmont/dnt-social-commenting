<?php
/*

dntsc-options.php
    Options page for the DNT Social Commenting (DNTSC) WordPress plugin.

Copyright 2013 Mark Montague, mark@catseye.org

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
    A very useful tutorial for creating options pages is at:
    http://ottopress.com/2009/wordpress-settings-api-tutorial/
*/



// Define all options and give each one a default value:

$dntsc_upload_dir = wp_upload_dir();

global $dntsc_options;

$dntsc_options = array(
    'required'     => 0,
    'avatar_dir'   => ( ( ! empty( $dntsc_upload_dir['basedir'] ) ) ? trailingslashit( $dntsc_upload_dir['basedir'] ) . 'dntsc_avatars/' : '' ),
    'avatar_url'   => ( ( ! empty( $dntsc_upload_dir['baseurl'] ) ) ? $dntsc_upload_dir['baseurl'] . '/dntsc_avatars/' : '' ),
    'service'      => array(
        'github'   => array( 'enabled' => 0, 'id' => '', 'secret' => '' ),
        'google'   => array( 'enabled' => 0, 'id' => '', 'secret' => '' ),
        'twitter'  => array( 'enabled' => 0, 'id' => '', 'secret' => '' ),
        'facebook' => array( 'enabled' => 0, 'id' => '', 'secret' => '' ),
    ),
    'callback'     => 'dntsc-callback',
    'debug'        => 0,
    'valid'        => 1
);


global $dntsc_service_name;
$dntsc_service_name = array(
    'github'   => 'GitHub',
    'google'   => 'Google',
    'twitter'  => 'Twitter',
    'facebook' => 'Facebook'
);


function dntsc_options_page() {
?>
<div class="wrap">
    <?php screen_icon(); ?>
    <h2>DNT Social Commenting</h2>
    <p>
        Allows users to authenticate via OAuth to social networking sites in
        order to leave WordPress comments.  Does not require the user to
        register or log in to WordPress.  Attempts to prevent WordPress
        readers from being tracked by the social networking providers.
    </p>
    <form action="options.php" method="post">
        <?php settings_fields( 'dntsc_options' ); ?>
        <?php do_settings_sections( 'dntsc' ); ?>

        <p class="submit">
            <input type="submit" class="button-primary" value="<?php esc_attr_e('Save Changes'); ?>" />
        </p>

    </form>
</div>
<?php
}


function dntsc_section_general() { return; }
function dntsc_section_services() { return; }
function dntsc_section_advanced() {
    echo '<p>Most sites will not need to change these settings.</p>';
    return;
}


function dntsc_get_error( $show_file = 1 ) {
    $message = '';
    $error = error_get_last();
    if ( ! empty( $error['message'] ) ) {
        $message .= '<br />' . $error['message'];
        if ( $show_file && ! empty( $error['file'] ) ) {
            $message .= ': ' . $error['file'];
        }
    }
    return $message;
}


function dntsc_check_avatar_dir( $dir ) {

    $dir = str_replace( '\\', '/', $dir );

    if ( ! file_exists( $dir ) ) {
       $parent = dirname( $dir );
       if ( ! is_dir( $parent ) ) {
           return "The directory does not exist.  Please either create the directory or specify a directory which does exist.";
       }
       if ( ! @mkdir( $dir, 0755 ) ) {
           return "Unable to create the directory.  Please create it manually or specify a directory which already exists." . dntsc_get_error( 1 );
       }
    }
    if ( ! is_dir( $dir ) ) {
        return "Exists, but is not a directory.  Please specify a directory.";
    }

    for ( $i = 0 ; $i <= 255 ; $i++ ) {
        $hash = sprintf( '%02x', $i );
        $subdir = $dir . $hash;
        if ( ! file_exists( $subdir ) ) {
           if ( ! @mkdir( $subdir, 0755 ) ) {
               return "Unable to create subdirectory $hash.  Please create it manually or fix the underlying problem." . dntsc_get_error( 1 );
           }
           if ( ! is_dir( $subdir ) ) {
               return "$hash exists inside the directory, but is not a directory itself.  Please manually fix this problem.";
           }
        }
    }

    return '';

}


function dntsc_setting_require_signin() {

    global $dntsc_options;

?>
    <input id="dntsc_setting_require_signin" name="dntsc_options[required]"
        type="checkbox" value="1"
        <?php @checked( '1', $dntsc_options['required'] ) ?> />
    &nbsp;&nbsp;Only accept comments from people who have signed in via a social media service.
<?php
} 


function dntsc_setting_avatar_dir() {

    global $dntsc_options;

?>
    <input id="dntsc_setting_avatar_dir" name="dntsc_options[avatar_dir]"
        type="text" size="80" maxlength="254"
        value="<?php echo $dntsc_options["avatar_dir"]; ?>" />
    <p class="description">
        Directory or folder in which downloaded avatar images (retrieved
        by the plugin from the social media sites) for commenters will be
        stored.  WordPress must be able to create files in this directory --
        the permissions should be the same as for an upload directory.
    </p>
<?php
    $message = dntsc_check_avatar_dir( $dntsc_options['avatar_dir'] );
    if ( $message != '' ) {
        $dntsc_options['valid'] = 0;
        echo "<div style='padding: 3px 3px 3px 3px; background-color: rgb(255, 235, 232); border-color: rgb(204, 0, 0); border-radius: 3px 3px 3px 3px; border-width: 1px; border-style: solid;'>$message</div>";
    }
} 


function dntsc_check_avatar_url( $url ) {

    global $dntsc_options;

    if ( ! $dntsc_options['valid'] ) {
        // We can't check the URL unless the avatar directory is OK
        return '';
    }

    $test_string = bin2hex( openssl_random_pseudo_bytes( 16 ) );
    $test_subdir = substr( $test_string, 0, 2 );

    $test_file = $dntsc_options['avatar_dir'] . $test_subdir . '/test.txt';
    $test_url = $url . $test_subdir . '/test.txt';

    $fp = @fopen( $test_file, 'w' );
    if ( ! $fp ) {
        return "Unable to create test file.  Please manually fix the underlying problem." . dntsc_get_error( 0 );
    }
    fwrite( $fp, $test_string );
    fclose( $fp );

    dntsc_debug( "retrieving test URL {$test_url}" );
    $response = wp_remote_get( $test_url,
        array( 'sslverify' => TRUE, 'method' => 'GET' ) );

    if ( ! @unlink( $test_file ) ) {
        return "Unable to remove the test file {$test_file}  Please manually fix the underlying problem." . dntsc_get_error( 0 );
    }

    if ( is_wp_error( $response ) ) {
        return "unable to retrieve {$test_url} : "
            . $response->get_error_message();
    }

    dntsc_debug( 'got test URL response: ' .  print_r( $response, TRUE ) );

    if ( wp_remote_retrieve_response_code( $response ) != 200 ) {
        return "unable to retrieve {$test_url} : got HTTP response code "
            . wp_remote_retrieve_response_code( $response );
    }

    $result_string = trim( wp_remote_retrieve_body( $response ) );
    if ( $result_string !== $test_string ) {
        return 'Test failed: got unexpected content';
    }

    return '';

}


function dntsc_setting_avatar_url() {

    global $dntsc_options;

?>
    <input id="dntsc_setting_avatar_url" name="dntsc_options[avatar_url]"
        type="text" size="80" maxlength="254"
        value="<?php echo $dntsc_options['avatar_url']; ?>" />
    <p class="description">
        URL corresponding to the avatar directory or folder above.
    </p>
<?php
    $message = dntsc_check_avatar_url( $dntsc_options['avatar_url'] );
    if ( $message != '' ) {
        $dntsc_options['valid'] = 0;
        echo "<div style='padding: 3px 3px 3px 3px; background-color: rgb(255, 235, 232); border-color: rgb(204, 0, 0); border-radius: 3px 3px 3px 3px; border-width: 1px; border-style: solid;'>$message</div>";
    }
} 


function dntsc_setting_service( $args ) {

    global $dntsc_options;

    $service = $args['service'];

?>
    <input id="dntsc_setting_${service}_enable"
        name="dntsc_options[service][<?php echo $service; ?>][enabled]"
        type="checkbox" value="1"
        <?php @checked( '1', $dntsc_options['service'][$service]['enabled'] ) ?> />
        &nbsp;&nbsp;Enable
    <table class="form-table">
    <tr><td>ID</td>
    <td>
    <input id="dntsc_setting_${service}_id"
        name="dntsc_options[service][<?php echo $service; ?>][id]"
        type="text" size="80" maxlength="254"
        value="<?php echo $dntsc_options['service'][$service]['id']; ?>" />
    </td></tr>
    <tr><td>Secret</td>
    <td>
    <input id="dntsc_setting_${service}_secret"
        name="dntsc_options[service][<?php echo $service; ?>][secret]"
        type="text" size="80" maxlength="254"
        value="<?php echo $dntsc_options['service'][$service]['secret']; ?>" />
    </td></tr>
    </table>
    <br />
<?php
} 


function dntsc_setting_callback() {

    global $dntsc_options;

    echo home_url();
?>
/<input id="dntsc_setting_callback" name="dntsc_options[callback]"
        type="text" size="35" maxlength="32"
        value="<?php echo $dntsc_options["callback"]; ?>" />/
    <p class="description">
        You will need to give each social media provider this URL when you
        create the social media "app" for this WordPress blog.  The social
        media providers will send users to this URL to complete the WordPress
        sign-in process after the user has successfully signed in to the
        social media provider.
    </p>
    <p class="description">
        This URL <b>must not exist</b> on your WordPress site.  The part of
        the URL that you specify above can only contain the lowercase letters
        a-z, the digits 0-9, hyphens, and underscores.
    </p>
<?php
} 


function dntsc_setting_debug() {

    global $dntsc_options;

?>
    <input id="dntsc_setting_debug" name="dntsc_options[debug]"
        type="checkbox" value="1"
        <?php @checked( '1', $dntsc_options['debug'] ) ?> />
    &nbsp;&nbsp;Write debugging messages to PHP error log.
    <p class="description">
        Turning this on will make WordPress slower for every request,
        so leave it off unless there is a problem you are trying to fix.
    </p>
<?php
} 


function dntsc_avatar_cleanup( $dir ) {

    for ( $i = 0 ; $i <= 255 ; $i++ ) {
        $hash = sprintf( '%02x', $i );
        $subdir = $dir . $hash;
        $d = @opendir( $subdir ); 
        if ( $d === FALSE ) { return; }
        while ( ( $file = readdir( $d ) ) !== FALSE ) { 
            if (( $file != '.' ) && ( $file != '..' )) { 
                $filename = $subdir . DIRECTORY_SEPARATOR . $file;
                if ( is_file( $filename ) ) { @unlink( $filename ); }
            }
        }
        closedir( $d );
        @rmdir( $subdir );
    }
    
}


// Returns '' on success, error message on failure.
function dntsc_move_avatar_dir( $old_dir, $new_dir ) {

    $old_dir = str_replace( '\\', '/', $old_dir );
    $new_dir = str_replace( '\\', '/', $new_dir );

    // move the old directory to the new one if all three are true:
    //   old_dir has hash subdirs
    //   new_dir parent exists
    //   new_dir doesn't exist or does exist but does not have hash subdirs

    if ( ! is_dir( $old_dir ) ) { return ''; }

    $old_dir_has_subdirs = 0;
    for ( $i = 0 ; $i <= 255 ; $i++ ) {
        $hash = sprintf( '%02x', $i );
        $old_subdir = $old_dir . $hash;
        if ( is_dir( $old_subdir ) ) {
            $old_dir_has_subdirs = 1;
            break;
        }
    }
    if ( ! $old_dir_has_subdirs ) { return ''; }

    $new_parent = dirname( $new_dir );
    if ( ! is_dir( $new_parent ) ) {
        return "{$new_parent} does not exist or is not a directory";
    }

    if ( file_exists( $new_dir ) ) {
        if ( ! is_dir( $new_dir ) ) {
            return "{$new_dir} exists but is not a directory";
        }
        // check to make sure it has no hash subdirs
        $new_dir_has_subdirs = 0;
        for ( $i = 0 ; $i <= 255 ; $i++ ) {
            $hash = sprintf( '%02x', $i );
            $new_subdir = $new_dir . $hash;
            if ( is_dir( $new_subdir ) ) {
                $new_dir_has_subdirs = 1;
                break;
            }
        }
        if ( $new_dir_has_subdirs ) {
            return "{$new_dir} already contains avatar subdirectories";
        }
    } else {
        if ( ! @mkdir( $new_dir, 0755 ) ) {
            return "Unable to create the directory {$new_dir}" .
                dntsc_get_error( 1 );
        }
    }


    // Create a copy of the hash subdirectories

    for ( $i = 0 ; $i <= 255 ; $i++ ) {
        $hash = sprintf( '%02x', $i );
        $old_subdir = $old_dir . $hash;
        $new_subdir = $new_dir . $hash;
        if ( ! @mkdir( $new_subdir, 0755 ) ) {
            $error = dntsc_get_error( 1 );
            dntsc_avatar_cleanup( $new_dir ); 
            return "Unable to create the directory {$new_subdir}{$error}";
        }
        dntsc_debug( "copying files from {$old_subdir}" );
        $dir = @opendir( $old_subdir ); 
        if ( $dir === FALSE ) {
            $error = dntsc_get_error( 1 );
            dntsc_avatar_cleanup( $new_dir ); 
            return "Unable to read directory {$old_subdir}{$error}";
        }
        while ( ( $file = readdir( $dir ) ) !== FALSE ) { 
            if ( ( $file != '.' ) && ( $file != '..' ) ) { 
                $src = $old_subdir . DIRECTORY_SEPARATOR . $file;
                if ( is_file( $src ) ) {
                    $dest = $new_subdir . DIRECTORY_SEPARATOR . $file;
                    if ( ! @copy( $src, $dest ) ) {
                        $error = dntsc_get_error( 0 );
                        dntsc_avatar_cleanup( $new_dir ); 
                        return "Unable to copy {$src} to ${dest}{$error}";
                    }
                } else {
                    dntsc_avatar_cleanup( $new_dir ); 
                    return "{$src} is not a file.";
                }
            }
        }
        closedir( $dir );
    }

    // Now that we copied everything without errors, remove the old
    // avatar directory

    dntsc_avatar_cleanup( $old_dir ); 

    return '';

}


function dntsc_options_validate( $input ) {

    global $dntsc_options;
    global $dntsc_service_name;

    $new_options = get_option( 'dntsc_options' );
    if ( ! current_user_can( 'manage_options' ) ) { return $new_options; }

    dntsc_debug( 'Validating settings: ' . print_r( $input, TRUE ) );

    $new_options['required'] =
        ( ! empty( $input['required'] ) && $input['required'] == 1 ) ? 1 : 0;

    $old_avatar_dir = $new_options['avatar_dir'];
    if ( ! empty( $input['avatar_dir'] ) ) {
        $input['avatar_dir'] = trim( $input['avatar_dir'] );
        if ( substr( $input['avatar_dir'], -1 ) != '/' &&
            substr( $input['avatar_dir'], -1 ) != '\\' ) {
            // This plugin relies on the avatar directory path ending in a
            // directory separator.
            $input['avatar_dir'] .= '/';
        }
        if ( preg_match( '/^([a-z]:)?[\/\\\]/i', $input['avatar_dir'] ) ) {
            $new_options['avatar_dir'] = $input['avatar_dir'];
        } else {
            add_settings_error( 'dntsc_options', 'settings_updated',
                'Avatar Directory must be an absolute path.', 'error' );
        }
    }

    if ( ! empty( $input['avatar_url'] ) ) {
        $input['avatar_url'] = trim( $input['avatar_url'] );
        if ( substr( $input['avatar_url'], -1 ) != '/' &&
            substr( $input['avatar_url'], -1 ) != '\\' ) {
            // This plugin relies on the avatar URL ending with a /
            $input['avatar_url'] .= '/';
        }
        if ( filter_var( $input['avatar_url'], FILTER_VALIDATE_URL ) !== FALSE
            && ( substr( $input['avatar_url'], 0, 7 ) == 'http://' ||
                substr( $input['avatar_url'], 0, 8 ) == 'https://' ) ) {
            $new_options['avatar_url'] = $input['avatar_url'];
        } else {
            add_settings_error( 'dntsc_options', 'settings_updated',
                'Invalid Avatar URL.', 'error' );
        }
    }

    foreach ( $dntsc_service_name as $service => $service_name ) {

        $new_options['service'][$service]['enabled'] =
            ( ! empty( $input['service'][$service]['enabled'] )
                && $input['service'][$service]['enabled'] == 1 ) ? 1 : 0;

        $id = trim( $input['service'][$service]['id'] );
        if ( preg_match( '/^[a-z0-9_.+@=-]{1,254}$/i', $id ) ) {
            $new_options['service'][$service]['id'] = $id;
        } else {
            add_settings_error( 'dntsc_options', 'settings_updated',
                'Illegal characters in ${service_name} Id.', 'error' );
        }

        $secret = trim( $input['service'][$service]['secret'] );
        if ( preg_match( '/^[a-z0-9_.+@=-]{1,254}$/i', $secret ) ) {
            $new_options['service'][$service]['secret'] = $secret;
        } else {
            add_settings_error( 'dntsc_options', 'settings_updated',
                'Illegal characters in ${service_name} Id.', 'error' );
        }

    }

    if ( ! empty( $input['callback'] ) ) {
        $input['callback'] = trim( $input['callback'] );
        if ( preg_match( '/^[a-z0-9_-]{1,32}$/', $input['callback'] ) ) {
            // Important: this gets used in regular expressions and URLs without
            // any further escaping/encoding.  So if there are any characters
            // that have special meanings in regexps or URLs, escape or encode
            // those characters here.
            $new_options['callback'] = $input['callback'];
        } else {
            add_settings_error( 'dntsc_options', 'settings_updated',
                'Illegal characters in callback URL.', 'error' );
        }
    }

    $new_options['debug'] =
        ( ! empty( $input['debug'] ) && $input['debug'] == 1 ) ? 1 : 0;


    if ( $new_options['avatar_dir'] != $old_avatar_dir ) {
        $message = dntsc_move_avatar_dir( $old_avatar_dir,
            $new_options['avatar_dir'] );
        if ( $message != '' ) {
            add_settings_error( 'dntsc_options', 'settings_updated',
                'Unable to move avatar images from ' . $old_avatar_dir .
                ' to ' . $new_options['avatar_dir'] . ' : ' . $message,
                'error' );
            $new_options['avatar_dir'] = $old_avatar_dir;
        }
    }

    $dntsc_options = $new_options; 
    return $new_options;

}


add_action( 'admin_init', 'dntsc_admin_init' );
function dntsc_admin_init() {

    global $dntsc_service_name;

    if ( ! current_user_can( 'manage_options' ) ) { return; }

    register_setting( 'dntsc_options', 'dntsc_options',
        'dntsc_options_validate' );
    add_settings_section( 'dntsc_general', 'General',
        'dntsc_section_general', 'dntsc' );
    add_settings_section( 'dntsc_services', 'Services',
        'dntsc_section_services', 'dntsc' );
    add_settings_section( 'dntsc_advanced', 'Advanced',
        'dntsc_section_advanced', 'dntsc' );

    add_settings_field( 'dntsc_setting_require_signin', 'Require Sign In',
        'dntsc_setting_require_signin', 'dntsc', 'dntsc_general' );
    add_settings_field( 'dntsc_setting_avatar_dir', 'Avatar Directory',
        'dntsc_setting_avatar_dir', 'dntsc', 'dntsc_general' );
    add_settings_field( 'dntsc_setting_avatar_url', 'Avatar URL',
        'dntsc_setting_avatar_url', 'dntsc', 'dntsc_general' );

    foreach ( $dntsc_service_name as $service => $service_name ) {
        add_settings_field( 'dntsc_setting_' . $service,
            $service_name, 'dntsc_setting_service', 'dntsc',
            'dntsc_services', array( 'service' => $service ) );
    }

    add_settings_field( 'dntsc_setting_callback', 'Callback URL',
        'dntsc_setting_callback', 'dntsc', 'dntsc_advanced' );
    add_settings_field( 'dntsc_setting_debug', 'Debugging',
        'dntsc_setting_debug', 'dntsc', 'dntsc_advanced' );

}


add_action( 'admin_menu', 'dntsc_admin_add_page' );
function dntsc_admin_add_page() {

    add_options_page( 'DNT Social Commenting Settings',
        'DNT Social Commenting', 'manage_options', 'dntsc',
        'dntsc_options_page' );

}

?>
