=== Plugin Name ===
Contributors: markmont
Donate link: https://supporters.eff.org/donate
Tags: comments, authentication, login, privacy, oauth, google, facebook, twitter, github, button, social
Requires at least: 3.6
Tested up to: 3.6
Stable tag: trunk
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl.html

Allows users to authenticate via OAuth to social networking sites to leave WordPress comments and protects the privacy of WordPress readers.

== Description ==

* Allows users to authenticate via OAuth to social networking sites in order to leave WordPress comments.
    * GitHub
    * Google
    * Twitter
    * Facebook
* Does not require people who comment to register or log in to WordPress.
* Attempts to prevent WordPress readers from being tracked by the social networking providers:
    * Downloads avatars for people who comment and stores them within WordPress.  WordPress readers then retrieve the avatars of people who left comments from WordPress rather than from the social media providers.
    * Prevents a referer from being sent when a reader visits a the social media profile of a person who left a comment.

DNT Social Commenting is an ongoing experiment, rather than a solution to an actual problem.  Better alternatives for preventing tracking include:

* Don't use social media!  Either allow anonymous commenting, or require people who leave comments to register.
* Use the standard WordPress social media plugins together with one of the following:
    * [Social Share Privacy](https://github.com/panzi/SocialSharePrivacy)
    * [shareNice](https://github.com/mischat/shareNice)

DNT Social Commenting **__does not support sharing buttons__** (such as "Like" or "+1").

== Installation ==

1. Extract and upload the `dnt-social-commenting` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in the WordPress admin panel
3. Configure the plugin through the 'Settings' menu in the WordPress admin panel.  You will need to create an "app" with each social media provider you want users to be able to sign in with, and enter the app's Id (or Key) and Secret on the Plugin's Settings page.  Most social media providers require you to specify a callback URL when you create an app -- you can get the callback URL from the "Advanced" section at the bottom of the plugin's Settings page.

= Advanced Installation =

This plugin requires a directory where it can store avatar images that it downloads from social media providers, with 256 sub-directories underneath it.  The plugin's Settings page attempts to create these directory automatically, advanced users may want to create them by hand, or it may be necessary to create them by hand if the Settings page fails to create them correctly.

The avatar directory and its subdirectories must be writable by WordPress -- that is, they should have the same permissions as a WordPress upload directory.

Here are Linux commands to manually create the avatar directories that advanced users can adapt to their own WordPress installations:

    cd /var/www/html/wordpress/wp-content/uploads
    mkdir dntsc-avatars
    cd dntsc-avatars
    perl -e 'for $i (0..255) { mkdir( sprintf("%02x", $i ) ); }'
    chgrp -R apache /var/www/html/wordpress/wp-content/uploads/dntsc-avatars
    chmod -R 775 /var/www/html/wordpress/wp-content/uploads/dntsc-avatars

After creating the directories, you should see something like this:

    $ ls /var/www/html/wordpress/wp-content/uploads/dntsc-avatars
    00  10  20  30  40  50  60  70  80  90  a0  b0  c0  d0  e0  f0
    01  11  21  31  41  51  61  71  81  91  a1  b1  c1  d1  e1  f1
    02  12  22  32  42  52  62  72  82  92  a2  b2  c2  d2  e2  f2
    03  13  23  33  43  53  63  73  83  93  a3  b3  c3  d3  e3  f3
    04  14  24  34  44  54  64  74  84  94  a4  b4  c4  d4  e4  f4
    05  15  25  35  45  55  65  75  85  95  a5  b5  c5  d5  e5  f5
    06  16  26  36  46  56  66  76  86  96  a6  b6  c6  d6  e6  f6
    07  17  27  37  47  57  67  77  87  97  a7  b7  c7  d7  e7  f7
    08  18  28  38  48  58  68  78  88  98  a8  b8  c8  d8  e8  f8
    09  19  29  39  49  59  69  79  89  99  a9  b9  c9  d9  e9  f9
    0a  1a  2a  3a  4a  5a  6a  7a  8a  9a  aa  ba  ca  da  ea  fa
    0b  1b  2b  3b  4b  5b  6b  7b  8b  9b  ab  bb  cb  db  eb  fb
    0c  1c  2c  3c  4c  5c  6c  7c  8c  9c  ac  bc  cc  dc  ec  fc
    0d  1d  2d  3d  4d  5d  6d  7d  8d  9d  ad  bd  cd  dd  ed  fd
    0e  1e  2e  3e  4e  5e  6e  7e  8e  9e  ae  be  ce  de  ee  fe
    0f  1f  2f  3f  4f  5f  6f  7f  8f  9f  af  bf  cf  df  ef  ff
    $ 

If you need to change the location of the avatar directories after people have left comments, the Settings page try to move everything automatically when you change the Avatar Directory setting.  If this does not work, you can move the directory from the command line and then go back and change the Avatar Directories setting afterward.  For example.

    $ mv /var/www/html/wordpress/wp-content/uploads/dntsc-avatars /opt/images/wordpress/avatars

Note that the avatar directory must be in a place from which the web server can serve files.  Be sure to update the Avatar URL on the plugin settings page appropriately.

== Frequently Asked Questions ==

= How can I get a Like, +1, or Tweet button for my articles? =

Use a different plugin instead of this one.

= Is the privacy of people who leave comments protected? =

No, only the privacy of people who read the comments is protected.  Currently, social media providers will know the URL of the article or page containing the sign-in button that the commentor clicks on.  However, it should be possible to improve this in a future version so that the social media provider only knows the URL of the blog rather than the URL of the specific article/page.

== Screenshots ==

1. Sign-in buttons (when "Require Sign-in" is checked on the Settings page).
2. Comment form, after sign-in (when "Require Sign-in" is checked on the Settings page).
3. Settings.
3. Advanced Settings.

== Changelog ==

= 1.0.0 =
* Initial release on GitHub.

== Upgrade Notice ==

== Acknowledgements ==

This plugin's css3-social-signin-buttons directory contains [CSS3 Social Sign-in Buttons](http://nicolasgallagher.com/lab/css3-social-signin-buttons/) by Nicolas Gallagher.  CSS3 Social Sign-in Buttons is in the public domain.

This plugin's oauth subdirectory contains PHP code from the [oauth](http://oauth.googlecode.com/) project, licensed under the MIT license.

The remainder of the DNT Social Commenting code is available under the following license:

Copyright 2013 Mark Montague, mark@catseye.org

DNT Social Commenting is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

DNT Social Commenting is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with DNT Social Commenting.  If not, see <http://www.gnu.org/licenses/>.

