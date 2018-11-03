<?php

/**
 * Simple Viewdata Server
 *
 * @version $Id$
 * @copyright 2018
 *
 *

 * Socket code based on an example at https://github.com/lukaszkujawa/php-multithreaded-socket-server
 *
 * based on early Prestel behaiours
 *
 * New commands -
 * *01 - info about a page
 * *02 - Timewarp toggle - when on, will go find page from another date
 *
 */



/**
 * Check dependencies
 */
if( ! extension_loaded('sockets' ) ) {
	echo "This example requires sockets extension (http://www.php.net/manual/en/sockets.installation.php)\n";
	exit(-1);
}

if( ! extension_loaded('pcntl' ) ) {
	echo "This example requires PCNTL extension (http://www.php.net/manual/en/pcntl.installation.php)\n";
	exit(-1);
}

define ('MODE_BL',1);		// Typing a * command on the baseline
define ('MODE_FIELD',2);	// typing into an imput field
define ('MODE_WARPTO',3); 	// awaiting selection of a timewarp
define ('MODE_COMPLETE',4);	// Entry of data is complete ..
define ('MODE_SUBMITRF',5);	// asking if should send or not.
define ('MODE_RFSENT',6);
define ('MODE_RFERROR',7);
define ('MODE_RFNOTSENT',8);


define ('ACTION_RELOAD',1);
define ('ACTION_GOTO',2);
define ('ACTION_BACKUP',3);
define ('ACTION_NEXT',4);
define ('ACTION_INFO',5);
define ('ACTION_TERMINATE',6);
define ('ACTION_SUBMITRF',7);		// offer to submit a response frame
define ('ACTION_STAR',8);

define ('CON',chr(17));		// Cursor On
define ('COFF',chr(20));	// Cursor Off
define ('HOME',chr(30));
define ('LEFT', chr(8));
define ('RIGHT',chr(9));
define ('DOWN',chr(10));
define ('UP',chr(11));
define ('CR',chr(13));
define ('LF',chr(10));
define ('CLS',chr(12));
define ('ESC',chr(27));
define ('RED',ESC.'A');
define ('GREEN',ESC.'B');
define ('YELLOW',ESC.'C');
define ('BLUE',ESC.'D');
define ('MAGENTA',ESC.'E');
define ('CYAN',ESC.'F');
define ('WHITE',ESC.'G');
define ('NEWBG',ESC .']');

define ('MSG_TIMEWARP_ON', WHITE . 'TIMEWARP ON' . GREEN . 'VIEW INFO WITH *02');
define ('MSG_TIMEWARP_OFF', WHITE . 'TIMEWARP OFF' . GREEN . 'VIEWING DATE IS FIXED');
define ('MSG_TIMEWARP_TO', GREEN.'TIMEWARP TO %s');
define ('MSG_TIMEWARP', WHITE . 'OTHER VERSIONS EXIST' . GREEN . 'KEY *02 TO VIEW');
define ('MSG_SENDORNOT', GREEN . 'KEY 1 TO SEND, 2 NOT TO SEND');
define ('MSG_SENT', GREEN . 'MESSAGE SENT - KEY _ TO CONTINUE');
define ('MSG_NOTSENT', GREEN . 'MESSAGE NOT SENT - KEY _ TO CONTINUE');


define ('ERR_ROUTE', WHITE . 'MISTAKE?' . GREEN . 'TRY AGAIN OR TELL US ON *36_');
define ('ERR_PAGE', ERR_ROUTE );
define ('ERR_PRIVATE', WHITE . 'PRIVATE PAGE' . GREEN . '- FOR EXPLANATION *37_..');
define ('ERR_DATABASE',RED . 'UNAVAILABLE AT PRESENT - PLSE TRY LATER');
define ('ERR_NOTSENT', WHITE . 'MESSAGE NOT SENT DUE TO AN ERROR');

// This maps field types to user data or system data.
$fieldmap = array('s' => 'systel', 'n' =>'username', 'a' => 'address#', 'd' => '%date');
$fieldoptions = array('f' => array('edit' => true));

include_once('classes/vvdatabase.class.php');
include_once('classes/vv.class.php');

if (count($argv) == 1) {
	include_once('config/config.php');
} else {
	if (count($argv) == 3 && $argv[1] == '-c') {
		include_once('config/' . $argv[2]);
	}
}

$db = new vvdb();
// Connect to database.  Returns error message if unable to connect.
$r = $db->connect($config['dbserver'],$config['database'],$config['dbuser'], $config['dbpass']);
if (!empty($r)) {
	http_response_code(500);
	die ($r);
}



/**
 * Connection handler
 */
function onConnect( $client ) {
	global $config;
	global $fieldoptions;

	$pid = pcntl_fork();

	if ($pid == -1) {
		die('could not fork');
	} else if ($pid) {
		// parent process
		return;
	}




	$read = '';
	printf( "[%s] Connected at port %d\n", $client->getaddress(), $client->getPort() );

	$client->send(CLS . "Connecting..");



	$db = new vvdb();
	// Connect to database.  Returns error message if unable to connect.
	$r = $db->connect($config['dbserver'],$config['database'],$config['dbuser'], $config['dbpass']);
	if (!empty($r)) {
		http_response_code(500);
		$client->send(CLS . UP . ERR_DATABASE);
		die ($r);
	}



	// $user will eventually contain validated user details.
	$user = array(  'systel' => '019990001',
					'username' => 'DEMONSTRATION DATA USER',
					'address1' => '8 HERBAL HILL',
					'address2' => 'LONDON',
					'address3' => 'EC1R 5EJ',
					'address4' => '',
					'address5' => '',
					'CUGS' => array( 7800, 15500), 			// Closed User Groups this user has access to
					);




	$history = array();		// backup history



	$cmd = '';			// current *command being typed in
	$mode = false;		// input mode.
	$prevmode = false;	// previous mode
	$timewarp = false;
	$action = ACTION_GOTO;	// do something if set. used here to trigger a goto to the login form.
	if (isset($config['loginpage'])) {
		$page = $config['loginpage'];
		$subpage = 'a';
	} else if (!empty($service['start_page'])) {
	 	$page = $service['start_page'];
		$subpage = 'a';
	} else {
		$page = '1';			// next page
		$subpage = 'a';
	}
	$curpage = '';			// current page
	$cursub = '';
	$curfield = null;		// current input field
	$curfp = 0;				// current field, position within.
	$blp = 0;				// botton line polluted (by this no. of characters)
	$resetpsn = false;		// flag to reset position (used in fields)

	$service = $db->getServiceById($config['service_id']);
	$varient = $db->getAllVarients($config['service_id'], $config['varient_id']);
	if ($varient === false) {
			die ("no varient");
		}
	$varient = reset($varient);


	$matches = array();
	if (preg_match('@'.$service['page_format'].'@',$service['start_frame'],$matches)) {
		$page = $matches[1];
		$subpage = $matches[2];
		echo " Using start page ".$page.$subpage."\n";
	}
//	$start = $service['start_frame'];
//  where to start from


	while( $action != ACTION_TERMINATE ) {

/*
   This section deals with a keypress
*/
		$read = $client->read(1);
		if( $read != '' ) {
			printf("mode:%s cmd:%s  page:%s Received %s, (%s)\n",$mode, $cmd,$page,$read, ord($read));
			switch($mode){


				/*
				  Currently accepting keypresses into an input field on an RF
				*/
				case MODE_FIELD:		// entering data into a field
					$cmd = '';
					$action = false;
					switch($pagedata['type']){
						case 'a':		// response frame
							switch ($read) {
								case '_':	//# ends field entry
									$curfield ++;	//skip to next field
									if ($curfield < count($fields)) { 	// skip past non-editable fields
										for ($i = $curfield; $i < count($fields); $i++) {
											if (isset($fieldoptions[$fields[$i]['type']]) &&
											$fieldoptions[$fields[$i]['type']]['edit']) {
												$curfield = $i;
												break;
											}
										}
										if ($curfield !== false) {
											$client->send(outputPosition($fields[$curfield]['x'],$fields[$curfield]['y']).CON);
											$mode = MODE_FIELD;
											$fielddate[$curfield] = '';
										} else {
											// there were no (more) editable fields.
											$action = ACTION_SUBMITRF;
										}
									} else {
										// done them all  editable fields.
										$action = ACTION_SUBMITRF;
									}
									break;
								case '*':
									$prevmode = MODE_FIELD;
									$action = ACTION_STAR;
									break;
								case chr(8):	// left
									if ($curfp) {
										$client->send($read);
										$curfp--;
									};
									break;
								case chr(9):	// right
									if ($curfp < $fields[$curfield]['length']) {
										$client->send($read);
										$curfp++;
									};
									break;
								case chr(10):	// down
									if ($curfp + 40 < $fields[$curfield]['length']) {
										$client->send($read);
										$curfp = $curfp + 40;
									};
									break;
								case chr(11):	// up
									if ($curfp - 40 >= 0) {
										$client->send($read);
										$curfp = $curfp - 40;
									};
									break;
								case chr(13):	// CR
									if ($curfp + $fields[$curfield]['x'] > 40) { // on second or later line of a field
										$client->send($read);
										$curfp = (($curfp + $fields[$curfield]['x'])%40)*40;
									} else {
										$client->send(outputPosition($fields[$curfield]['x'],$fields[$curfield]['y']).CON);
										$curfp = 0;
									}
									break;

								case chr(27):	// Escape ?

									break;																																	;

								default:
									if (ord($read) > 31 && $curfp < $fields[$curfield]['length']) {
										$fielddata[$curfield]{$curfp} = $read;
										$curfp++;
										$client->send($read);
									}
							} // switch character pressed
							break;
						default:	// other frame types ... shouldn't get here
							;
					} // switch frame types

					break;

				/*
				  Currently waiting at the Key 1 to send, 2 not to send prompt.
				*/
				case MODE_SUBMITRF:
					switch($read){
						case '1':
							if(mail('robert@irrelevant.com', $service['service_name'], implode("\n",$fielddata))) {
								sendBaseline($client, $blp, MSG_SENT);
								$mode = MODE_RFSENT;
							} else {
								sendBaseline($client, $blp, ERR_NOTSENT);
								$mode = MODE_RFERROR;
							}
							break;
						case '2':
							sendBaseline($client, $blp, MSG_NOTSENT);;
							$mode = MODE_RFNOTSENT;
							break;
						case '*':
							$action = ACTION_STAR;
							break;
						default:
							;
					} // switch;
					break;

				/*
				   Message sent key # to continue
				*/

				case MODE_RFSENT:
					$client->send(COFF);
					if ($read == '_') {
						if (!empty($pagedata['route1'])) {
							$action = ACTION_GOTO;
							$page = $pagedata['route1'];
							$subpage = 'a';
						} else if ($r = $db->getFrame($service['service_id'],$varient['varient_id'],$page,chr(1+ord($subpage)))) {
							$action = ACTION_GOTO;
							$page = $curpage;
							$subpage = chr(1+ord($subpage));
						} else if (!empty($pagedata['route0'])) {
							$action = ACTION_GOTO;
							$page = $pagedata['route0'];
							$subpage = 'a';
						} else {
							$action = ACTION_GOTO;
							$page = '0';
							$subpage = 'a';
						}
					} else if ($read == '*') {
						$action = ACTION_STAR;
						break;
					}
					break;

				/*
				   message not sent, key # to continue
				*/
				case MODE_RFNOTSENT:
				case MODE_RFERROR:
					$client->send(COFF);
					if ($read == '_') {
						if (!empty($pagedata['route2'])) {
							$action = ACTION_GOTO;
							$page = $pagedata['route2'];
							$subpage = 'a';
						} else if ($r = $db->getFrame($service['service_id'],$varient['varient_id'],$page,chr(1+ord($subpage)))) {
							$action = ACTION_GOTO;
							$page = $curpage;
							$subpage = chr(1+ord($subpage));
						} else if (!empty($pagedata['route0'])) {
							$action = ACTION_GOTO;
							$page = $pagedata['route0'];
							$subpage = 'a';
						} else {
							$action = ACTION_GOTO;
							$page = '0';
							$subpage = 'a';
						}
					} else if ($read == '*') {
						$action = ACTION_STAR;
						break;
					}
					break;

				/*
				   List of alternate options has been presented
				*/

				case MODE_WARPTO:			// expecting a timewarp selection
					if (isset($alts[$read - 1 ])) {
						$v = $db->getAllVarients($config['service_id'], $alts[$read - 1]['varient_id']);
						if (!empty($v)) {
							$varient = reset($v);
							$page = $curpage;
							$subpage = $cursub;
							$action = ACTION_GOTO;
							break;
						}
					}
					// if wasn't a valid warpto keypress,
					//drop into

				/*
				   Not currently doing anything special.
				   Should look for route keypresses, * commands, etc.
				*/

				case false:			// not currently doing anything in particular
					$cmd = '';
					echo "Was idle\n";
					switch($read){
						case '_':	// hash for next subpage
							$action = ACTION_NEXT;
							break;
						case '*':	// start a star command!
							$action = ACTION_STAR;
							break;
						case '0':	// routing
						case '1':
						case '2':
						case '3':
						case '4':
						case '5':
						case '6':
						case '7':
						case '8':
						case '9':
							if (isset($pagedata['route' . $read]) && $pagedata['route' . $read] != '*') {
								$action = ACTION_GOTO;
								$page = $pagedata['route' . $read];
								$subpage = 'a';
								break;
							} else {
								sendBaseline($client, $blp, ERR_ROUTE);
								$mode = $action = false;
							}
							break;
						default:
							;
					} // switch;
					break;


				/*
				   currently accepting baseline imput after a * was received
				*/

				case MODE_BL:		// entering a baseline command
					echo "was waiting for page number\n";
					// if it's a number, continue entry
					if (strpos('0123456789',$read) !== false) {	// numeric
						$cmd .= $read;
						$client->send($read);
						$blp++;
					}
					// if we hit a special numeric command, deal with it.
					if ($cmd === '00') {	// refresh page
						$client->send(COFF);
						$action = ACTION_RELOAD;
						$cmd = '';
						break;
					}
					if ($cmd === '09') {	// reload page
						$client->send(COFF);
						$action = ACTION_GOTO;
						$cmd = '';
						break;

					}
					if ($cmd === '02') {		// new for emulator
						$client->send(COFF);
						$action = ACTION_INFO;
						$cmd = '';
						break;
					}
					if (($cmd === '01')) {	// new for emulator
						$client->send(COFF);
						$timewarp = !$timewarp;
						sendBaseline($client, $blp,
							( $timewarp ? MSG_TIMEWARP_ON : MSG_TIMEWARP_OFF));
						$cmd = '';
						$action = $mode = false;
					}
					// another star aborts the command.
					if ($read === "*") {		// abort command or reset input field.
						$action = false;
						sendBaseline($client, $blp, '');
						$cmd = '';

						if ($prevmode == MODE_FIELD) {
							$mode = $prevmode;
							$prevmode = false;
							$client->send(outputPosition($fields[$curfield]['x'],$fields[$curfield]['y']).CON);
							$client->send(str_repeat(' ',$fields[$curfield]['length']));
// tood reset stored entered text
							$resetpsn = $curfield;
						} else {
							$mode = false;
						}
						break;
					}
					// user hit # to complete request
					if ($read === '_') {		// really the # key,
						$client->send(COFF);
						if ($cmd === '') {			// nothing typed between * and #
							$action = ACTION_BACKUP;
						} else {			// *# means go back
							$page = $cmd;
							$subpage = 'a';
							$action = ACTION_GOTO;
						}
						$cmd = '';			// finished with this now
						break;
					}
					break;


				default:
					echo "not sure what we were doing\n";
			} // switch $mode
		} // something in $read

/*
   This section performs some action if it is deemed necessary
*/
		if ($action) {
			echo "Performing action $action\n";
		}
		switch($action){
			case ACTION_STAR:
				echo " star command started\n";
				sendBaseline($client, $blp, GREEN . '*', true);
				$client->send(CON);
				$action = false;
				$mode = MODE_BL;
				break;



			case ACTION_SUBMITRF:
				$action = false;
				sendBaseline($client, $bpl, MSG_SENDORNOT);
				$mode = MODE_SUBMITRF;
				break;




			case ACTION_BACKUP:
				// do we have anywhere to go?
				if (count($history) > 1) {	// because current page should always be in there.
					array_pop($history);	// drop current page to reveal previous
				}
				list($page, $subpage) = end($history);	// get new last entry,
				echo "Backing up to $page$subpage\n";
				// drop into
			case ACTION_NEXT:
				if ($action == ACTION_NEXT) {
					$cursub = $subpage;
					$subpage = chr(ord($subpage)+1);
				}
			case ACTION_GOTO:
//				$client->send(HOME . UP . GREEN . "Searching for page $page");
//				$blp = 20 + strlenv($page);
				// look for requested page
				$r = $db->getFrame($service['service_id'],$varient['varient_id'],$page,$subpage);
				if (empty($r) && $timewarp) {
					$r = $db->getFrame($service['service_id'],null,$page,$subpage);
				}
				if (empty($r)) {
					echo "Couldn't fetch $page$subpage\n";
					if ($action == ACTION_NEXT) {
						$subpage = $cursub;	// put subpage back as it was
					}
					sendBaseline($client, $blp, ERR_PAGE);
					$mode = $action = false;
					break;
				}
				$v = array_merge($varient, array_intersect_key($r, $varient));

				$m = $db->getFrameMeta($r['frameunique']);

				// set some defaults in case it's an incomplete record
				if (!isset($m['type']) || $m['type'] == ' ') {
					$m['type'] = 'i';
				}
//				return array_merge($pagerecord,$pagemeta);

				// validate if we have access top it
/*				if (isset($m['access']) && $m['access'] == 'n') {
					sendBaseline($client, $blp, ERR_PAGE);
					$mode = $action = false;
					break;
				}
				if (isset($m['cug']) && is_numeric($m['cug']) && $m['cug'] && !in_array($m['cug'],$usercugs)) {
					sendBaseline($client, $blp, ERR_PRIVATE);
					$mode = $action = false;
					break;
				}
*/


				// we have access...
				if ($r['varient_id'] != $varient['varient_id']) {
					if (empty($v['varient_date'])) {
						sendBaseline($client, $blp, sprintf(MSG_TIMEWARP_TO, 'unknown date') );
					} else {
						sendBaseline($client, $blp, sprintf(MSG_TIMEWARP_TO,date('j f Y',strtotime($v['varient_date']))) );
					}
					$varient = array_merge($varient, array_intersect_key($r, $varient));
				}
				$pagedata = array_merge($r, $m);
				$varient = $v;
				$curpage = $page;
				$cursub = $subpage;
				$cufield = 0;
				$curfp = 0;
				$pageflags = $db->getFrameTypeFlags($pagedata['type']);

				if ($action == ACTION_GOTO || $action == ACTION_NEXT) {	// only if new location, not going backwards
					$history[] = array($page,$subpage);
				}
				// drop into
			case ACTION_RELOAD:
				//
/*				if ($pageflags['clear']) {
					$output = CLS;
					$blp = 0;
				} else {
					$output = HOME;
				}
*/
//				print_r($pageflags); print_r($pagedata);

				switch($pagedata['type']){
					default:
					case 'i':	// standard frame
						if ($timewarp && 1 < count(
							$alts = $db->getAlternateFrames($service['service_id'],$varient['varient_id'],$curpage,$cursub)
							)) {
							$msg = MSG_TIMEWARP;
						} else {
							$msg = '';
						}

						$output = getOutput($curpage, $cursub, $pagedata, $pageflags, $msg);
						$blp = strlenv($msg);
						$client->send($output);
						$mode = $action = false;
						break;
					case 'a':		// active frame.  Prestel uses this for Response Framea.

						if ($timewarp && 1 < count(
							$alts = $db->getAlternateFrames($service['service_id'],$varient['varient_id'],$curpage,$cursub)
						)) {
							$msg = MSG_TIMEWARP;
						} else {
							$msg = '';
						}

						// this is a glorious hack to fix three out of 30,000 pages but might
						// break lots more.
						$pagedata = str_replace(chr(12),chr(27),$pagedata);

						// holds data entered by user.
						$fielddata = array();

						$fields = array();
						$output = getOutput($curpage, $cursub, $pagedata, $pageflags, $msg, $user, $fields);
						$blp = strlenv($msg);
						$client->send($output);

						if (count($fields)) {
							// need t skip to first field that is..
							// of a field type that is editable
							// or finish.
							$curfield = false;
							for ($i = 0; $i < count($fields); $i++) {
								if (isset($fieldoptions[$fields[$i]['type']]) &&
								    $fieldoptions[$fields[$i]['type']]['edit']) {
										$curfield = $i;
										break;
								}
							}
							$resetpsn = $curfield;
							if ($curfield !== false) {
								$mode = MODE_FIELD;
							} else {
								// there were no editable fields.
								$mode = MODE_COMPLETE;
							}
							$curfp = 0;
						}

						break;

					case 't':	// terminate
						$output = getOutput($curpage, $cursub, $pagedata, $pageflags);
						$client->send($output);
						$action = ACTION_TERMINATE;
						break;

				} // switch


				break;




			case ACTION_INFO:		// special emulator command
				$mode = false;
				$cmd='';
				$action = false;

				$output = outputPosition(0,0) . WHITE . NEWBG . RED . 'TIMEWARP INFO FOR Pg.' . BLUE . $curpage . $cursub . WHITE;
				$output .= outputPosition(0,1) . WHITE . NEWBG . BLUE . 'Service : ' . substr($service['service_name'] . str_repeat(' ',27),0,27) ;
				$output .= outputPosition(0,2) . WHITE . NEWBG . BLUE . 'Varient : ' . substr($varient['varient_name'] . str_repeat(' ',27),0,27)  ;
				$output .= outputPosition(0,3) . WHITE . NEWBG . BLUE . 'Dated   : ' . substr(date('j F Y',strtotime($varient['varient_date'])) . str_repeat(' ',27),0,27);

				$alts = $db->getAlternateFrames($service['service_id'],$varient['varient_id'],$curpage,$cursub);
				if (count($alts) > 1) {
					$n = 1;
					$output .= outputPosition(0,4) . WHITE . NEWBG . RED . 'ALTERNATIVE VERSIONS:' . str_repeat(' ',16);
					$y = 5;
					foreach ($alts as $ss){
//						if (is_numeric($ss['varient_date'])) {
							$date = date('d M Y', strtotime($ss['varient_date']));
//						} else {
//							$date = 'unknown';
//						}
						$line =  WHITE . NEWBG;
						if ($timewarp) {
						 	$line .= RED . $n;
						 }
						$line .= BLUE . $date . ' ' . $ss['varient_name'];


						$output .= outputPosition(0,$y) . $line . str_repeat(' ',40-strlenv($line));
						$y++;
						$n++;

					}
					if ($timewarp) {
						$mode = MODE_WARPTO;
					}
				}
				$client->send($output);

				break;

			default:
				;
		} // switch $action

		if ($resetpsn !== false && isset($fields[$resetpsn])) {
			$client->send(outputPosition($fields[$resetpsn]['x'],$fields[$resetpsn]['y']).CON);
			$resetpsn = false;
		}


		if( $read === null || socket_last_error()) {
			printf( "[%s] Disconnected\n", $client->getaddress() );
			return false;
		}
	}
	$client->close();
	printf( "[%s] Disconnected\n", $client->getaddress() );

}

function strlenv($text){
	return strlen($text) - substr_count($text, ESC);
}

function sendBaseline($client, &$blp, $text, $reposition = false){
	$client->send(HOME . UP . $text .
	 ( $blp > strlenv($text) ? str_repeat(' ',$blp-strlenv($text)) .
		 ( $reposition ? HOME . UP . str_repeat(RIGHT, strlenv($text)) : '' )
	  : '')
	);
	$blp = strlenv($text);
	return;
}

function outputPosition($x,$y){

	if ($y < 12) {
		if ($x < 21) {
			 return HOME . str_repeat(DOWN, $y) . str_repeat(RIGHT, $x);
		} else {
			return HOME . str_repeat(DOWN, $y+1) . str_repeat(LEFT, 40-$x);
		}
	} else {
		if ($x < 21) {
			return HOME . str_repeat(UP, 24-$y) . str_repeat(RIGHT, $x);
		} else {
			return HOME . str_repeat(UP, 24-$y) . str_repeat(LEFT, 40-$x);
		}

	}
}

/*

   return a screen output ... $msg sent on baseline just after the cls.
   remember to update $blp manually after calling this.

*/

function getOutput($page, $subpage, $pagedata, $pageflags, $msg = '', $user = array(), &$fields = null, &$frame_content = null) {
	global $blp;
	global $fieldmap;

	$price = isset($pagerecord['price']) ? $pagerecord['price'] : 0;

	// get textual content.

	$text = $pagedata['frame_content'];

	if ($pageflags['clear']) {
		$output = CLS;
	} else {
		$output = HOME;
	}
	if ($msg) {
		$output .= UP . $msg . HOME ;
	}

	$startline = 0;
	if ($pageflags['ip']) {
		// generate page header (but leave ISP as per frame)
		$header = chr(7) . $page . $subpage;
		$header .= str_repeat(' ', 12-strlenv($header));
		$header .= ($price < 5 ? chr(3) : chr(1)) . substr(" $price",-2) . 'p';
		$text = substr_replace($text,$header, 24, 16);
	} else {
		$startline = 1;
	}

	if ($startline) {
		$output .= str_repeat(DOWN, $startline);
	}
	$infield = false;
	$fieldtype = '';
	$fieldlength = '';;
	$fieldx = false;
	$fieldy = false;
	$fieldadrline = 1;

	for ($y=$startline; $y<23; $y++ ) {
		for ($x=0; $x<40; $x++) {
			$posn = $y*40+$x;
			$byte = ord($text{$posn})%128;

			// check for start-of-field
			if ( $byte == 27 ) {	// Esc designates start of field (Esc-K is end of edit)
				$infield = true;
				$fieldtype = ord(substr( $text, $posn + 1, 1))%128;
				$fieldlength = 0;
				$byte = 32;			// display a space there.
			} else {
				if ($infield) {
					if ($byte == $fieldtype) {
						$byte = 32;						// blank out fields
						if ($fieldx === false) {
							$fieldx = $x;
							$fieldy = $y;
						}
						$fieldlength++;
						// but is it a field that needs filling in?
						if (isset($fieldmap[chr($fieldtype)]) ) {
							$field = $fieldmap[chr($fieldtype)];
							// address field has many lines. increment when hit on first character.
							if ($fieldlength == 1 && strpos($field,'#') !== false) {
								$field = str_replace('#',$fieldadrline,$field);
								$fieldadrline++;
							}
							// user data

							if ($field == '%date') {
								$datetime = strtoupper(date('D d M Y H:i:s'));
								if ($fieldlength <= strlen($datetime)) {
									$byte = ord($datetime{$fieldlength-1});
								}
							} else if (isset($user[$field])) {
									if ($fieldlength <= strlen($user[$field])) {
										$byte = ord($user[$field]{$fieldlength-1});
									}
							} /*else 	// pre-load field contents. PAM or *00 ?
								if (isset($fields[what]['value'])) {

							} */

						}

					} else {
						$infield = false;
echo "Field at $fieldx, $fieldy type $fieldtype length $fieldlength \n";
						if (is_array($fields)) {
							$fields[] = array('type' => chr($fieldtype), 'length' => $fieldlength,
								'y' => $fieldy, 'x' => $fieldx);
						}
						$infield = false;
						$fieldx = false;
					}
				}
			}


			// truncate end of lines
			if ($pageflags['tru'] && substr($text,$y*40+$x,40-$x) === str_repeat(' ',40-$x)) {
				$output .= CR . LF;
				break;
			}
			if ($byte < 32) {
				$output .= ESC . chr($byte+64);
				//				echo '^'. chr($byte-64);
			} else {
				$output .= chr($byte);
//echo "($byte)".chr($byte);
			}
			$text{$posn} = $byte;
		}
//echo "\n";
	}

	// if we were asked to return the frame content, do so, but modified with any fields
	// that were filled in (or blanked)
	if (!is_null($frame_content)) {
		$frame_content = $text;
	}
	return $output;


}




require "sock/SocketServer.php";



$server = new \Sock\SocketServer($config['port'], "0.0.0.0");
$server->init();
$server->setConnectionHandler( 'onConnect' );
$server->listen();