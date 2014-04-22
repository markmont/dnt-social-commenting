<?php
/*

dntsc-avatar.php
    Manage locally-stored avatars for DNT Social Commenting.

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


function dntsc_download_avatar_image( $userinfo ) {

    global $wpdb;
    global $dntsc_options;

    dntsc_debug( 'downloading avatar image' );

    $image_source = $userinfo['dntsc_avatar_url'];

    if ( ! filter_var( $image_source, FILTER_VALIDATE_URL )
        || ( substr( $image_source, 0, 7 ) != 'http://' &&
            substr( $image_source, 0, 8 ) != 'https://' ) ) {
        dntsc_error( "dntsc_download_avatar_image: bad avatar URL: {$image_source}" );
        return '';
    }


    // Map URLs to random strings so that people won't be able to
    // match avatar image filenames to email addresses or URLs or
    // use avatar image filenames to tie commentor identities across
    // multiple sites.
    $avatar_table = $wpdb->prefix . 'dntsc_avatar';
    $local_avatar_id = $wpdb->get_var( $wpdb->prepare( "
        SELECT local_avatar_id FROM $avatar_table
        WHERE service_author_url = %s", $userinfo['dntsc_url'] ) );
    // If an entry was found, update the URL:
    if ( $local_avatar_id != NULL ) {
        $ok = $wpdb->update(
	    $avatar_table,
	    array( 'service_avatar_url' => $image_source ),
	    array( 'service_author_url' => $userinfo['dntsc_url'] ),
	    array( '%s' ),
	    array( '%s' )
        );
        // Note the update may not have resulted in the URL actually changing,
        // so use === to test for false in particular (as opposed to 0, which
        // is OK).
        if ( $ok === false ) {
            dntsc_error( 'unable to update dntsc_avatar table' );
            return '';
        }
    }
    $image_file = $local_avatar_id;
    // Generate a filename if one was not found:
    while ( $image_file == NULL ) {
        $local_avatar_id = bin2hex( openssl_random_pseudo_bytes( 16 ) );
        $url = $wpdb->get_var( $wpdb->prepare( "
            SELECT service_author_url FROM $avatar_table
            WHERE local_avatar_id = %s", $local_avatar_id ) );
        if ( $url == NULL ) {
            $ok = $wpdb->insert( $avatar_table,
                array( 'service_author_url' => $userinfo['dntsc_url'],
                    'service_avatar_url' => $image_source,
                    'local_avatar_id' => $local_avatar_id ),
                array( '%s', '%s', '%s' ) );
            if ( ! $ok ) {
                dntsc_error( 'unable to insert into dntsc_avatar table' );
                return '';
            }
            $image_file = $local_avatar_id;
        }
    }
    dntsc_debug( "{$userinfo['dntsc_email']} has local_avatar_id {$local_avatar_id}" );
    // add directory hash to filename:
    $image_file = substr( $image_file, 0, 2 ) . '/'. $image_file;


    dntsc_debug( "retrieving {$image_source}" );
    $response = wp_remote_get( $image_source,
        array( 'sslverify' => TRUE, 'timeout' => 10 )
    );
    if ( is_wp_error( $response ) ) {
        dntsc_error( "unable to retrieve avatar image {$image_source} : "
            . $response->get_error_message() );
        return '';
    }
    if ( wp_remote_retrieve_response_code( $response ) != 200 ) {
        dntsc_error( "unable to retrieve avatar image: {$image_source} : got HTTP response code "
            . wp_remote_retrieve_response_code( $response ) );
        return '';
    }

    $headers = wp_remote_retrieve_headers( $response );
    dntsc_debug( 'got avatar image.  Headers: ' . print_r( $headers, TRUE ) );

    $extension = '';
    if ( ! empty( $headers['content-disposition'] ) &&
        preg_match( "/filename=[\"']?[^\"']+(\\.\w+)[\"']?/i",
            $headers['content-disposition'], $match ) ) {
        $extension = strtolower( $match[1] );
        if ( $extension == '.jpeg' ) { $extension = '.jpg'; }
        if ( $extension != '.png' && $extension != '.jpg'
            && $extension != 'gif' ) {
            $extension = '';
        }
    }
    if ( $extension == '' && ! empty( $headers['content-type'] ) ) {
        switch ( $headers['content-type'] ) {
            case 'image/png':
                $extension = '.png';
                break;
            case 'image/jpeg':
                $extension = '.jpg';
                break;
            case 'image/gif':
                $extension = '.gif';
                break;
        }
    }
    if ( $extension == '' ) {
        dntsc_error( 'dntsc_download_avatar_image: unable to determine avatar file type' );
        return '';
    }

    $image_data = wp_remote_retrieve_body( $response );

    $image_info = getimagesizefromstring( $image_data );
    if ( ! $image_info ) {
        dntsc_error( 'dntsc_download_avatar_image: avatar data was not an image' );
        return '';
    }
    if ( $image_info[2] != IMAGETYPE_PNG && $image_info[2] != IMAGETYPE_GIF
        && $image_info[2] != IMAGETYPE_JPEG ) {
        dntsc_error( 'avatar image was an unsupported type: '
            . $image_info['mime'] );
        return '';
    }
    dntsc_debug( "avatar image is has dimensions {$image_info[0]} x {$image_info[1]}" );
    if ( $image_info[0] < 24 || $image_info[1] < 24 ) {
        dntsc_error( "avatar image is too small, failing" );
        return '';
    }

    if ( $image_info[0] > 128 || $image_info[1] > 128 ) {
        dntsc_debug( "avatar image is too large, resizing" );
        $max_dim = ( $image_info[0] > $image_info[1] ) ?
            $image_info[0] : $image_info[1];
        $scale = 128.0 / $max_dim;
        $new_width = intval( $image_info[0] * $scale );
        $new_height = intval( $image_info[1] * $scale );
        dntsc_debug( "new avatar image size: {$new_width} x {$new_height}" );
        if ( $new_width < 24 || $new_height < 24 ) {
            dntsc_error( 'dntsc_download_avatar_image: new avatar image would be too small' );
            return '';
        }
        try {
            $image = new Imagick();
            $image->readImageBlob( $image_data );
            $image->setImageOpacity(1.0); // in case it uses transparency
            $image->resizeImage( $new_width, $new_height,
                Imagick::FILTER_LANCZOS, 1 );
            $image_data = $image->getImageBlob();
            $image->destroy();
        } catch ( Exception $e ) {
            dntsc_error( 'dntsc_download_avatar_image: failed to resize image: ',
                $e->getMessage() );
            return '';
        }
    }


    // Remove all files whose names begin with $image_file
    // Otherwise, if a service changes an image from a png to a jpg
    // the jpg won't be served since we prefer png over jpg.
    try { 
        @unlink( $dntsc_options['avatar_dir'] . $image_file . '.png' );
        @unlink( $dntsc_options['avatar_dir'] . $image_file . '.jpg' );
        @unlink( $dntsc_options['avatar_dir'] . $image_file . '.gif' );
    } catch ( Exception $e ) {
        # Do nothing
    }

    $image_filename = $dntsc_options['avatar_dir'] . $image_file . $extension;
    dntsc_debug( "writing avatar to {$image_filename}" );
    $fp = fopen( $image_filename, 'w' );
    if ( ! $fp ) {
        dntsc_error( "unable to write to {$image_filename}" );
        return '';
    }
    fwrite( $fp, $image_data );
    fclose( $fp );

    $image_url = $dntsc_options['avatar_url'] . $image_file . $extension;
    dntsc_debug( "returning avatar URL path: {$image_url}" );
    return $image_url;

}


add_filter( 'get_avatar', 'dntsc_get_avatar', 10, 5 );
function dntsc_get_avatar( $avatar, $id_or_email, $size = '96', $default = '',
  $alt = false ) {

    global $wpdb;
    global $dntsc_options;

    //dntsc_debug( "dntsc_get_avatar called: $avatar\n" . print_r( $id_or_email, TRUE ) );

    // If the core function determined that an avatar should not be
    // displayed for whatever reason, trust it.
    if ( $avatar === false ) { return $avatar; }

    // Don't do anything unless this is for a comment.
    if ( ! is_object( $id_or_email ) || ! isset( $id_or_email->comment_ID ) ) {
        return $avatar;
    }

    if ( empty( $id_or_email->comment_author_url ) ) { return $avatar; }
    $url = $id_or_email->comment_author_url;
    dntsc_debug( "finding avatar for ${url}" );

    if ( preg_match( "/\/" . $dntsc_options['callback'] .
            "\/?\?step=profile&amp;id=([0-9a-f]+)$/",
            $url, $match ) ) {
        $local_avatar_id = $match[1];
    } else {
        dntsc_debug( 'URL did not contain local avatar id, checking database' );
        $avatar_table = $wpdb->prefix . 'dntsc_avatar';
        $local_avatar_id = $wpdb->get_var( $wpdb->prepare( "
            SELECT local_avatar_id FROM $avatar_table
            WHERE service_author_url = %s", $url ) );
        if ( $local_avatar_id == NULL ) {
            dntsc_debug( 'No local avatar id found, try to create one' );
            $email = 'unknown@gravatar.com';
            if ( ! empty( $id_or_email->comment_author_email ) ) {
                $email = strtolower( trim( $id_or_email->comment_author_email ) );
            }
            $userinfo['dntsc_url'] = $url;
            $userinfo['dntsc_email'] = $email;
            $userinfo['dntsc_avatar_url'] =
                'https://secure.gravatar.com/avatar/' .  md5( $email )
                    . '?s=96&amp;d=mm';
            $rating = get_option( 'avatar_rating' );
            if ( ! empty( $rating ) ) {
                $userinfo['dntsc_avatar_url'] .= "&amp;r={$rating}";
            }
            $new_url = dntsc_download_avatar_image( $userinfo );
            if ( ! $new_url ) { return $avatar; }
            dntsc_debug( "successfully created local id, returning avatar url {$new_url}" );
            $avatar = "<img class='avatar avatar-{$size}' src='{$new_url}' width='{$size}' height='{$size}' style='width:($size}px;height:($size)px;' />";
            return $avatar;
        }
    }

    $image_file = substr( $local_avatar_id, 0, 2 ) . '/'. $local_avatar_id;

    $image_filename = $dntsc_options['avatar_dir'] . $image_file;
    dntsc_debug( "looking for images with filenames that start with {$image_file}" );
    if ( file_exists( $image_filename . '.png' ) ) {
        $image_file .= '.png';
    } else if ( file_exists( $image_filename . '.jpg' ) ) {
        $image_file .= '.jpg';
    } else if ( file_exists( $image_filename . '.gif' ) ) {
        $image_file .= '.gif';
    } else {
        dntsc_error( "avatar not found for local_avatar_id ${local_avatar_id}" );
        return $avatar;
    }

    $image_url = $dntsc_options['avatar_url'] . $image_file;
    dntsc_debug( "returning avatar url {$image_url}" );
    $avatar = "<img class='avatar avatar-{$size}' src='{$image_url}' width='{$size}' height='{$size}' style='width:{$size}px;height:{$size}px;' />";

    return $avatar;

}


?>
