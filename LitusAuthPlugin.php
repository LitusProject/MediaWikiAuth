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

/* Disable password reset */
$wgPasswordResetRoutes = false;

/**
 * Add extension information to Special:Version
 */
$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'Litus Authentication Plugin',
	'version' => '0.1',
	'author' => '[https://github.com/LitusProject The Litus Project]',
	'description' => 'Automatic login using a Litus API server.',
	'url' => 'https://github.com/LitusProject/MediaWikiAuth'
);

/* check whether the Litus session cookie is present */
$litusCookiePresent = array_key_exists( 'Litus_Auth_Session', $_COOKIE );

class LitusApi {

	private static function sendApiRequest( $url, $postData = array() ) {
		global $wgLitusAPIServer;
		global $wgLitusAPIKey;
        global $litusCookiePresent;
        
        // if there's no session cookie, we can't be logged in, return false
        if ( !$litusCookiePresent )
            return false;
		
        // add the API key and session cookie to the POST data
		$postData['key'] = $wgLitusAPIKey;
        $postData['session'] = $_COOKIE['Litus_Auth_Session'];
		
		return Http::post( $wgLitusAPIServer . $url, array( 'postData' => $postData ) );
	}

	public static function isLoggedIn() {
		return 'true' == self::sendApiRequest( '/auth/isLoggedIn' );
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

function fnLitusAuthFromSession( $user, &$result ) {
	global $wgLanguageCode, $wgRequest, $wgOut;
	global $wgLitusUserLoginTitles, $wgLitusLogoutTitles, $wgLitusServer;
	
	if ( isset( $_REQUEST['title'] ) ) {
		$title = Title::newFromText( $wgRequest->getVal( 'title' ) );
		
		if ( $title->isSpecial( 'Userlogin' ) ) {
			$litusUser = LitusApi::getUserInfo();
			
			// TODO abort if not student, redirect to page saying that only students have access?
			
			if ( !$litusUser ) {
				header('Location: ' . $wgLitusServer . '/admin/auth/login');
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
			
			// Redirect if a returnto parameter exists
			$returnto = $wgRequest->getVal( 'returnto' );
			if ( $returnto ) {
				$target = Title::newFromText( $returnto );
				if ( $target ) {
					// make sure we don't loop to logout
					if ( $target->getNameSpace() == NS_SPECIAL )
						$url = Title::newMainPage()->getFullUrl();
					else
						$url = $target->getFullUrl();
					
					// action=purge is used to purge the cache
					$wgOut->redirect( $url . '?action=purge' );
				}
			}
		} else if ( $title->isSpecial( 'Userlogout' ) ) {
			$user->logout();
		}
	} else
		die( 'Title not set...' );
	
	return true;
}
