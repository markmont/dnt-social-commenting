<?php
/*

dntsc-require-signin.php
    Require the user to sign-in via a social networking service
    in order to leave comments.

    Note that some of the filters and actions hooked in this file
    are also hooked in the main DNTSC plugin file; in these cases,
    they will have differing priorities and the ordering of the
    priorities is crucial.

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


//
// Never use anyting submitted in the author, email, url fields:
//

add_filter( 'pre_comment_on_post', 'dntsc_clear_comment_fields', 0, 1 );

function dntsc_clear_comment_fields( $comment_post_ID ) {

    global $dntsc_options;

    if ( $dntsc_options['required'] ) {
        unset( $_POST['author'] );
        unset( $_POST['email'] );
        unset( $_POST['url'] );
    }

}


//
// Hide the author, email, url fields so they are never displayed to the user.
// (Leave them on the form in case another plugin or theme expects to
// manipulate them).
//

add_filter( 'comment_form_field_author', 'dntsc_comment_form_hide_text_field' );
add_filter( 'comment_form_field_email',  'dntsc_comment_form_hide_text_field' );
add_filter( 'comment_form_field_url',    'dntsc_comment_form_hide_text_field' );

function dntsc_comment_form_hide_text_field( $content ) {

    global $dntsc_options;

    if ( $dntsc_options['required'] ) {
        $content = str_replace( "<p class",
            "<p style=\"display:none;\" class", $content );
    }
    return $content;

}


//
// Put a div around the comment textbox, notes, and post button.
// Hide this div by default, and display it again only when a user
// signs in.  This way, the user won't see a form that they won't
// be able to submit yet (that is, when they are not signed in).
//

add_action( 'comment_form_after_fields', 'dntsc_start_comment_div', 100, 0);

function dntsc_start_comment_div() {

    global $dntsc_options;

    if ( $dntsc_options['required'] ) {
        echo "\n<div id=\"dntsc-comment-fields\" style=\"display:none;\">\n";
    }

}


add_action( 'comment_form', 'dntsc_end_comment_div', 100, 0);

function dntsc_end_comment_div() {

    global $dntsc_options;

    if ( $dntsc_options['required'] && ! is_user_logged_in() ) {
        echo "\n</div><!-- dntsc-comment-fields -->\n";
    }

}


//
// Change the "Your email address will not be published. Required fields are
// marked *" text to something that tells the user that they need to sign in
// to leave a comment.
//

add_filter( 'comment_form_defaults', 'dntsc_change_comment_defaults' );

function dntsc_change_comment_defaults( $fields ) {

    global $dntsc_options;

    if ( $dntsc_options['required'] ) {
        $fields['comment_notes_before'] = '<p class="comment-notes" id="comment-notes">Sign in via one of these serivces to leave a comment:</p>';
    }

    return $fields;

}

?>
