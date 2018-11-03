# viewdatahost
A simple viewdata service host

This is a viewdata host program that attempts to closely emulate the operation of the original GPO/BT Prestel service.  
This is so that original period third-party programs and hardware can be used again.

Code is written in php, because it was easiest for me to get up and running quickly using libraries I
already had. No comments on this choice of language please.

You might find https://github.com/irrelevantdotcom/asterisk-Softmodem useful for creating a dial-up port for this.


Requires vv.class.php - viewdata viewer library. 
https://github.com/irrelevantdotcom/viewdataviewer

Requires vvdatabase.class.php - viewdata database library
https://github.com/irrelevantdotcom/vvdb

Requires you create a config/config.php file -

$config = array();

$config['database'] = 'vtext_pages';
$config['dbserver'] = 'localhost';
$config['dbuser'] = '*username*';
$config['dbpass'] = '*password*';

$config['service_id'] = 1;    // service and varient within database
$config['varient_id'] = 1;

$config['port'] = 6502;



Current status

Browsing works fine.
Frame types "t"erminate and "i"nformation(default) work as expected.


No Editor - you will need to add frames to the database using other methods
No Users - default user parameters are used pending login process being writen
Response Frames - basis is done, but do not work correctly yet.


Roadmap 

create (working) routines for accepting user input.
- used for everything from response frames to editor

implement modular extensions for other frame types and active pages
- allows for 
  
  implement login process module

  implement user management

  implement editor

  other functions, see e.g. featues of Autonomic Host (Gnome at Home) functions??


