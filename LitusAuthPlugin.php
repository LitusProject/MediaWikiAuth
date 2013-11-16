<?php
/*
 * Copyright (C) 2012 Litus <https://github.com/LitusProject>
 *
 * Permission is hereby granted, free of charge, to any person
 * obtaining a copy of this software and associated documentation
 * files (the "Software"), to deal in the Software without
 * restriction, including without limitation the rights to use,
 * copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom
 * the Software is furnished to do so, subject to the following
 * conditions:
 *
 * The above copyright notice and this permission notice shall
 * be included in all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
 * OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
 * HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
 * WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
 * OTHER DEALINGS IN THE SOFTWARE.
 */

if ( !defined( 'MEDIAWIKI' ) ) exit;

/* The Litus API Server to use for authentication */
$wgLitusAPIServer = '';

/* The API key */
$wgLitusAPIKey = '';

/* The Litus server itself, for login/logout links */
$wgLitusServer = '';

/* Required status to be allowed to log in */
$wgLitusRequiredStatus = array(
    'university_status' => false,
    'organization_status' => false
);

/* The web page to redirect to if the user has an invalid status. */
$wgLitusInvalidStatusRedirect = false;

/* The callback page for the login */
$wgLitusLoginCallback = array(
    'title' => 'Special:UserLogin',
    'returnto' => 'Main+Page'
);

/* The cookie to use for authentication */
$wgLitusAuthCookie = 'Litus_Auth_Session';

/* Allow the user with this user ID to login regardless of university and organization status */
$wgLitusAdminUserid = false;

/**
 * Add extension information to Special:Version
 */
$wgExtensionCredits['other'][] = array(
    'path' => __FILE__,
    'name' => 'Litus Authentication Plugin',
    'version' => '0.2',
    'author' => '[https://github.com/LitusProject The Litus Project]',
    'description' => 'Automatic login using a Litus API server.',
    'url' => 'https://github.com/LitusProject/MediaWikiAuth'
);

class LitusApi {
    public static function isLitusCookiePresent() {
        global $wgLitusAuthCookie;
        
        return array_key_exists( $wgLitusAuthCookie, $_COOKIE );
    }

    private static function sendApiRequest( $url, $postData = array() ) {
        global $wgLitusAPIServer;
        global $wgLitusAPIKey;
        global $wgLitusAuthCookie;

        // if there's no session cookie, we can't be logged in, return false
        if ( !self::isLitusCookiePresent() )
            return false;

        // add the API key and session cookie to the POST data
        $postData['key'] = $wgLitusAPIKey;
        $postData['session'] = $_COOKIE[$wgLitusAuthCookie];

        $result = Http::post( $wgLitusAPIServer . $url, array( 'postData' => $postData ) );

        if ( preg_match( '/error/', $result ) )
            return false;
        return $result;
    }

    public static function getUserInfo() {
        $json = self::sendApiRequest( '/auth/getPerson' );
        if ( !$json )
            return false;

        return json_decode( $json );
    }
}

/* add auto-auth hook */
$wgHooks['UserLoadFromSession'][] = 'fnLitusAuthFromSession';

/* Disable password reset */
$wgPasswordResetRoutes = false;

function fnLitusAuthFromSession( $user, &$result ) {
    global $wgLanguageCode, $wgRequest, $wgOut;
    global $wgLitusServer, $wgLitusRequiredStatus;
    global $wgLitusLoginCallback, $wgServer, $wgScript;
    global $wgLitusAdminUserid;

    if ( isset( $_REQUEST['title'] ) ) {
        $title = Title::newFromText( $wgRequest->getVal( 'title' ) );

        if ( $title->isSpecial( 'Userlogin' ) ) {
            $litusUser = LitusApi::getUserInfo();

            if ( !$litusUser ) {
                $returnto = $wgRequest->getVal( 'returnto' );
                if ( ! $returnto )
                    $returnto = $wgLitusLoginCallback['returnto'];
                
                $callback = 'https:' . $wgServer . $wgScript
                                . '?title=' . $wgLitusLoginCallback['title']
                                . '&returnto=' . $returnto;
                
                header( 'Location: ' . $wgLitusServer . '/wiki/auth/login/redirect/' . urlencode( $callback ) );
                exit();
            }

            // if not a valid status, redirect to page set by user
            $validRequest = true;
            if ( $wgLitusRequiredStatus['university_status'] !== false
                    && $litusUser->university_status !== $wgLitusRequiredStatus['university_status'] )
                $validRequest = false;
            if ( $wgLitusRequiredStatus['organization_status'] !== false
                     && $litusUser->organization_status !== $wgLitusRequiredStatus['organization_status'] ) 
                $validRequest = false;
            if ( $wgLitusAdminUserid !== false && $litusUser->username === $wgLitusAdminUserid )
                $validRequest = true;
            if ( !$validRequest ) {
                global $wgLitusInvalidStatusRedirect;
                header( 'Location: ' . ( $wgLitusInvalidStatusRedirect !== false
                                            ? $wgLitusInvalidStatusRedirect
                                            : $wgLitusServer ) );
                exit();
            }

            // TODO: s-nr als ID gebruiken?
            $username = $litusUser->full_name;
            $u = User::newFromName( $username );

            // Create a new user if it's the first time this user logs in
            if ( $u->getID() == 0 ) {
                $u->addToDatabase();
                $u->setRealName( $username );
                $u->setEmail( $litusUser->email );

                // set emailauthenticated
                $u->mEmailAuthenticated = wfTimestampNow();

                // set the password to an unexisting md5 hash:
                $u->setPassword( '*' ); 

                $u->setToken();
                $u->saveSettings();

                $ssUpdate = new SiteStatsUpdate( 0, 0, 0, 0, 1 );
                $ssUpdate->doUpdate();
            }

            $u->setOption( 'rememberpassword', 1 );
            $u->setCookies();

            $user = $u;

            // Redirect if a returnto parameter exists, else go to main page
            $returnto = $wgRequest->getVal( 'returnto' );
            $url = Title::newMainPage()->getFullUrl();
            if ( $returnto ) {
                $target = Title::newFromText( $returnto );
                if ( $target ) {
                    // make sure we don't loop to logout
                    if ( $target->getNameSpace() != NS_SPECIAL )
                        $url = $target->getFullUrl();
                }
            }

            // action=purge is used to purge the cache
            $wgOut->redirect( $url . '?action=purge' );
        } else if ( $title->isSpecial( 'Userlogout' ) ) {
            $user->logout();
        }
    } else
        die( 'Title not set...' );

    return true;
}
